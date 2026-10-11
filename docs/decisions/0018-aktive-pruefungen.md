# ADR 0018: Aktive Prüfungen (Web-Adresse, TCP-Port, Ping)

Status: umgesetzt

Die Überwachung (ADR 0011) wartete nur auf Pings. Jetzt prüft Meridian auch selbst: eine feste Web-Adresse, einen Netzwerk-Port oder einen Host per Ping. Entscheidung von Alex: alle drei Arten, Ausfall erst nach n Fehlversuchen in Folge.

## Entscheidungen

1. **Gleiche Tabelle `monitors`**, neue Spalten (Migration 0025): `kind` (`heartbeat`/`http`/`tcp`/`ping`), `target_enc`, `display_target`, `expected_status`, `fail_after`, Prüfergebnis (`last_check_*`, `consecutive_failures`) und `down_pending`. Aktive Prüfungen bekommen ein nie ausgegebenes Zufalls-Token (`token_hash` bleibt eindeutig); der Ping-Endpunkt kennt nur `kind = 'heartbeat'`, Token erneuern ist für aktive Prüfungen 404. Die Art lässt sich nicht ändern.
2. **Ziel ist ein Geheimnis:** nur verschlüsselt (`target_enc`, SecretBox), nie in Antworten, Listen, Audit oder Logs. Anzeige = Ursprung (`https://host`) bzw. `host:port`; bei `http.display_host = hidden` ein fester Platzhalter. Beim Ändern bleibt das Ziel, wenn `target` fehlt; ein neues ersetzt es und startet die Prüfung neu. Das Audit nennt nur den Feldnamen.
3. **Syntax wie bei HTTP-Jobs:** `MonitorTarget` nutzt `UrlPolicy` (Host-Regeln, gesperrte Namen, keine Zugangsdaten). TCP verlangt `host:port`, Ping nur den Host; kein Schema/Pfad/Query.
4. **SSRF:** Jede Prüfung läuft durch den `TargetGuard` (auflösen, jede Adresse prüfen, festhalten); verbunden wird nur zu einer geprüften Adresse. Freigaben interner Ziele gelten je Kategorie der Überwachung (Port bei TCP, bei Ping/HTTP der Port des Ziels bzw. 80); Metadaten, Link-local und Meridians Infrastruktur nie.
5. **Web:** GET ohne Header und Body, 10 s, TLS immer geprüft, **keine Weiterleitungen** (3xx zählt nach den erwarteten Codes, Standard `200-399`). **TCP:** Verbindung zur festgehaltenen Adresse. **Ping:** Programm `ping` mit Argument-Array und fester Umgebung, nur mit einer bereits geprüften IP; fehlt es oder darf es nicht senden, steht ein fester Hinweis. Im Compose-Betrieb braucht `worker-http` `net.ipv4.ping_group_range` (ohne Capability), im Image liegt `iputils`.
6. **Ausführung** im `worker-http`-Wartungstakt (alle 60 s, `MonitorChecker`), höchstens 20 s Prüfzeit je Takt, älteste zuerst; wer den bisherigen `last_check_at` beansprucht, prüft (mehrere Worker nie doppelt). Prüfintervall 1 Minute bis 31 Tage.
7. **Ausfall erst nach `fail_after` Fehlschlägen in Folge** (1 bis 10, Standard 3); ein Erfolg setzt zurück. Der Zustand wechselt in einer Transaktion mit dem Vormerken (`down_pending`/`recovery_pending`); der Zusteller meldet einmal je Ausfall und einmal je Rückkehr (dieselben Kanäle und Texte wie beim Herzschlag, Detail = feste Notiz).
8. **Notizen sind feste Texte** (Statuscode, Zeitüberschreitung, TLS, Verbindung, gesperrtes Ziel); nie Pfad, Query, Antwort oder curl-Meldung.
9. **Rechte unverändert:** Lesen `jobs.view`, Ändern `jobs.edit_http` in der Kategorie; nicht sichtbar → 404, sichtbar ohne Recht → 403. Statusseiten und „Braucht Aufmerksamkeit“ zeigen aktive Prüfungen wie Herzschlag-Überwachungen.
10. **Bekannte Grenzen:** Der Text der Meldung heißt weiter „Kein Lebenszeichen von …“. Prüfungen laufen seriell im Wartungstakt; sehr viele langsame Ziele werden über mehrere Takte verteilt.
