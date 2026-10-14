# Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
# SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
"""Supervise the updater and the restricted Unix-socket proxy in one maintenance container."""
import os
import pathlib
import signal
import subprocess
import sys
import time
import json
import socket

STATE = pathlib.Path('/tmp/meridian-maintenance.json')
if sys.argv[1:] == ['--health']:
    try:
        for pid in json.loads(STATE.read_text()):
            if not isinstance(pid, int) or pid <= 1:
                raise ValueError('invalid service')
            os.kill(pid, 0)
        if os.environ.get('MERIDIAN_DOCKER_PROXY_ENABLE') == '1':
            with socket.socket(socket.AF_UNIX) as connection:
                connection.settimeout(2)
                connection.connect('/run/meridian-docker/docker.sock')
                connection.sendall(b'GET /_ping HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n')
                if b'200 OK' not in connection.recv(4096):
                    raise RuntimeError('proxy unavailable')
    except (OSError, ValueError, RuntimeError):
        sys.exit(1)
    sys.exit(0)

children = []
stopping = False

def stop(signum, frame):
    global stopping
    stopping = True

signal.signal(signal.SIGTERM, stop)
signal.signal(signal.SIGINT, stop)
try:
    if os.environ.get('MERIDIAN_COMPACT_STACK') == '1':
        # Remove only the three known legacy services from this exact Compose project.
        # This makes a NAS editor redeploy sufficient; job containers and data volumes are never removed.
        inspected = subprocess.run(['docker', 'inspect', '--format', '{{json .Config.Labels}}', 'meridian-web'], capture_output=True, check=True)
        project = json.loads(inspected.stdout).get('com.docker.compose.project')
        if not isinstance(project, str) or not project:
            raise RuntimeError('deployment identity missing')
        for name in ('scheduler', 'worker-http', 'docker-proxy'):
            found = subprocess.run(['docker', 'inspect', '--format', '{{json .Config.Labels}}', 'meridian-' + name], capture_output=True)
            if found.returncode:
                continue
            labels = json.loads(found.stdout)
            if labels.get('com.docker.compose.project') != project or labels.get('com.docker.compose.service') != name:
                raise RuntimeError('legacy service identity mismatch')
            subprocess.run(['docker', 'stop', '-t', '40', 'meridian-' + name], check=True, timeout=60)
            subprocess.run(['docker', 'rm', 'meridian-' + name], check=True, timeout=20)
    if os.environ.get('MERIDIAN_DOCKER_PROXY_ENABLE') == '1':
        pathlib.Path('/run/meridian-docker').mkdir(mode=0o755, parents=True, exist_ok=True)
        prefix = r'/v1\.[0-9]{2}/'
        children.append(subprocess.Popen([
            '/usr/local/bin/socket-proxy',
            '-proxysocketendpoint=/run/meridian-docker/docker.sock', '-proxysocketendpointfilemode=0666',
            '-loglevel=WARN', '-watchdoginterval=300', '-stoponwatchdog',
            r'-allowGET=((/v1\.[0-9]{2})?/_ping|' + prefix + r'(version|containers/[A-Za-z0-9][A-Za-z0-9_.-]*/json|exec/[0-9a-f]{64}/json))',
            r'-allowHEAD=((/v1\.[0-9]{2})?/_ping)',
            r'-allowPOST=(' + prefix + r'(containers/[A-Za-z0-9][A-Za-z0-9_.-]*/exec|exec/[0-9a-f]{64}/start))',
        ]))
    children.append(subprocess.Popen([sys.executable, '/usr/local/lib/meridian-updater.py']))
    STATE.write_text(json.dumps([child.pid for child in children]))
    while not stopping and all(child.poll() is None for child in children):
        time.sleep(0.2)
finally:
    for child in children:
        if child.poll() is None:
            child.terminate()
    for child in children:
        try:
            child.wait(timeout=10)
        except subprocess.TimeoutExpired:
            child.kill()
            child.wait()
    STATE.unlink(missing_ok=True)
sys.exit(0 if stopping else 1)
