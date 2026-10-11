# ADR 0012: Öffentliche Statusseiten

Status: umgesetzt (Phase 7)

Eine Seite zum Weitergeben, auch an Menschen ohne Konto: sie zeigt, ob ausgewählte Jobs und Überwachungen laufen.

## Entscheidungen

1. **Zugang über einen geheimen Link** `/status/{slug}`: 24 Zeichen aus 120 Bit Zufall. Wer den Link kennt, sieht die Seite; es gibt kein Verzeichnis aller Seiten. Unbekannt, ausgeschaltet und falsch geformt antworten gleich mit 404. „Neuer Link“ macht den alten sofort ungültig. Der Slug steht nur in der Verwaltung (für Bearbeiter), nie im Audit-Log.
2. **Die Seite zeigt nur das, was der Bearbeiter ausdrücklich festlegt:** Titel, je Eintrag einen selbst gewählten **Anzeigenamen**, den Zustand und einen Zeitpunkt. Nie den Namen des Jobs, Adressen, Header, Ausgaben, Notizen, Kategorien oder Fehlermeldungen. Alles wird maskiert und HTML-escaped.
3. **Zustände:** Job — pausiert (deaktiviert), „noch keine Daten“ (kein beendeter Lauf), „in Ordnung“ (letzter beendeter Lauf erfolgreich), sonst „gestört“; Testläufe zählen nicht. Überwachung — in Ordnung (`up`), gestört (`down`), pausiert, sonst „noch keine Daten“. Kopfzeile: „Es gibt n Störung(en)“, „Alles läuft“, „Noch keine Daten“. (Ein Lauf, dem noch ein Wiederholungsversuch folgt, zeigt kurz „gestört“.)
4. **Rechte:** `status_pages.edit` (kategoriebezogen, in der Standardrolle Operator). Eine Seite gehört einer Kategorie; wer keine Kategorie hat (ohne Kategorie), braucht das Recht uneingeschränkt. Jeder Eintrag muss ein Job oder eine Überwachung sein, die der Bearbeiter selbst sehen darf (`jobs.view`): wer etwas nicht sehen darf, kann es nicht veröffentlichen. Bis 50 Einträge je Seite, 50 Seiten.
5. **Auslieferung ohne Skript:** serverseitig erzeugtes HTML mit eigener Stylesheet-Datei `/status/status.css` (CSP bleibt `style-src 'self'`, kein Inline), selbst gehostete Schriften, `X-Robots-Tag: noindex, nofollow, noarchive`, `Cache-Control: public, max-age=30`, Selbstaktualisierung per `meta refresh` alle 60 s.
6. **Löschen:** Wird ein Job oder eine Überwachung gelöscht, verschwindet ihr Eintrag mit (CASCADE); eine Kategorie mit Statusseiten lässt sich nicht löschen.
7. **Bewusst nicht enthalten:** Verfügbarkeitsbalken über Tage, eigene Domains, Abonnements, Texte zu Vorfällen.
