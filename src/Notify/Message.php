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
        public string $language = 'de',
    ) {
    }

    public function subject(): string
    {
        if ($this->language === 'en') {
            return match ($this->kind) {
                'failure' => 'Meridian: ' . $this->jobName . ' failed',
                'recovery' => 'Meridian: ' . $this->jobName . ' recovered',
                'monitor_down' => 'Meridian: No heartbeat from ' . $this->jobName,
                'monitor_up' => 'Meridian: ' . $this->jobName . ' is responding again',
                'disabled' => 'Meridian: ' . $this->jobName . ' was disabled',
                'tls_expiry' => 'Meridian: Certificate for ' . $this->jobName . ' expires soon',
                default => 'Meridian: Test notification',
            };
        }

        return match ($this->kind) {
            'failure' => 'Meridian: ' . $this->jobName . ' ist fehlgeschlagen',
            'recovery' => 'Meridian: ' . $this->jobName . ' läuft wieder',
            'monitor_down' => 'Meridian: Kein Lebenszeichen von ' . $this->jobName,
            'monitor_up' => 'Meridian: ' . $this->jobName . ' meldet sich wieder',
            'disabled' => 'Meridian: ' . $this->jobName . ' wurde deaktiviert',
            'tls_expiry' => 'Meridian: Zertifikat von ' . $this->jobName . ' läuft bald ab',
            default => 'Meridian: Testnachricht',
        };
    }

    private function englishText(): string
    {
        if ($this->kind === 'test') {
            return "This is a test notification from Meridian.\nIf you can read it, this channel is working.\n";
        }
        $name = '“' . $this->jobName . '”';
        $lines = [match ($this->kind) {
            'monitor_down' => 'The monitor ' . $name . ' has stopped receiving heartbeats.',
            'monitor_up' => 'The monitor ' . $name . ' is receiving heartbeats again.',
            'disabled' => 'The job ' . $name . ' was automatically disabled after ' . $this->failures . ' consecutive failures. Enable it in Meridian to resume runs.',
            'tls_expiry' => 'The TLS certificate for ' . $name . ' expires soon.',
            'failure' => 'The job ' . $name . ($this->runStatus === 'timeout' ? ' exceeded its time limit' : ' failed') . ($this->failures > 1 ? ' (' . $this->failures . ' consecutive failures)' : '') . '.',
            default => 'The job ' . $name . ' has recovered and is running successfully again.',
        }];
        $lines[] = 'Time: ' . $this->occurredAt . ' (UTC)';
        if ($this->detail !== null && $this->detail !== '') {
            $lines[] = 'Note: ' . MessageDetail::english($this->detail);
        }
        $lines[] = '';
        $lines[] = $this->link === null ? (str_starts_with($this->kind, 'monitor_') ? 'See “Monitoring” in Meridian for details.' : 'See “History” in Meridian for details.') : 'Open: ' . $this->link;

        return implode("\n", $lines) . "\n";
    }

    public function text(): string
    {
        if ($this->language === 'en') {
            return $this->englishText();
        }
        if ($this->kind === 'test') {
            return "Das ist eine Testnachricht von Meridian.\nWenn du sie liest, funktioniert dieser Kanal.\n";
        }
        $lines = [];
        if ($this->kind === 'monitor_down') {
            $lines[] = 'Die Überwachung „' . $this->jobName . '“ hat kein Lebenszeichen mehr erhalten.';
        } elseif ($this->kind === 'monitor_up') {
            $lines[] = 'Die Überwachung „' . $this->jobName . '“ hat wieder ein Lebenszeichen erhalten.';
        } elseif ($this->kind === 'disabled') {
            $lines[] = 'Der Job „' . $this->jobName . '“ wurde automatisch deaktiviert, nachdem er ' . $this->failures . ' Mal in Folge fehlgeschlagen ist. Er läuft erst wieder, wenn du ihn in Meridian aktivierst.';
        } elseif ($this->kind === 'tls_expiry') {
            $lines[] = 'Das TLS-Zertifikat des Ziels von „' . $this->jobName . '“ läuft bald ab.';
        } elseif ($this->kind === 'failure') {
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
        $lines[] = $this->link === null ? (str_starts_with($this->kind, 'monitor_') ? 'Details stehen in Meridian unter „Überwachung“.' : 'Details stehen in Meridian unter „Verlauf“.') : 'Öffnen: ' . $this->link;

        return implode("\n", $lines) . "\n";
    }
}
