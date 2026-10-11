# ADR 0016: Mehrsprachigkeit (Deutsch, Englisch)

Status: umgesetzt (Rahmen, Umschalter, Speicherung, alle Bildschirme der Oberfläche)

1. **Sprache je Benutzer:** Spalte `users.language` (`de`/`en`, Migration 0023), in `GET /api/auth/me` als `language`, änderbar mit `PUT /api/auth/language {language}` (nur die eigene, strikt `de`/`en`, CSRF, nicht bei offenem Pflichtwechsel). Vor der Anmeldung gilt die zuletzt gewählte Sprache (localStorage, nur Komfort) oder die des Browsers.
2. **Umschalter oben rechts** im Seitenkopf; Wechsel baut die Oberfläche sofort neu auf und speichert auf dem Server.
3. **Ohne Zusatzpaket:** `t('Deutscher Text', {platzhalter})` in `frontend/src/i18n`; Quelltext ist Deutsch, `en.ts` bildet deutsche Texte auf englische ab, fehlende Einträge bleiben deutsch (nie ein leerer Text). Datum und Zahlen folgen der Sprache (`de-DE`/`en-GB`).
4. **Grenze:** Meldungen des Servers (Validierungs- und Fehlertexte, Audit-Texte) sind deutsch und werden in der Oberfläche über dieselbe Tabelle übersetzt, soweit eingetragen. Ein serverseitiger Katalog ist nicht Teil dieser Stufe.
5. **Umfang der Übersetzung:** Alle festen Texte der Oberfläche laufen über `t()`; Tabellen mit Beschriftungen (Zustände, Rechte, Aktionen im Audit-Log, Voreinstellungen) übersetzen an der Anzeigestelle, nie beim Laden des Moduls (sonst bliebe die beim Start gültige Sprache stehen). Daten des Benutzers (Namen von Jobs, Kategorien, Rollen, Benutzern) werden nie übersetzt.
6. **Meldungen des Servers** (Fehler- und Feldmeldungen) sind deutsch und werden in `api.ts` über `t()` abgebildet; nicht eingetragene Meldungen bleiben deutsch (bewusste Grenze, ein serverseitiger Katalog folgt bei Bedarf).
