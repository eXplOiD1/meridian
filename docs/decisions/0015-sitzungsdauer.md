# ADR 0015: Einstellbare Sitzungsdauer

Status: umgesetzt

1. **Einstellung `auth.session_days`** (Migration 0022; `settings.manage`, Audit wie jede Einstellung): `-1` = Standard (2 Std. Leerlauf, höchstens 12 Std.), `0` = niemals ablaufen, `1` bis `365` = so viele Tage. „Zurücksetzen“ stellt den Standard her.
2. **Wirkung:** `SessionManager` liest die Dauer bei jeder Prüfung (Leerlauf) und beim Anlegen einer Sitzung (absolute Dauer, `expires_at`). Eine **Verkürzung** wirkt sofort auf den Leerlauf, eine Verlängerung nur für neue Anmeldungen. Bei N Tagen gilt N für Leerlauf und Gesamtdauer; „niemals“ heißt serverseitig 10 Jahre.
3. **Cookie:** Lebensdauer wie die Sitzung, höchstens 400 Tage (Grenze der Browser). Das Cookie bleibt `HttpOnly`, `SameSite=Strict`, bei HTTPS `Secure` mit `__Host-`-Präfix.
4. **Sicherheit:** Eine lange Sitzung lockert den Schutz gegen gestohlene Cookies; deshalb nur mit `settings.manage` (gefährliches Recht, nur uneingeschränkt), mit Warnhinweis in der Oberfläche. Passwortwechsel, Deaktivieren und „Sitzungen beenden“ gelten unverändert sofort.
