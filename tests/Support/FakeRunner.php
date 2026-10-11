<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Runner\Heartbeat;
use Meridian\Runner\Runner;
use Meridian\Runner\RunRequest;
use Meridian\Runner\RunResult;
use Meridian\Security\SecretMasker;

/**
 * Runner für Tests: liefert vorgegebene Ergebnisse der Reihe nach (das letzte bleibt stehen) und merkt sich
 * die Aufträge.
 */
final class FakeRunner implements Runner
{
    /** @var list<RunRequest> */
    public array $requests = [];

    /** @var (\Closure(RunRequest, Heartbeat): void)|null Wird während jedes Laufs aufgerufen (langer Lauf, Herzschlag). */
    public ?\Closure $during = null;

    /** @var list<SecretMasker> Der Masker jedes Laufs (H4: je Lauf ein eigener). */
    public array $maskers = [];

    /** @var list<string> Werden in jedem Lauf im Masker registriert, wie es ein echter Runner mit der Payload tut. */
    public array $secrets = [];

    /** @var list<RunResult|\Throwable> */
    private array $results;

    public function __construct(RunResult|\Throwable ...$results)
    {
        $this->results = array_values($results);
    }

    #[\Override]
    public function run(RunRequest $request, Heartbeat $heartbeat, SecretMasker $masker, \Meridian\Runner\LiveLog $live): RunResult
    {
        $this->requests[] = $request;
        $this->maskers[] = $masker;
        foreach ($this->secrets as $secret) {
            $masker->remember($secret);
        }
        if ($this->during !== null) {
            ($this->during)($request, $heartbeat);
        }
        $result = count($this->results) > 1 ? array_shift($this->results) : ($this->results[0] ?? RunResult::ok());
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }
}
