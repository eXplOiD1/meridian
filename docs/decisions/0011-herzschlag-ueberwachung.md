# ADR 0011: Herzschlag-Überwachung (eingehende Pings)

Status: umgesetzt (Phase 7)

Für Dinge, die Meridian nicht selbst startet: ein Skript, eine Sicherung, ein Router ruft am Ende eine Adresse auf. Bleibt der Aufruf aus, meldet Meridian den Ausfall, danach die Rückkehr.

## Entscheidungen

1. **Eigene Tabelle `monitors`** (Migration 0020), kein Job-Typ: ein Job wird von Meridian gestartet, eine Überwachung wartet. Felder: Name, Kategorie, Intervall (60 s bis 31 Tage), Toleranz (0 bis 24 h), aktiv, Zustand `waiting`/`up`/`down`, letzter Ping, Meldungswahl.
2. **Ping `GET|POST /ping/{token}`** ohne Sitzung: Das Token ist die Berechtigung. 256 Bit Zufall (`mhb_…`, eigenes Präfix, nie ein API-Token), gespeichert nur als SHA-256-Hash, Klartext genau einmal bei Anlegen/Erneuern (`ping_token`, `no-store`), nie in Listen, Audit oder Übersicht. Kein Cookie, keine CSRF-Prüfung (nichts, was ein Browser automatisch mitschickt), kein Anfragekörper. Unbekanntes, erneuertes, pausiertes und falsch geformtes Token antworten gleich mit 404. Höchstens alle 5 s je Überwachung wird geschrieben (200 bleibt).
3. **Zustandswechsel im Zusteller** (`Notifier::evaluateMonitors`, `worker-http`, alle 60 s): Frist = (letzter Ping oder Beginn) + Intervall + Toleranz. Überschritten → `down`, einmal gemeldet (`monitor_down`). Ein Ping danach setzt `up` und merkt `recovery_pending` vor; der Zusteller meldet es einmal (`monitor_up`). Zustandswechsel und Ausgangseintrag stehen in einer Transaktion. Änderung von Intervall/Toleranz oder Wiederaktivieren beginnt die Frist neu (`waiting`).
4. **Meldungen** nutzen dieselben Kanäle und denselben Ausgang wie Jobs (`all` oder `none`; keine Einzelwahl), nur feste Texte mit Name und Zeit, nie ein Link (kein Job).
5. **Rechte:** Lesen `jobs.view`, Anlegen/Ändern/Löschen/Token erneuern `jobs.edit_http` in der Kategorie (beim Ändern alte und neue). Nicht sichtbar → 404, sichtbar ohne Recht → 403. Eine Kategorie mit Überwachungen oder Statusseiten lässt sich nicht löschen (409), wie bei Jobs.
6. **Übersicht:** Eine ausgefallene Überwachung steht in „Braucht Aufmerksamkeit“ (mit Link auf die Überwachung), gekappt auf die erlaubten Kategorien.
7. **Nicht umgesetzt (bewusst):** „Fehler-Ping“ (`/ping/{token}/fail`), Start-Ping mit Laufzeit, Auswahl einzelner Kanäle je Überwachung.
