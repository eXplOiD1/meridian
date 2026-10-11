<?php

declare(strict_types=1);

namespace Meridian\Notify;

/**
 * Inhalt einer Benachrichtigung (ADR 0007 N6): nur Jobname, Status, Zeit, Hinweis — nie Ausgabe, Skript, URL oder Header.
 * Jobname und Hinweis sind schon maskiert, wenn sie hier ankommen.
 */
final readonly class Message
{
    public function __construct(
        public string $kind,
        public string $jobName,
        public ?string $runStatus,
        public string $occurredAt,
        public ?string $detail,
        public int $failures,
        public ?string $link = null,
    ) {
    }

    public function subject(): string
    {
        return match ($this->kind) {
            'failure' => 'Meridian: ' . $this->jobName . ' ist fehlgeschlagen',
            'recovery' => 'Meridian: ' . $this->jobName . ' läuft wieder',
            default => 'Meridian: Testnachricht',
        };
    }

    public function text(): string
    {
        if ($this->kind === 'test') {
            return "Das ist eine Testnachricht von Meridian.\nWenn du sie liest, funktioniert dieser Kanal.\n";
        }
        $lines = [];
        if ($this->kind === 'failure') {
            $label = $this->runStatus === 'timeout' ? 'wegen Zeitlimit abgebrochen' : 'fehlgeschlagen';
            $lines[] = 'Der Job „' . $this->jobName . '“ ist ' . $label . ($this->failures > 1 ? ' (' . $this->failures . ' Fehlschläge in Folge)' : '') . '.';
        } else {
            $lines[] = 'Der Job „' . $this->jobName . '“ war fehlgeschlagen und läuft jetzt wieder erfolgreich.';
        }
        $lines[] = 'Zeit: ' . $this->occurredAt . ' (UTC)';
        if ($this->detail !== null && $this->detail !== '') {
            $lines[] = 'Hinweis: ' . $this->detail;
        }
        $lines[] = '';
        $lines[] = $this->link === null ? 'Details stehen in Meridian unter „Verlauf“.' : 'Öffnen: ' . $this->link;

        return implode("\n", $lines) . "\n";
    }
}
