#!/usr/bin/env python3
# Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
# SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
"""Privileged deployment helper. Only fixed check/install requests, no client commands."""
import datetime
import fcntl
import json
import os
import pathlib
import re
import shutil
import stat
import subprocess
import tempfile
import time
import threading
import urllib.request

REPO = "https://api.github.com/repos/eXplOiD1/meridian/commits/main"
SHA = re.compile(r"^[0-9a-f]{40}$")
SERVICES = ["web", "scheduler", "worker-http", "worker-shell", "sandbox"]
UNITS = ["meridian-web", "meridian-scheduler", "meridian-worker-http", "meridian-worker-shell"]
WORKERS = UNITS[1:]


def command(args, timeout=600, env=None):
    # No shell, no untrusted arguments and no command output in the public status.
    result = subprocess.run(args, capture_output=True, timeout=timeout, env=env)
    if result.returncode:
        print("Meridian updater: command failed:", args[0], args[1] if len(args) > 1 else "", flush=True)
        raise RuntimeError("deployment command failed")
    return result.stdout.decode("utf-8", errors="strict").strip()


def latest_revision():
    request = urllib.request.Request(REPO, headers={"User-Agent": "Meridian-Updater", "Accept": "application/vnd.github+json"})
    with urllib.request.urlopen(request, timeout=20) as response:
        if response.geturl() != REPO:
            raise RuntimeError("unexpected redirect")
        data = json.loads(response.read(65536))
    revision = data.get("sha", "")
    if not isinstance(revision, str) or not SHA.fullmatch(revision):
        raise RuntimeError("invalid revision")
    return revision


