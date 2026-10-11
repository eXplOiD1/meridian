# ADR 0017: Farbschema und Redesign „Material Aurora“

Status: umgesetzt (Oberfläche); Backend nur um die Benutzereinstellung erweitert

1. **Zwei gleichwertige Schemata:** dunkel (Standard) und hell. Alle Farben, Radien, Schatten und Schriften stehen als Tokens in `frontend/src/styles.css` (`:root` und `:root[data-theme='light']`); Komponenten benutzen nur Tokens. Verläufe nur für Kopfbereich (Hero), Hauptaktionen und Akzente, nie für Fließtext oder Tabellen.
2. **Farbschema je Benutzer:** Spalte `users.theme` (`dark`/`light`, Migration 0024), in `GET /api/auth/me` als `theme`, änderbar mit `PUT /api/auth/theme {theme}` (nur das eigene, strikt `dark`/`light`, CSRF, nicht bei offenem Pflichtwechsel). Vor der Anmeldung gilt die letzte Wahl dieses Browsers (localStorage, nur `dark`/`light`, kein Geheimnis). `data-theme` wird beim Start gesetzt, bevor gezeichnet wird.
3. **Status nie nur durch Farbe:** Hinweise (`alert`), Zustände und Fehler tragen Text oder Symbol (✓, !, ✕, i), Fehlerfelder zusätzlich die Meldung.
4. **Rahmen:** feste Seitenleiste ab 768 px, nur der Arbeitsbereich scrollt (`min-height: 0` in Raster und Spalte). Menü in vier Gruppen (Betrieb, Beobachten, Verwaltung, Konto); Bereiche ohne Recht bleiben sichtbar, aber nicht anwählbar (wie bisher). Unter 768 px: Kopfleiste mit Logo und Menüknopf, Seitenleiste als Schublade mit Fokusfalle, Escape, Scroll-Sperre, `aria-expanded`/`aria-controls`, Fokus zurück auf den Knopf.
5. **Mobil:** Tabellen werden zu beschrifteten Karten (`data-label`), kein Querscrollen der Seite, Bedienelemente ≥ 44 px, Eingaben ≥ 16 px (kein Zoom in iOS).
6. **Job-Editor Einfach/Experte:** Einfach zeigt nur „Allgemein“, Experte alle Reiter. Beide Ansichten teilen denselben Formularzustand; verborgene Reiter bleiben eingehängt, nichts geht beim Umschalten verloren. Ein Fehler in einem verborgenen Reiter schaltet auf Experte und springt zum Feld. Die Wahl liegt in `localStorage` (kein Geheimnis); beim Bearbeiten eines Jobs ist Experte vorgewählt.
7. **Einstellungen:** je Einstellung ein Satz sichtbar, der Rest unter „Mehr erfahren“ (`details`), Text vollständig erhalten.
8. **Unverändert:** Backend-Verträge, Rechte, Maskierung, Geheimnisbehandlung, CSP (kein Inline-JS/-Style), Schriften selbst gehostet. Keine neuen Abhängigkeiten. Die Übersicht („Lage“, Ring „Nächster Lauf“) zeigt nur Werte aus `/api/jobs` und `/api/overview`.
9. **Bewegung:** `prefers-reduced-motion` schaltet Animationen und Übergänge ab.
