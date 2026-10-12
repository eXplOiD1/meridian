<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell\Agent;

use Meridian\Runner\Shell\ExecFailed;
use Meridian\Runner\Shell\ExecSpec;
use Meridian\Runner\Shell\ShellInterpreter;
use Meridian\Runner\Shell\ShellRules;
use Meridian\Runner\Shell\ShellTarget;
use Meridian\Runner\Shell\ShellTargetKind;

/**
 * Der Auftrag des Workers (Rahmen `S`), vom Agenten **erneut** vollständig geprüft: Interpreter aus dem Enum, Umgebung nach
 * E12 (Namen, Werte, Anzahl, Gesamtgröße), Nonce, Größen. Nichts wird zurechtgeschnitten; jede Abweichung ist ein
 * Protokollfehler mit festem Text. Skript und Werte erscheinen nie in einer Meldung.
 */
final class AgentRequest
{
    private const KEYS = ['v', 'run_id', 'job_id', 'trigger', 'interpreter', 'env', 'nonce', 'script_bytes', 'timezone'];

    /**
     * @param list<array{0: string, 1: string}> $env
     */
    private function __construct(
        public readonly int $runId,
        public readonly int $jobId,
        public readonly string $trigger,
        public readonly ShellInterpreter $interpreter,
        public readonly array $env,
        public readonly string $nonce,
        public readonly int $scriptBytes,
        public readonly string $timezone,
    ) {
    }

    /**
     * @throws ProtocolError
     */
    public static function parse(#[\SensitiveParameter] string $json): self
    {
        $bad = static fn (): ProtocolError => ProtocolError::because('Ungültiger Auftrag.');
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $bad();
        }
        if (!is_array($data) || array_is_list($data) || array_diff(array_map(strval(...), array_keys($data)), self::KEYS) !== [] || count($data) !== count(self::KEYS)) {
            throw $bad();
        }
        /** @var mixed $v */
        $v = $data['v'];
        /** @var mixed $runId */
        $runId = $data['run_id'];
        /** @var mixed $jobId */
        $jobId = $data['job_id'];
        /** @var mixed $trigger */
        $trigger = $data['trigger'];
        /** @var mixed $interpreterName */
        $interpreterName = $data['interpreter'];
        /** @var mixed $rawEnv */
        $rawEnv = $data['env'];
        /** @var mixed $nonce */
        $nonce = $data['nonce'];
        /** @var mixed $scriptBytes */
        $scriptBytes = $data['script_bytes'];
        /** @var mixed $timezone */
        $timezone = $data['timezone'];

        $interpreter = is_string($interpreterName) ? ShellInterpreter::tryFrom($interpreterName) : null;
        if ($v !== 1 || !is_int($runId) || $runId < 1 || !is_int($jobId) || $jobId < 1 || !is_string($trigger) || preg_match('/^[a-z]{1,16}$/D', $trigger) !== 1
            || $interpreter === null || !is_string($nonce) || preg_match('/^[0-9a-f]{32}$/D', $nonce) !== 1
            || !is_int($scriptBytes) || $scriptBytes < 1 || $scriptBytes > ShellRules::MAX_SCRIPT_BYTES
            || !is_string($timezone) || strlen($timezone) > 64 || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            || !is_array($rawEnv) || !array_is_list($rawEnv) || count($rawEnv) > ShellRules::MAX_ENV_VARIABLES) {
            throw $bad();
        }

        $env = [];
        $total = 0;
        $names = [];
        /** @var mixed $pair */
        foreach ($rawEnv as $pair) {
            if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2) {
                throw $bad();
            }
            [$name, $value] = $pair;
            if (!is_string($name) || !is_string($value) || ShellRules::envNameProblem($name) !== null || ShellRules::envValueProblem($value) !== null || isset($names[$name])) {
                throw $bad();
            }
            $total += strlen($name) + strlen($value);
            if ($total > ShellRules::MAX_ENV_TOTAL_BYTES) {
                throw $bad();
            }
            $names[$name] = true;
            $env[] = [$name, $value];
        }

        return new self($runId, $jobId, $trigger, $interpreter, $env, $nonce, $scriptBytes, $timezone);
    }

    /**
     * @throws ProtocolError
     */
    public function toSpec(#[\SensitiveParameter] string $script): ExecSpec
    {
        if (strlen($script) !== $this->scriptBytes || ShellRules::scriptProblem($script) !== null) {
            throw ProtocolError::because('Ungültiges Skript.');
        }
        try {
            return new ExecSpec(
                $this->runId,
                $this->jobId,
                $this->trigger,
                new ShellTarget(ShellTargetKind::Host, 'agent'),
                $this->interpreter,
                null,
                null,
                $this->timezone,
                $this->nonce,
                $script,
                $this->env,
            );
        } catch (ExecFailed) {
            throw ProtocolError::because('Ungültiger Auftrag.');
        }
    }
}
