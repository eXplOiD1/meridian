<?php

declare(strict_types=1);

namespace Meridian\Category;

/**
 * Eine Kategorie-Aktion wurde abgelehnt. Die Meldungen sind fest oder enthalten nur Zahlen, nie Eingabewerte;
 * {@see $status} ist der HTTP-Status, den die Schnittstelle daraus macht.
 */
final class CategoryException extends \RuntimeException
{
    public const NOT_FOUND = 404;
    public const CONFLICT = 409;
    public const INVALID = 422;
    public const TOO_MANY = 429;

    private function __construct(string $message, public readonly int $status, public readonly ?int $jobs = null)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Kategorie nicht gefunden.', self::NOT_FOUND);
    }

    public static function nameTaken(): self
    {
        return new self('Eine Kategorie mit diesem Namen gibt es schon (Groß- und Kleinschreibung zählt nicht). Anderen Namen wählen.', self::INVALID);
    }

    public static function invalidName(): self
    {
        return new self('Ungültiger Name. Erlaubt sind 1 bis 64 Zeichen: Buchstaben (A–Z), Ziffern, Leerzeichen, _ . und -. Der Name beginnt mit einem Buchstaben oder einer Ziffer und endet nicht mit einem Leerzeichen.', self::INVALID);
    }

    public static function hasJobs(int $jobs): self
    {
        return new self(
            'Kategorie enthält ' . $jobs . ($jobs === 1 ? ' Job' : ' Jobs') . '. Jobs zuerst in eine andere Kategorie verschieben oder löschen.',
            self::CONFLICT,
            $jobs,
        );
    }

    public static function tooMany(): self
    {
        return new self('Zu viele Änderungen an Kategorien in der letzten Stunde (höchstens ' . CategoryService::HOURLY_LIMIT . '). Später erneut versuchen.', self::TOO_MANY);
    }
}
