<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Status;

/**
 * Die öffentliche Statusseite als reines HTML ohne Skript (ADR 0012). Alles Dynamische wird maskiert und HTML-escaped; die
 * Seite zeigt nur Titel, Anzeigenamen, Zustand und Zeitpunkt, nie Namen, Adressen, Ausgaben oder Kategorien.
 * Das Aussehen kommt aus der eigenen Stylesheet-Datei (CSP erlaubt nur 'self', kein Inline-Style).
 */
final class StatusPageRenderer
{
    private const STATES = [
        'ok' => ['In Ordnung', 'ok'],
        'down' => ['Gestört', 'down'],
        'degraded' => ['Prüfung fehlgeschlagen', 'degraded'],
        'paused' => ['Pausiert', 'paused'],
        'unknown' => ['Noch keine Daten', 'unknown'],
    ];
    private const STATES_EN = [
        'ok' => ['Operational', 'ok'],
        'down' => ['Disrupted', 'down'],
        'degraded' => ['Check failed', 'degraded'],
        'paused' => ['Paused', 'paused'],
        'unknown' => ['No data yet', 'unknown'],
    ];

    /**
     * @param list<array{label: string, state: string, since: string|null, history?: list<array{day: string, ok: int, bad: int}>, events?: list<array{time: string, state: string}>, selected_day?: string|null}> $items
     * @param \Closure(string): string                                         $mask   SecretMasker::mask
     */
    public function render(string $title, array $items, \DateTimeZone $zone, \DateTimeImmutable $now, \Closure $mask, string $language = 'de'): string
    {
        $language = $language === 'en' ? 'en' : 'de';
        $english = $language === 'en';
        $states = $english ? self::STATES_EN : self::STATES;
        $down = 0;
        $unknown = 0;
        $degraded = 0;
        foreach ($items as $item) {
            $down += $item['state'] === 'down' ? 1 : 0;
            $unknown += $item['state'] === 'unknown' ? 1 : 0;
            $degraded += $item['state'] === 'degraded' ? 1 : 0;
        }
        [$bannerClass, $banner] = match (true) {
            $items === [] => ['unknown', $english ? 'Nothing to show yet.' : 'Diese Seite zeigt noch nichts an.'],
            $down > 0 => ['down', $english
                ? ($down === 1 ? 'There is one disruption.' : 'There are ' . $down . ' disruptions.')
                : ($down === 1 ? 'Es gibt eine Störung.' : 'Es gibt ' . $down . ' Störungen.')],
            $degraded > 0 => ['degraded', $english ? 'Some checks failed; the outage threshold has not yet been reached.' : 'Einige Prüfungen sind fehlgeschlagen; die Alarmschwelle ist noch nicht erreicht.'],
            $unknown === count($items) => ['unknown', $english ? 'No data yet.' : 'Noch keine Daten.'],
            default => ['ok', $english ? 'All systems operational.' : 'Alles läuft.'],
        };

        $rows = '';
        foreach ($items as $position => $item) {
            [$text, $class] = $states[$item['state']] ?? $states['unknown'];
            $history = '';
            foreach ($item['history'] ?? [] as $day) {
                $dayClass = $day['bad'] > 0 ? ($day['ok'] > 0 ? 'mixed' : 'down') : ($day['ok'] > 0 ? 'ok' : 'unknown');
                $caption = $day['day'] . ' (UTC) · ' . ($english ? 'successful: ' : 'erfolgreich: ') . $day['ok'] . ' · ' . ($english ? 'failed: ' : 'fehlgeschlagen: ') . $day['bad'];
                $history .= '<a class="history-day history-day--' . $dayClass . '" href="?lang=' . $language . '&amp;item=' . $position . '&amp;day=' . $day['day'] . '#events-' . $position . '" title="' . self::e($caption) . '" aria-label="' . self::e($caption) . '"></a>';
            }
            if ($history !== '') {
                $history = '<div class="history-bars">' . $history . '</div><p class="history-caption">' . ($english ? '90 days · UTC · green: successful, amber: mixed, red: failed, grey: no observations. Click a day for events.' : '90 Tage · UTC · Grün: erfolgreich, Orange: gemischt, Rot: fehlgeschlagen, Grau: keine Beobachtungen. Tag anklicken für Ereignisse.') . '</p>';
            }
            $details = '';
            foreach ($item['events'] ?? [] as $event) {
                [$eventText] = $states[$event['state']] ?? $states['unknown'];
                $details .= '<li>' . self::e(self::time($event['time'], $zone, $language)) . ' · ' . self::e($eventText) . '</li>';
            }
            if ($details !== '' || ($item['selected_day'] ?? null) !== null) {
                $date = $item['selected_day'] ?? '';
                $details = '<div class="public-events" id="events-' . $position . '"><h2>' . ($english ? 'Events for this day' : 'Ereignisse dieses Tages') . ($date === '' ? '' : ' · ' . self::e($date) . ' (UTC)') . '</h2><p>' . ($english ? 'Latest 100 recorded events. Missing periods have no retained observations.' : 'Die letzten 100 gespeicherten Ereignisse. Für fehlende Zeiträume liegen keine Beobachtungen vor.') . '</p>' . ($details === '' ? '<p>' . ($english ? 'No recorded events for this day.' : 'Für diesen Tag sind keine Ereignisse gespeichert.') . '</p>' : '<ol>' . $details . '</ol>') . '</div>';
            }
            $since = $item['since'] === null ? '' : '<span class="since">' . self::e(self::time($item['since'], $zone, $language)) . '</span>';
            $rows .= '<li class="item item--' . $class . '"><span class="dot" aria-hidden="true"></span><span class="label">' . self::e($mask($item['label']))
                . '</span><span class="state">' . $text . '</span>' . $since . $history . $details . "</li>\n";
        }

        $switch = '<nav class="languages" aria-label="' . ($english ? 'Language' : 'Sprache') . '">';
        foreach (['de' => 'Deutsch', 'en' => 'English'] as $code => $name) {
            $switch .= '<a href="?lang=' . $code . '" lang="' . $code . '" hreflang="' . $code . '" aria-label="' . $name . '"'
                . ($language === $code ? ' aria-current="page"' : '') . '>' . strtoupper($code) . '</a>';
        }
        $switch .= '</nav>';

        return "<!doctype html>\n<html lang=\"" . $language . "\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<meta name=\"robots\" content=\"noindex, nofollow, noarchive\">\n<meta http-equiv=\"refresh\" content=\"60\">\n"
            . '<title>' . self::e($mask($title)) . " – Status</title>\n<link rel=\"stylesheet\" href=\"/status/status.css?v="
            . substr(hash('sha256', self::stylesheet()), 0, 12) . "\">\n</head>\n<body>\n<main>\n"
            . '<header><h1>' . self::e($mask($title)) . '</h1>' . $switch . "</header>\n<p class=\"banner banner--" . $bannerClass . '" role="status">' . self::e($banner) . "</p>\n"
            . "<ul class=\"items\">\n" . $rows . "</ul>\n"
            . '<p class="stamp">' . ($english ? 'Updated: ' : 'Stand: ') . self::e(self::time($now->format('Y-m-d\TH:i:sP'), $zone, $language))
            . ($english ? ' · refreshes automatically' : ' · aktualisiert sich selbst') . "</p>\n</main>\n</body>\n</html>\n";
    }

