<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/** Only instantiated by the explicitly enabled, privileged host bridge, never by a worker. */
final readonly class DockerHostExecutor implements Executor
{
    public function __construct(private int $uid, private int $gid, private string $workRoot, bool $allowRoot = false)
    {
        if ($uid < 0 || $gid < 0 || $uid > 2147483647 || $gid > 2147483647 || ($uid === 0 && !$allowRoot)
            || preg_match('#^/proc/1/root/tmp/meridian-host-[0-9a-f]{32}$#D', $workRoot) !== 1) {
            throw new \InvalidArgumentException('Host bridge requires a fixed UID/GID and private work root; root requires explicit approval.');
        }
    }

    #[\Override]
    public function start(#[\SensitiveParameter] ExecSpec $spec): Execution
    {
        // Sharing the host PID namespace is essential: PID and PGID must mean the same thing for cancellation.
        if (getenv('MERIDIAN_DOCKER_HOST_ENABLE') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0
            || !is_file('/.dockerenv') || is_file('/proc/1/root/.dockerenv')
            || readlink('/proc/self/ns/pid') !== readlink('/proc/1/ns/pid')
            || !is_executable('/usr/bin/nsenter') || !is_executable('/usr/bin/setsid')
            || !is_dir($this->workRoot) || is_link($this->workRoot)) {
            throw new ExecFailed(ExecFailed::HOST_NOT_SET_UP, false);
        }
        $workdir = $this->workRoot . '/run-' . $spec->runId . '-' . $spec->nonce;
        if (!@mkdir($workdir, 0700)) {
            // Never delete an existing path when exclusive mkdir failed.
            throw new ExecFailed(ExecFailed::START_FAILED, false);
        }
        if (!@chown($workdir, $this->uid) || !@chgrp($workdir, $this->gid)) {
            LocalExecution::removeTree($workdir);
            throw new ExecFailed(ExecFailed::START_FAILED, false);
        }
        $hostHome = substr($workdir, strlen('/proc/1/root'));
        $interpreter = $spec->interpreter === ShellInterpreter::Bash ? '/bin/bash' : '/bin/sh';
        return LocalProcessExecutor::spawn([
            '/usr/bin/setsid', '/usr/bin/nsenter', '--target=1', '--mount', '--net', '--uts', '--ipc',
            '--root', '--wd=' . $workdir, '--setgid=' . $this->gid, '--setuid=' . $this->uid,
            '--no-fork', '--', $interpreter, '-s',
        ], $workdir, LocalProcessExecutor::environment($spec, $hostHome), $spec->script());
    }
}
