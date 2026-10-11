<?php

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
@font-face { font-family: 'Barlow Condensed'; font-weight: 600; font-display: swap; src: url('/app/fonts/barlow-condensed-600.woff2') format('woff2'); }
@font-face { font-family: 'Public Sans'; font-weight: 100 900; font-display: swap; src: url('/app/fonts/public-sans-variable.woff2') format('woff2'); }
:root { --emaille: #f2f4f1; --tafel: #0b2545; --ok: #1f7a52; --fehler: #b8321e; --bernstein: #8a6a00; --schiefer: #566476; --karte: #ffffff; --linie: #d9dde3; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--emaille); color: var(--tafel); font-family: 'Public Sans', system-ui, sans-serif; line-height: 1.5; }
main { max-width: 640px; margin: 0 auto; padding: 32px 16px 48px; }
h1 { font-family: 'Barlow Condensed', 'Arial Narrow', sans-serif; font-weight: 600; font-size: 2rem; margin: 0 0 16px; letter-spacing: .01em; }
.banner { margin: 0 0 20px; padding: 14px 16px; border-radius: 10px; font-weight: 600; color: #fff; }
.banner--ok { background: var(--ok); }
.banner--down { background: var(--fehler); }
.banner--unknown { background: var(--schiefer); }
.items { list-style: none; margin: 0; padding: 0; background: var(--karte); border: 1px solid var(--linie); border-radius: 10px; overflow: hidden; }
.item { display: grid; grid-template-columns: 14px 1fr auto; gap: 4px 12px; align-items: center; padding: 14px 16px; border-bottom: 1px solid var(--linie); }
.item:last-child { border-bottom: 0; }
.dot { width: 12px; height: 12px; border-radius: 50%; background: var(--schiefer); }
.item--ok .dot { background: var(--ok); }
.item--down .dot { background: var(--fehler); }
.item--paused .dot { background: var(--bernstein); }
.label { font-weight: 600; }
.state { font-size: .9rem; font-weight: 600; }
.item--ok .state { color: var(--ok); }
.item--down .state { color: var(--fehler); }
.item--paused .state { color: var(--bernstein); }
.item--unknown .state { color: var(--schiefer); }
.since { grid-column: 2 / 4; font-size: .8rem; color: var(--schiefer); }
.stamp { margin: 20px 0 0; font-size: .8rem; color: var(--schiefer); }
@media (prefers-color-scheme: dark) {
  :root { --emaille: #071a33; --tafel: #dce3ec; --karte: #0b2545; --linie: #1e3a63; --schiefer: #9fb0c6; }
  .item--ok .state { color: #7fd6a8; } .item--down .state { color: #ff8a75; }
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
