# ADR 0009: Zugriffstoken für die REST-API

Status: umgesetzt (Phase 8)

## Entscheidungen

1. **Bearer nur, wenn der Header da ist.** `Authorization: Bearer …` ersetzt die Sitzung; ein fehlerhafter Header gilt als nicht angemeldet, nie als Rückfall auf ein Cookie.
2. **Bereiche per Allowlist:** `/api/jobs`, `/api/runs`, `/api/overview`, `/api/stats`. Nie Konto, Passwort, Einstellungen, Benutzer, Rollen und nie `/api/jobs/{id}/source`. Andere Bereiche antworten 403.
3. **Rechte des Benutzers, nie mehr.** Das Token läuft durch dieselben `AccessControl`- und Kategorie-Prüfungen wie die Sitzung.
4. **Standard schreibgeschützt.** Schreibende Anfragen brauchen ein Token mit `read_only = 0`.
5. **Anlegen nur mit Passwort** (einmalige Legitimierung), Klartext (256 Bit, Präfix `mrd_`) genau einmal in der Antwort mit `no-store`, gespeichert nur als Hash (`ApiToken`).
6. **Ablauf:** Standard 90 Tage, 1–365 Tage oder ausdrücklich nie; abgelaufene und widerrufene Token sind sofort wirkungslos.
7. **CSRF entfällt** bei Bearer (kein Cookie, keine Browser-Automatik); die Sitzungs-Pfade bleiben geschützt.
8. **Audit** mit Namen und Umfang, nie mit dem Token.
