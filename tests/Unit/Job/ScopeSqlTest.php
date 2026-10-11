<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Job;

use Meridian\Job\CategoryRepository;
use Meridian\Job\JobRepository;
use Meridian\Job\RunRepository;
use Meridian\Job\ScopeSql;
use PHPUnit\Framework\TestCase;

/**
 * Das Sichtbarkeits-Prädikat steht in jeder Abfrage der Repositories als derselbe Text (E6).
 */
final class ScopeSqlTest extends TestCase
{
    /** Nur für Job-IDs, die der Aufrufer schon über das Prädikat gewonnen hat (Zustand der sichtbaren Jobs). */
    private const WITHOUT_SCOPE = [JobRepository::class . '::RUN_STATE'];

    public function testEveryRepositoryQueryUsesTheCanonicalPredicate(): void
    {
        $checked = 0;
        foreach ([JobRepository::class, RunRepository::class, CategoryRepository::class] as $class) {
            foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
                $value = $constant->getValue();
                if (!is_string($value) || stripos($value, 'SELECT') !== 0 || in_array($class . '::' . $constant->getName(), self::WITHOUT_SCOPE, true)) {
                    continue;
                }
                self::assertSame(1, substr_count($value, ScopeSql::PREDICATE), $class . '::' . $constant->getName() . ' braucht das Prädikat genau einmal');
                self::assertSame(1, substr_count($value, ':scope_all'), 'keine abweichende Variante daneben');
                self::assertStringNotContainsString('payload_enc', $value, 'die Web-API lädt das Payload nie');
                self::assertStringNotContainsStringIgnoringCase('worker', $value);
                self::assertStringNotContainsStringIgnoringCase('heartbeat', $value);
                ++$checked;
            }
        }
        self::assertSame(3, $checked, 'drei Abfragen: Jobs, Läufe, Kategorien');
    }
}
