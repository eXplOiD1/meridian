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
        'paused' => ['Pausiert', 'paused'],
        'unknown' => ['Noch keine Daten', 'unknown'],
    ];

    /**
     * @param list<array{label: string, state: string, since: string|null}> $items
     * @param \Closure(string): string                                         $mask   SecretMasker::mask
     */
    public function render(string $title, array $items, \DateTimeZone $zone, \DateTimeImmutable $now, \Closure $mask): string
    {
        $down = 0;
        $unknown = 0;
        foreach ($items as $item) {
            $down += $item['state'] === 'down' ? 1 : 0;
            $unknown += $item['state'] === 'unknown' ? 1 : 0;
        }
        [$bannerClass, $banner] = match (true) {
            $items === [] => ['unknown', 'Diese Seite zeigt noch nichts an.'],
            $down > 0 => ['down', $down === 1 ? 'Es gibt eine Störung.' : 'Es gibt ' . $down . ' Störungen.'],
            $unknown === count($items) => ['unknown', 'Noch keine Daten.'],
            default => ['ok', 'Alles läuft.'],
        };

        $rows = '';
        foreach ($items as $item) {
            [$text, $class] = self::STATES[$item['state']] ?? self::STATES['unknown'];
            $since = $item['since'] === null ? '' : '<span class="since">' . self::e(self::time($item['since'], $zone)) . '</span>';
            $rows .= '<li class="item item--' . $class . '"><span class="dot" aria-hidden="true"></span><span class="label">' . self::e($mask($item['label']))
                . '</span><span class="state">' . $text . '</span>' . $since . "</li>\n";
        }

        return "<!doctype html>\n<html lang=\"de\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<meta name=\"robots\" content=\"noindex, nofollow, noarchive\">\n<meta http-equiv=\"refresh\" content=\"60\">\n"
            . '<title>' . self::e($mask($title)) . " – Status</title>\n<link rel=\"stylesheet\" href=\"/status/status.css\">\n</head>\n<body>\n<main>\n"
            . '<h1>' . self::e($mask($title)) . "</h1>\n<p class=\"banner banner--" . $bannerClass . '" role="status">' . self::e($banner) . "</p>\n"
            . "<ul class=\"items\">\n" . $rows . "</ul>\n"
            . '<p class="stamp">Stand: ' . self::e(self::time($now->format('Y-m-d\TH:i:sP'), $zone)) . " · aktualisiert sich selbst</p>\n</main>\n</body>\n</html>\n";
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
h1 { font-size: clamp(1.7rem, 4vw, 2.2rem); font-weight: 600; margin: 0 0 24px; letter-spacing: -.01em; overflow-wrap: anywhere; }
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
.label { min-width: 0; font-weight: 600; overflow-wrap: anywhere; }
.state { font-size: .85rem; font-weight: 600; padding: 5px 12px; border-radius: 999px; background: var(--info-bg); color: var(--dim); }
.item--ok .state { color: var(--ok); background: var(--ok-bg); }
.item--down .state { color: var(--err); background: var(--err-bg); }
.item--paused .state { color: var(--warn); }
.since { grid-column: 2 / 4; font-size: .8rem; color: var(--dim); overflow-wrap: anywhere; }
.stamp { margin: 24px 0 0; font-size: .8rem; color: var(--dim); overflow-wrap: anywhere; }
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

    private static function time(string $iso, \DateTimeZone $zone): string
    {
        try {
            return (new \DateTimeImmutable($iso))->setTimezone($zone)->format('d.m.Y H:i') . ' (' . $zone->getName() . ')';
        } catch (\Exception) {
            return '';
        }
    }
}
