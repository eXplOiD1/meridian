# ADR 0016: Mehrsprachigkeit (Deutsch, Englisch)

Status: Grundgerüst umgesetzt (Rahmen, Umschalter, Speicherung); die Übersetzung der Bildschirme folgt mit dem Redesign

1. **Sprache je Benutzer:** Spalte `users.language` (`de`/`en`, Migration 0023), in `GET /api/auth/me` als `language`, änderbar mit `PUT /api/auth/language {language}` (nur die eigene, strikt `de`/`en`, CSRF, nicht bei offenem Pflichtwechsel). Vor der Anmeldung gilt die zuletzt gewählte Sprache (localStorage, nur Komfort) oder die des Browsers.
2. **Umschalter oben rechts** im Seitenkopf; Wechsel baut die Oberfläche sofort neu auf und speichert auf dem Server.
3. **Ohne Zusatzpaket:** `t('Deutscher Text', {platzhalter})` in `frontend/src/i18n`; Quelltext ist Deutsch, `en.ts` bildet deutsche Texte auf englische ab, fehlende Einträge bleiben deutsch (nie ein leerer Text). Datum und Zahlen folgen der Sprache (`de-DE`/`en-GB`).
4. **Grenze:** Meldungen des Servers (Validierungs- und Fehlertexte, Audit-Texte) sind deutsch und werden in der Oberfläche über dieselbe Tabelle übersetzt, soweit eingetragen. Ein serverseitiger Katalog ist nicht Teil dieser Stufe.
