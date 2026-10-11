<?php

declare(strict_types=1);

namespace Meridian\Tests\Support;

use Meridian\Runner\Http\HostResolver;

/**
 * DNS für Tests: je Host eine Folge von Antworten; die letzte wiederholt sich. Zählt die Abfragen.
 */
final class FakeHostResolver implements HostResolver
{
    /** @var array<string, list<list<string>>> */
    public array $answers = [];

    /** @var array<string, int> */
    public array $lookups = [];

    /**
     * @param list<string> ...$answers
     */
    public function set(string $host, array ...$answers): void
    {
        $this->answers[$host] = array_values($answers);
    }

    public function resolve(string $host): array
    {
        $this->lookups[$host] = ($this->lookups[$host] ?? 0) + 1;
        $queue = $this->answers[$host] ?? [];
        if ($queue === []) {
            return [];
        }
        $answer = $queue[0];
        if (count($queue) > 1) {
            array_shift($queue);
            $this->answers[$host] = $queue;
        }

        return $answer;
    }
}