class Updater:
    def __init__(self):
        self.mode = os.environ.get("MERIDIAN_UPDATE_MODE", "systemd")
        if self.mode not in ("docker", "systemd"):
            raise RuntimeError("invalid mode")
        self.data = pathlib.Path(os.environ.get("MERIDIAN_UPDATE_DATA", "/var/lib/meridian"))
        self.private = pathlib.Path(os.environ.get("MERIDIAN_UPDATE_PRIVATE", "/var/lib/meridian-updater"))
        self.private.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(self.private, 0o700)
        directory = self.data / "updates"
        directory.mkdir(mode=0o770, exist_ok=True)
        uid = int(os.environ.get("MERIDIAN_UPDATE_UID", "1000"))
        gid = int(os.environ.get("MERIDIAN_UPDATE_GID", str(uid)))
        # Keep the descriptor: the application cannot redirect privileged writes with a symlink.
        self.fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        os.fchown(self.fd, uid, gid)
        os.fchmod(self.fd, 0o770)
        self.uid, self.gid = uid, gid
        self.lock = os.open(self.private / "daemon.lock", os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        fcntl.flock(self.lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.compose_file = os.environ.get("MERIDIAN_UPDATE_COMPOSE", "")
        self.compose_location = None
        self.state = {"mode": self.mode, "current": self.current(), "latest": "", "available": False, "busy": False, "phase": "idle", "message": "Bereit."}
        self.last_check = 0
        self.publish_lock = threading.Lock()
        try:
            descriptor = os.open('status.json', os.O_RDONLY | os.O_NOFOLLOW, dir_fd=self.fd)
            with os.fdopen(descriptor, 'r') as stream:
                info = os.fstat(stream.fileno())
                previous = json.loads(stream.read(8192)) if stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_size <= 8192 else {}
            if isinstance(previous, dict) and (previous.get('recovery_required') is True or (previous.get('busy') is True and previous.get('phase') in ('backup', 'installing', 'verifying', 'rollback'))):
                self.state.update(recovery_required=True, phase='failed', message='Vorgang fehlgeschlagen. Update-Dienst und Sicherungen prüfen; vor einem neuen Versuch erneut nach Updates suchen.')
                self.last_check = time.time()
        except (OSError, ValueError):
            pass

    def compose(self, *args, override=None):
        if self.compose_file:
            # Explicit configuration remains available for existing custom installations.
            base = ["docker", "compose", "--project-name", "meridian", "--project-directory", str(pathlib.Path(self.compose_file).parent), "-f", self.compose_file]
        else:
            project, directory, files, image = self.location()
            directories = sorted({directory, *(str(pathlib.PurePosixPath(file).parent) for file in files)})
            base = ["docker", "run", "--rm", "--network", "none", "--read-only", "--cap-drop", "ALL", "--cap-add", "DAC_OVERRIDE", "--security-opt", "no-new-privileges:true", "--tmpfs", "/tmp", "--volumes-from", "meridian-updater"]
            for mount in directories:
                base += ["--mount", "type=bind,src=" + mount + ",dst=" + mount + ",readonly"]
            base += ["--entrypoint", "docker", image, "compose", "--project-name", project, "--project-directory", directory]
            for file in files:
                base += ["-f", file]
        if override:
            base += ["-f", str(override)]
        try:
            return command(base + list(args), timeout=1800)
        except RuntimeError:
            # Portainer records paths inside its own data volume; resolve those host paths once.
            if not self.compose_file and args and args[0] == 'config' and self.portainer_location():
                return self.compose(*args, override=override)
            raise

    def portainer_location(self):
        if getattr(self, 'portainer_resolved', False):
            return False
        project, directory, files, image = self.location()
        if not directory.startswith('/data/compose/'):
            return False
        containers = command(['docker', 'ps', '--format', '{{json .}}'], 20)
        for line in containers.splitlines():
            row = json.loads(line)
            if not str(row.get('Image', '')).startswith(('portainer/portainer-ce:', 'portainer/portainer-ee:')):
                continue
            container = row.get('ID', '')
            if not re.fullmatch(r'[0-9a-f]{12,64}', container):
                continue
            mounts = json.loads(command(['docker', 'inspect', '--format', '{{json .Mounts}}', container], 20))
            for mount in mounts:
                if mount.get('Destination') != '/data' or not str(mount.get('Source', '')).startswith('/'):
                    continue
                source = mount['Source'].rstrip('/')
                if ',' in source or '..' in pathlib.PurePosixPath(source).parts or any(ord(char) < 32 for char in source) or not all(file.startswith('/data/') for file in files):
                    continue
                self.compose_location = (project, source + directory[5:], [source + file[5:] for file in files], image)
                self.portainer_resolved = True
                return True
        return False

    def location(self):
        if self.compose_location is not None:
            return self.compose_location
        labels = json.loads(command(["docker", "inspect", "--format", "{{json .Config.Labels}}", "meridian-web"], 20))
        project = labels.get('com.docker.compose.project', '')
        directory = labels.get('com.docker.compose.project.working_dir', '')
        files = labels.get('com.docker.compose.project.config_files', '').split(',')
        # Compose persists the temporary image override in its labels. Ignore only
        # overrides generated in our private directory when discovering a new stack.
        generated = re.compile(re.escape(str(self.private).rstrip('/')) + r'/tmp[A-Za-z0-9_-]+/images\.json')
        files = [file for file in files if not generated.fullmatch(file)]
        if not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,62}', project) or not files:
            raise RuntimeError('compose metadata missing')
        for value in [directory, *files]:
            path = pathlib.PurePosixPath(value)
            if not value.startswith('/') or str(path) == '/' or '..' in path.parts or ',' in value or any(ord(char) < 32 for char in value) or path.parts[1] in ('proc', 'sys', 'dev'):
                raise RuntimeError('invalid compose metadata')
        image = command(["docker", "inspect", "--format", "{{.Image}}", "meridian-updater"], 20)
        if not re.fullmatch(r'sha256:[0-9a-f]{64}', image):
            raise RuntimeError('invalid updater image')
        self.compose_location = (project, directory, files, image)
        return self.compose_location

    def current(self):
        if self.mode == "docker":
            return command(["docker", "inspect", "--format", '{{index .Config.Labels "org.opencontainers.image.revision"}}', "meridian-web"], 20)
        file = pathlib.Path("/opt/meridian/REVISION")
        revision = file.read_text().strip() if file.exists() else ""
        return revision if SHA.fullmatch(revision) else "unknown"

    def publish(self, phase=None, message=None, busy=None):
        if phase is not None:
            self.state["phase"] = phase
        if message is not None:
            self.state["message"] = message
        if busy is not None:
            self.state["busy"] = busy
        self.state["heartbeat"] = time.time()
        name = "status-" + os.urandom(12).hex() + ".tmp"
        descriptor = os.open(name, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o640, dir_fd=self.fd)
        with os.fdopen(descriptor, "w") as stream:
            json.dump(dict(self.state), stream)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), 0, self.gid)
        with self.publish_lock:
            os.replace(name, "status.json", src_dir_fd=self.fd, dst_dir_fd=self.fd)

    def request(self):
        try:
            descriptor = os.open("request.json", os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=self.fd)
        except FileNotFoundError:
            return None
        try:
            info = os.fstat(descriptor)
            if not stat.S_ISREG(info.st_mode) or info.st_size > 1024 or info.st_uid != self.uid:
                raise ValueError("invalid request file")
            with os.fdopen(descriptor, "r") as stream:
                descriptor = -1
                data = json.load(stream)
            if set(data) != {"action", "id"} or data["action"] not in ("check", "install") or not isinstance(data["id"], str) or not re.fullmatch(r"[0-9a-f]{32}", data["id"]):
                raise ValueError("invalid request")
            return data["action"]
        except (ValueError, TypeError):
            return None
        finally:
            if descriptor != -1:
                os.close(descriptor)
            os.unlink("request.json", dir_fd=self.fd)

    def check(self):
        self.publish("checking", "Updates werden geprüft.", True)
        revision = latest_revision()
        self.state.update(current=self.current(), latest=revision, checked_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
        self.state["available"] = revision != self.state["current"]
        if self.mode == "docker" and self.state["available"]:
            for image in ("meridian", "meridian-sandbox"):
                try:
                    command(["docker", "manifest", "inspect", "ghcr.io/exploid1/" + image + ":sha-" + revision], 30)
                except Exception:
                    self.state["available"] = False
                    self.last_check = time.time() - 21300
                    self.publish("building", "Neue Version gefunden; Docker-Images sind noch nicht verfügbar.", False)
                    return
        self.last_check = time.time()
        self.publish("ready", "Update verfügbar." if self.state["available"] else "Meridian ist aktuell.", False)

    def override(self, directory, images):
        path = directory / "images.json"
        # A JSON object is also a valid Compose YAML document.
        path.write_text(json.dumps({"services": {name: {"image": image, "pull_policy": "never"} for name, image in images.items()}}))
        return path

    def snapshot(self):
        directory = self.private / ("backup-" + datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%d-%H%M%S") + "-" + os.urandom(4).hex())
        directory.mkdir(mode=0o700)
        return directory

    def docker_install(self, revision):
        if self.compose_file and not pathlib.Path(self.compose_file).is_file():
            raise RuntimeError("compose missing")
        project = 'meridian' if self.compose_file else self.location()[0]
        # Check deployment identity before touching any container.
        for service in SERVICES:
            actual = command(["docker", "inspect", "--format", '{{index .Config.Labels "com.docker.compose.project"}}', "meridian-" + service])
            if actual != project:
                raise RuntimeError("unexpected stack")
        images = {service: "ghcr.io/exploid1/" + ("meridian-sandbox" if service == "sandbox" else "meridian") + ":sha-" + revision for service in SERVICES}
        self.publish("downloading", "Update wird heruntergeladen.")
        for image in sorted(set(images.values())):
            command(["docker", "pull", image], 1800)
        # Pin downloaded image IDs, preventing a tag change between pull and deployment.
        images = {service: command(["docker", "image", "inspect", "--format", "{{.Id}}", image]) for service, image in images.items()}
        old = {service: command(["docker", "inspect", "--format", "{{.Image}}", "meridian-" + service]) for service in SERVICES}
        with tempfile.TemporaryDirectory(dir=self.private) as temp:
            directory = pathlib.Path(temp)
            new_override = self.override(directory, images)
            # Validate Compose interpolation, paths and new image overrides before stopping jobs.
            self.compose("config", "--quiet", override=new_override)
            snapshot = self.snapshot()
            stopped = False
            changed = False
            try:
                self.publish("backup", "Jobs werden beendet und eine Sicherung wird erstellt.")
                stopped = True
                self.compose("stop", "-t", "120", "scheduler", "worker-http", "worker-shell")
                self.compose("exec", "-T", "web", "php", "bin/meridian", "backup:create", "--keep=365")
                name = self.compose("exec", "-T", "web", "php", "-r", '$files=glob("/var/lib/meridian/backups/meridian-*.sqlite"); rsort($files); echo basename($files[0]);')
                if not re.fullmatch(r"meridian-\d{8}-\d{6}\.sqlite", name):
                    raise RuntimeError("backup missing")
                command(["docker", "cp", "meridian-web:/var/lib/meridian/backups/" + name, str(snapshot / "meridian.sqlite")])
                command(["docker", "cp", "meridian-web:/etc/meridian/master.key", str(snapshot / "master.key")])
                (snapshot / "images.json").write_text(json.dumps(old))
                changed = True
                self.state['recovery_required'] = True
                self.compose("stop", "-t", "30", "web", "sandbox")
                self.publish("installing", "Neue Version wird gestartet.")
                self.compose("up", "-d", "--no-build", "--no-deps", "web", override=new_override)
                self.docker_health([])
                self.compose("up", "-d", "--no-build", "--no-deps", *SERVICES[1:], override=new_override)
                self.docker_health()
                self.state['recovery_required'] = False
            except Exception:
                if changed:
                    self.publish("rollback", "Update fehlgeschlagen. Vorherige Version wird wiederhergestellt.")
                    self.compose("stop", "-t", "30", *SERVICES)
                    helper = command(["docker", "create", "--network", "none", "--user", "0:0", "--cap-drop", "ALL", "--cap-add", "CHOWN", "--cap-add", "DAC_OVERRIDE", "--cap-add", "FOWNER", "--security-opt", "no-new-privileges:true", "--volumes-from", "meridian-web", "--entrypoint", "sh", old["web"], "-c", 'set -eu; rm -f /var/lib/meridian/meridian.sqlite-wal /var/lib/meridian/meridian.sqlite-shm; cp /tmp/restore.sqlite /var/lib/meridian/meridian.sqlite; cp /tmp/restore.key /etc/meridian/master.key; chown 1000:1000 /var/lib/meridian/meridian.sqlite /etc/meridian/master.key; chmod 600 /var/lib/meridian/meridian.sqlite /etc/meridian/master.key'])
                    if not re.fullmatch(r"[0-9a-f]{64}", helper):
                        raise RuntimeError("invalid helper")
                    try:
                        command(["docker", "cp", str(snapshot / "meridian.sqlite"), helper + ":/tmp/restore.sqlite"])
                        command(["docker", "cp", str(snapshot / "master.key"), helper + ":/tmp/restore.key"])
                        command(["docker", "start", "--attach", helper])
                        if command(["docker", "inspect", "--format", "{{.State.ExitCode}}", helper]) != "0":
                            raise RuntimeError("restore failed")
                    finally:
                        command(["docker", "rm", "-f", helper])
                    rollback = self.override(directory, old)
                    self.compose("up", "-d", "--no-build", "--no-deps", "web", override=rollback)
                    self.docker_health([])
                    self.compose("up", "-d", "--no-build", "--no-deps", *SERVICES[1:], override=rollback)
                    self.docker_health()
                    self.state['recovery_required'] = False
                elif stopped:
                    self.compose("start", "scheduler", "worker-http", "worker-shell")
                raise

    def docker_health(self, services=None):
        if services is None:
            services = SERVICES[1:]
        self.publish("verifying", "Dienste werden geprüft.")
        for _ in range(60):
            web = command(["docker", "inspect", "--format", "{{.State.Health.Status}}", "meridian-web"], 20)
            running = all(command(["docker", "inspect", "--format", "{{.State.Running}}", "meridian-" + service], 20) == "true" for service in services)
            if web == "healthy" and running:
                return
            time.sleep(2)
        raise RuntimeError("health check failed")

    def systemd_install(self, revision):
        self.publish("downloading", "Update wird heruntergeladen.")
        with tempfile.TemporaryDirectory(dir=self.private) as temp:
            source = pathlib.Path(temp) / "source"
            command(["git", "clone", "--no-checkout", "https://github.com/eXplOiD1/meridian.git", str(source)], 300)
            command(["git", "-C", str(source), "checkout", "--detach", revision], 60)
            command(["npm", "--prefix", str(source / "frontend"), "ci", "--no-audit", "--no-fund"], 600)
            command(["npm", "--prefix", str(source / "frontend"), "run", "build"], 600)
            (source / "REVISION").write_text(revision + "\n")
            snapshot = self.snapshot()
            enabled = [unit for unit in UNITS if subprocess.run(["systemctl", "is-active", "--quiet", unit], capture_output=True).returncode == 0]
            changed = False
            try:
                self.publish("backup", "Jobs werden beendet und eine Sicherung wird erstellt.")
                for unit in WORKERS:
                    command(["systemctl", "stop", unit], 180)
                command(["sudo", "-u", "meridian", "env", "MERIDIAN_DATA_DIR=/var/lib/meridian", "MERIDIAN_KEY_FILE=/etc/meridian/master.key", "php", "/opt/meridian/bin/meridian", "backup:create", "--keep=365"], 120)
                backup = sorted((self.data / "backups").glob("meridian-????????-??????.sqlite"))[-1]
                shutil.copy2(backup, snapshot / "meridian.sqlite")
                owner = os.stat(self.data / "meridian.sqlite")
                shutil.copy2("/etc/meridian/master.key", snapshot / "master.key")
                command(["systemctl", "stop", "meridian-web"], 60)
                shutil.copytree("/opt/meridian", snapshot / "app", symlinks=True)
                changed = True
                self.state['recovery_required'] = True
                self.publish("installing", "Neue Version wird gestartet.")
                command(["bash", str(source / "deploy/install.sh")], 1800)
                self.systemd_health(enabled)
                self.state['recovery_required'] = False
            except Exception:
                if changed:
                    self.publish("rollback", "Update fehlgeschlagen. Vorherige Version wird wiederhergestellt.")
                    command(["systemctl", "stop", *UNITS], 180)
                    command(["rsync", "-a", "--delete", str(snapshot / "app") + "/", "/opt/meridian/"])
                    for file in (snapshot / "app/deploy/systemd").glob("*.service"):
                        shutil.copy2(file, pathlib.Path("/etc/systemd/system") / file.name)
                    for file in (snapshot / "app/deploy/systemd").glob("*.socket"):
                        shutil.copy2(file, pathlib.Path("/etc/systemd/system") / file.name)
                    command(["systemctl", "daemon-reload"])
                    for suffix in ("-wal", "-shm"):
                        (self.data / ("meridian.sqlite" + suffix)).unlink(missing_ok=True)
                    shutil.copy2(snapshot / "meridian.sqlite", self.data / "meridian.sqlite")
                    os.chown(self.data / "meridian.sqlite", owner.st_uid, owner.st_gid)
                    shutil.copy2(snapshot / "master.key", "/etc/meridian/master.key")
                    command(["systemctl", "start", *enabled], 180)
                    self.systemd_health(enabled)
                    self.state['recovery_required'] = False
                else:
                    command(["systemctl", "start", *enabled], 180)
                raise

    def systemd_health(self, units):
        self.publish("verifying", "Dienste werden geprüft.")
        for _ in range(60):
            active = subprocess.run(["systemctl", "is-active", "--quiet", *units], capture_output=True).returncode == 0
            try:
                with urllib.request.urlopen("http://127.0.0.1:8080/api/health", timeout=5) as response:
                    if active and response.status == 200:
                        return
            except OSError:
                pass
            time.sleep(2)
        raise RuntimeError("health check failed")

    def install(self):
        # Resolve main again. Clients cannot choose arbitrary commits or repositories.
        revision = latest_revision()
        if revision != self.state["latest"]:
            self.check()
            return
        self.publish("preparing", "Update wird vorbereitet.", True)
        if self.mode == "docker":
            self.docker_install(revision)
        else:
            self.systemd_install(revision)
        self.state.update(current=self.current(), available=False)
        self.publish("success", "Update erfolgreich installiert.", False)

    def run(self):
        self.publish()
        def heartbeat():
            while True:
                time.sleep(20)
                self.publish()
        threading.Thread(target=heartbeat, daemon=True).start()
        while True:
            try:
                if self.state.get('recovery_required') is True:
                    self.publish()
                    time.sleep(5)
                    continue
                action = self.request()
                if action == "install" and self.state["available"]:
                    self.install()
                elif action == "check" or time.time() - self.last_check > 21600:
                    self.check()
                else:
                    self.publish()
            except Exception as error:
                print("Meridian updater: operation failed during", self.state.get("phase"), type(error).__name__, flush=True)
                self.last_check = time.time()
                self.publish("failed", "Vorgang fehlgeschlagen. Update-Dienst und Sicherungen prüfen; vor einem neuen Versuch erneut nach Updates suchen.", False)
            time.sleep(5)


if __name__ == "__main__":
    Updater().run()