    public static function stylesheet(): string
    {
        return <<<'CSS'
@font-face { font-family: 'Public Sans'; font-weight: 100 900; font-display: swap; src: url('/app/fonts/public-sans-variable.woff2') format('woff2'); }
:root {
  color-scheme: dark;
  --bg: #0c0c18; --panel: #1b1b2b; --text: #eeedf7; --dim: #b0aabe;
  --line: #2d2c40; --accent: #aa8eff; --end: #79d8e8; --glow: #202037;
  --ok: #78dcc3; --ok-bg: #1d3a33; --err: #ff9a92; --err-bg: #3a1f26;
  --warn: #f4c95d; --info-bg: #26254a; --shadow: 0 3px 14px rgb(0 0 0 / 28%);
}
* { box-sizing: border-box; }
body {
  margin: 0; min-height: 100vh;
  background: radial-gradient(ellipse at 80% 0%, var(--glow) 0%, transparent 50%), var(--bg);
  background-attachment: fixed; color: var(--text);
  font-family: 'Public Sans', system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-size: 15px; line-height: 1.5; -webkit-font-smoothing: antialiased;
}
main { max-width: 960px; margin: 0 auto; padding: 56px 24px 48px; }
header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
h1 { font-size: clamp(1.7rem, 4vw, 2.2rem); font-weight: 600; margin: 0; letter-spacing: -.01em; overflow-wrap: anywhere; min-width: 0; }
.languages { display: inline-flex; flex: none; background: var(--info-bg); border-radius: 999px; overflow: hidden; }
.languages a { min-width: 44px; min-height: 44px; display: grid; place-items: center; color: var(--dim); font-size: 12px; font-weight: 700; text-decoration: none; }
.languages a[aria-current='page'] { background: linear-gradient(120deg, var(--accent), var(--end)); color: var(--bg); }
.languages a:focus-visible { outline: 2px solid var(--accent); outline-offset: -3px; }
.banner {
  display: flex; align-items: center; gap: 12px; margin: 0 0 24px; padding: 18px 22px;
  border: 1px solid currentColor; border-radius: 14px; font-weight: 600;
}
.banner::before { content: ''; width: 10px; height: 10px; flex: none; border-radius: 50%; background: currentColor; }
.banner--ok { color: var(--ok); background: var(--ok-bg); }
.banner--down { color: var(--err); background: var(--err-bg); }
.banner--unknown { color: var(--dim); background: var(--info-bg); }
.items { list-style: none; margin: 0; padding: 8px 24px; background: var(--panel); border: 1px solid var(--line); border-radius: 22px; box-shadow: var(--shadow); }
.item { display: grid; grid-template-columns: 12px minmax(0, 1fr) auto; gap: 6px 16px; align-items: center; padding: 22px 0; border-bottom: 1px solid var(--line); }
.item:last-child { border-bottom: 0; }
.dot { width: 10px; height: 10px; border-radius: 50%; background: var(--dim); }
.item--ok .dot { background: var(--ok); }
.item--down .dot { background: var(--err); }
.item--paused .dot { background: var(--warn); }
.item--degraded .dot { background: #f59e0b; }
.item--degraded .state, .banner--degraded { color: var(--warn); }
.label { min-width: 0; font-weight: 600; overflow-wrap: anywhere; }
.state { font-size: .85rem; font-weight: 600; padding: 5px 12px; border-radius: 999px; background: var(--info-bg); color: var(--dim); }
.item--ok .state { color: var(--ok); background: var(--ok-bg); }
.item--down .state { color: var(--err); background: var(--err-bg); }
.item--paused .state { color: var(--warn); }
.since { grid-column: 2 / 4; font-size: .8rem; color: var(--dim); overflow-wrap: anywhere; }
.stamp { margin: 24px 0 0; font-size: .8rem; color: var(--dim); overflow-wrap: anywhere; }
.history-bars { grid-column: 1 / -1; display: flex; gap: 3px; height: 30px; margin-top: 12px; }
.history-day { flex: 1; min-width: 0; background: var(--dim); opacity: .35; }
.history-day--ok { background: var(--ok); opacity: 1; }
.history-day--down { background: var(--err); opacity: 1; }
.history-day--mixed { background: #f59e0b; opacity: 1; }
.history-day:hover, .history-day:focus-visible { outline: 2px solid var(--text); outline-offset: 1px; }
.history-caption, .public-events { grid-column: 1 / -1; font-size: .8rem; color: var(--dim); }
.public-events { padding: 16px; border: 1px solid var(--line); border-radius: 12px; max-height: 360px; overflow: auto; }
@media (max-width: 540px) { .history-bars { gap: 1px; } }
@media (max-width: 540px) {
  main { padding: 32px 16px; }
  .items { padding: 4px 18px; }
  .item { grid-template-columns: 10px minmax(0, 1fr); gap: 10px 12px; padding: 18px 0; }
  .state { grid-column: 2; justify-self: start; }
  .since { grid-column: 2; }
  .banner { padding: 16px 18px; }
}
@media (prefers-color-scheme: light) {
  :root {
    color-scheme: light;
    --bg: #f5f3fb; --panel: #ffffff; --text: #252038; --dim: #6e6680;
    --line: #e8e4f1; --accent: #6b45d6; --end: #0d8798; --glow: #eee8fc;
    --ok: #237d6b; --ok-bg: #e3f4ee; --err: #b3261e; --err-bg: #fbeceb;
    --warn: #7a5200; --info-bg: #ece8fa; --shadow: 0 3px 14px rgb(60 40 120 / 9%);
  }
}
CSS;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function time(string $iso, \DateTimeZone $zone, string $language): string
    {
        try {
            return (new \DateTimeImmutable($iso))->setTimezone($zone)->format($language === 'en' ? 'Y-m-d H:i' : 'd.m.Y H:i') . ' (' . $zone->getName() . ')';
        } catch (\Exception) {
            return '';
        }
    }
}
