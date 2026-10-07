# 0003 – Phase 3: HTTP-Jobs inklusive Job-Bildschirmen

Status: **Entwurf**, wartet auf die offenen Entscheidungen in Abschnitt 8 (Alex). Stand 07.10.2026.
Verfasser: architekt. Gilt für die Umsetzung von Phase 3 in `TODO.md` (Schritte S1–S13, U1–U4, R).

Dieser Entwurf ändert keinen Produktcode. Interfaces und die Migration stehen hier als Skizze; die
umsetzenden Agenten übernehmen sie und passen die Skills **im selben Arbeitsschritt** an (Spalte „Skill“
in Abschnitt 7). Eine Datei unter `migrations/` legt erst S1 an — der `Migrator` würde sonst alles dort
sofort einspielen.

---

## 1. Ausgangslage

| Vorhanden | Folgerung für Phase 3 |
|---|---|
| `jobs` mit `config_json` (Klartext) und `payload_enc` (NOT NULL), `runs` mit `trigger` inkl. `manual`/`test`, `started_by`, `http_status`, `note`, `worker` | Keine neue Job-Spalte nötig. Neu nur Recht, Freigabeliste für interne Ziele, zwei Indizes (Migration 0007). |
| `Runner::run(RunRequest, Heartbeat)`, `RunResult`, `RunnerRegistry`, `Worker` maskiert mit **einem** globalen `SecretMasker` | Schnittstelle bekommt einen Masker **pro Lauf** (H4). `RunResult` bekommt `retryable`. |
| `Planner::reschedule()` öffnet selbst `BEGIN IMMEDIATE`; `Connection` kann nicht verschachteln | Speichern setzt `next_run_at = NULL` in der Speicher-Transaktion, `reschedule()` läuft **danach** (H6). |
| `Kernel` kennt nur `get`/`post`, Pfadparameter nur bei `get` | Kernel um `put`/`delete` und Anforderungen bei `post` erweitern. |
| `RoleGrant` arbeitet mit Kategorie-**Namen**, `null` = ausdrücklich alle | Sichtbarkeit als Wertobjekt `CategoryScope` aus `AccessControl`, nie als leere Liste. |
| `JsonBody::object()` mit fester Tiefe 4 | Tiefe als Parameter (Job-Anfrage braucht 4–5). |
| Docker-Image `dunglas/frankenphp:1-php8.4-alpine` | Die offiziellen PHP-Images bauen `curl` fest ein (`--with-curl`); FrankenPHP baut darauf auf. Keine `install-php-extensions curl` nötig, aber prüfen (S12). Lokal: PHP 8.3.6 mit curl 8.5.0. |
| Klickdummy „Takt“: Abfahrtstafel `ZEIT · JOB · GLEIS · ZIEL · STATUS`, Jobliste `JOB · ART · ZEITPLAN · LETZTER LAUF · NÄCHSTER · BESITZER`, Filter „Alle/HTTP/Shell/Mit Fehler“, Editor mit Methode GET/POST/PUT/HEAD, URL, Header, „Antwort im Verlauf speichern (max. 64 KB)“, Zeitlimit, Wiederholen, Überlappung, Cron-Presets, „NÄCHSTE LÄUFE · EUROPE/BERLIN“, Testlauf-Kasten „Der Testlauf zählt nicht in die Statistik.“ | Begriffe und Aufbau übernehmen. **Abweichung:** Der Klickdummy zeigt die URL im Editor mit `key=••••`. Das widerspricht „keine Geheimnisse in Antworten, auch nicht maskiert“ – siehe E2. |

---

## 2. Entscheidungen

### E1 – Was ist geheim, was nicht

| Wert | Ort | Begründung |
|---|---|---|
| URL (komplett: Pfad, Query, Port, Host) | `payload_enc` | Pfade tragen oft das Geheimnis selbst: Telegram `/bot<token>/…`, Slack-/Discord-Webhooks, healthchecks.io-UUID. Maskieren nach Mustern erkennt das nicht. |
| Header (Namen **und** Werte) | `payload_enc` | Werte sind typisch Tokens. Namen getrennt zu speichern lohnt den Aufwand im MVP nicht (siehe Offen O5). |
| Body | `payload_enc` | Kann Zugangsdaten enthalten. |
| Methode, Zeitlimit, erwartete Statuscodes, Weiterleitungen, „Antwort speichern“ | `config_json` | Steuern nur das Verhalten, verraten nichts. |
| **Ziel** = Schema + Host + Port (`https://img.deuba24.com`) | `config_json.http.target`, beim Ersetzen der Anfrage serverseitig aus der URL abgeleitet | Wird für Abfahrtstafel („ZIEL“), Jobliste und die Herkunftsprüfung gebraucht, ohne zu entschlüsseln. Hostnamen gelten als nicht geheim (Offen O2). |
| `has_headers`, `has_body` | `config_json.http` | Flags für die Antwort, damit lesende Endpunkte nie entschlüsseln. |

`config_json` (Version 1, Klartext, **keine Geheimnisse**):

```json
{
  "v": 1,
  "http": {
    "method": "GET",
    "timeout_seconds": 30,
    "expected_status": [[200, 299]],
    "max_redirects": 3,
    "store_response": false,
    "target": { "scheme": "https", "host": "img.deuba24.com", "port": 443 },
    "has_headers": true,
    "has_body": false
  }
}
```

`payload_enc` = `SecretBox::encrypt(json)` mit Klartext (Version 1):

```json
{ "v": 1, "url": "https://…", "headers": [["Authorization", "Bearer …"]], "body": null }
```

**Versionierung:** `v` steht **im** Klartext. `HttpPayload::fromJson()` nimmt nur bekannte Versionen an;
eine unbekannte Version ist ein fester Fehler ohne Inhalt („Gespeicherte Anfrage hat ein unbekanntes
Format“), der Lauf endet `failed`, nicht wiederholbar. Ein künftiges Format v2 wird nie per SQL-Migration
umgeschrieben (die Migration kann nicht entschlüsseln), sondern beim Lesen umgedeutet und beim nächsten
Speichern als v2 geschrieben, oder über einen eigenen CLI-Befehl. `config_json.v` analog; fehlt `v`, gilt
der Datensatz als ungültig (keine stillen Standardwerte).

Verworfen:
- *Getrennte Spalten `url_enc`, `headers_enc`, `body_enc`*: passt nicht zu Shell-Jobs (Phase 4: Skript),
  braucht eine Tabellen-Neuanlage, bringt nur Komfort beim teilweisen Ersetzen (siehe E3).
- *Basis-URL im Klartext, nur Query verschlüsselt (cron-job.org-Stil)*: Pfad-Tokens wären Klartext in DB und
  Antworten.

### E2 – Die Bearbeiten-Ansicht zeigt die URL **nicht**

Die API liefert URL, Header und Body nie zurück – auch nicht maskiert, gekürzt oder als Länge. Antwort:
`target` (Schema+Host+Port) und `has_url`/`has_headers`/`has_body`. Der Editor zeigt
„Anfrage gesetzt · Ziel https://img.deuba24.com · Header: ja · Body: nein“ und „Anfrage ersetzen“.

Begründung: (1) Sicherheitsnetz „keine Geheimnisse in Antworten, auch nicht maskiert“. (2) Wer bearbeiten
darf, hat das Geheimnis nicht unbedingt eingegeben (Operator einer Kategorie, Vertretung). (3) Eine
gestohlene Sitzung oder ein XSS-Fund liest dann keine Zugangsdaten aus. (4) Musterbasiertes Maskieren
verfehlt Pfad-Tokens (E1).

Verworfen: *maskierte URL wie im Klickdummy* (Regelverstoß, Pfad-Tokens); *„Anzeigen“ mit erneuter
Passworteingabe und Audit-Eintrag* (möglich als spätere Erweiterung, Offen O3).

### E3 – Anfrage nur als Ganzes ersetzen

Beim Ändern ist `request` optional: fehlt es, bleiben URL, Header und Body unverändert; ist es da, ersetzt es
**alle drei** (fehlende Header = keine Header, fehlender Body = kein Body).

Begründung: Die Web-API muss dann nie entschlüsseln (nur verschlüsseln), und es gibt keinen Weg, gespeicherte
Header an ein neues Ziel umzulenken: Ein Operator, der den Token nie gesehen hat, könnte sonst die URL auf
seinen Server ändern und den gespeicherten `Authorization`-Header abgreifen. Mit E3 muss, wer das Ziel
ändert, die Header neu eingeben.

Verworfen: *Teile einzeln ersetzen mit Entschlüsseln und Zusammenführen in der API* (Klartext im
Webprozess, plus eine eigene Regel „bei geändertem Ursprung Header neu eingeben“); *Teile einzeln
verschlüsselt in einem JSON-Container* (Spalteninhalt kein reiner `v1:`-Wert, zwei Schichten).

Folge: Methode GET/HEAD bei gespeichertem Body → 422, außer die Anfrage wird im selben Schritt ersetzt.

### E4 – Testlauf und manueller Lauf gehen über die Warteschlange

Beide legen einen Lauf (`trigger = test` bzw. `manual`, `started_by`, `scheduled_for = jetzt`) an und
antworten `202 {run_id}`. Der Scheduler-Prozess führt ihn im nächsten Takt (≤ 5 s) aus; die Oberfläche fragt
`GET /api/runs/{id}` ab.

Begründung: Eine Stelle für SSRF, Herzschlag, Zeitlimit und Maskieren; kein curl im Webprozess; keine
Webanfrage, die bis zu 120 s hängt; H1 wird beim Übernehmen geprüft.

- Testlauf nur für **gespeicherte** Jobs („Speichern und testen“). Ein Testlauf ungespeicherter Formulardaten
  würde Klartext durch die Warteschlange tragen.
- Testlauf auch für **deaktivierte** Jobs (testen vor dem Aktivieren), manueller Lauf nur für aktive (409).
  Der `Worker` überspringt bisher jeden Lauf eines deaktivierten Jobs → Ausnahme für `trigger = test`
  (Skill `mer-scheduler` §4 anpassen).
- Begrenzung: je Job höchstens **ein** offener (`queued`/`running`) Lauf mit `trigger IN ('manual','test')`
  → sonst 409; je Benutzer höchstens 60 manuelle/Testläufe pro Stunde → sonst 429.
- Testläufe: nicht wiederholt (schon so), nicht in der Statistik (Phase 6 filtert `trigger = 'test'`),
  zählen nicht als „letzter Lauf“ in Liste und Abfahrtstafel.

Verworfen: *synchroner Testlauf im Webprozess* (zweite Ausführungsstelle, lange Anfragen, Egress aus dem
Webcontainer).

### E5 – Interne Ziele standardmäßig gesperrt, Freigabe als Netz-Liste mit eigenem Recht

- Neues Recht `network.internal_targets` (`Permission::ManageInternalTargets`, `isDangerous() = true`, nur
  uneingeschränkt), per Migration nur der Rolle **Admin**.
- Neue Tabelle `http_internal_targets` (Netz in CIDR, Port oder 0 = alle, Notiz). Gilt für alle Jobs
  (Kategorien einzeln: Offen O8).
- Freigabe greift nur für die Sperrklassen „privat“ (RFC 1918, ULA `fc00::/7`, CGNAT `100.64/10`) und
  „Loopback“ (nur als einzelne Adresse `/32` bzw. `/128` **mit** Port). **Nie** freigebbar: Link-local
  inklusive Metadaten (`169.254.0.0/16`, `fe80::/10`), `0.0.0.0/8`, `::`, Multicast, Broadcast,
  Dokumentations-/Benchmark-/reservierte Netze und – ab Phase 4 – die Adressen des docker-socket-proxy
  (sonst wird ein HTTP-Job zum Shell-Job: Rechteausweitung von `jobs.edit_http` auf Docker).
- Pflege per API (`/api/settings/internal-targets`) und CLI (`http:internal-targets`), jeweils mit
  Audit-Eintrag. Oberfläche dafür in Phase 5 (Einstellungen).

### E6 – Sichtbarkeit: eine Funktion für Liste, Detail, Verlauf, Lauf

`AccessControl::scope(array $grants, Permission $p): CategoryScope` leitet den Bereich aus denselben
`RoleGrant::allows()`-Regeln ab wie `can()`. `CategoryScope` ist entweder ausdrücklich „alle“ oder eine Liste
von Kategorienamen (leer = nichts). Repositories bekommen den Scope und setzen **ein** SQL-Prädikat:

```sql
WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
```

Jobs ohne Kategorie fallen bei beschränkten Rollen heraus (`c.name` ist NULL). Detail, Verlauf und Lauf laden
über dasselbe Prädikat (`findVisible`) **und** rufen danach `AccessControl::require()` mit der gespeicherten
Kategorie für das konkrete Recht. Nicht sichtbar → 404; sichtbar, aber Recht fehlt → 403.
Ein Eigenschaftstest prüft für alle Rollen-/Kategorie-Kombinationen: `scope->contains(k) === can(…, k)`.

### E7 – HTTP-Runner: curl\_multi, gepinnte Adresse, eine Stelle für SSRF

Details in Abschnitt 5. Kurz: eigene DNS-Auflösung vor jeder Verbindung, Prüfung **aller** Adressen,
Festhalten per `CURLOPT_RESOLVE`, keine automatischen Weiterleitungen, Proxy-Umgebung ignoriert, TLS fest an,
Herzschlag in der `curl_multi`-Schleife, Rohausgabe schon beim Lesen begrenzt.

### E8 – Masker pro Lauf (H4)

`Worker::execute()` erzeugt für jeden Lauf `new SecretMasker()` und reicht ihn an den Runner. Der Runner
registriert die entschlüsselten Teile darin; der Worker maskiert Ausgabe und Notiz mit **diesem** Masker und
verwirft ihn danach. Die globale Liste wächst nicht mehr, und Werte eines Jobs landen nicht im Speicher
anderer Läufe. Lesende API-Endpunkte maskieren zusätzlich mit einem frischen Masker (nur Muster).

### E9 – Zeitlimit höchstens 120 s, Standard 30 s

Der Worker führt Läufe nacheinander im Takt-Prozess aus. Ein hängendes Ziel hält alle anderen Jobs auf. Bis
Phase 4 (Worker-Prozesse) bleibt das Zeitlimit deshalb klein (Offen O6).

---

## 3. Datenmodell

### 3.1 Migration `0007_http_jobs.sql` (Entwurf, anzulegen in S1)

```sql
-- Phase 3: HTTP-Jobs.
-- Recht, interne Ziele für HTTP-Jobs freizugeben. Lockert den SSRF-Schutz, deshalb eigenes Recht und nur
-- für die Rolle Admin (neue Rechte nie automatisch an Operator/Beobachter).
INSERT INTO role_permissions (role_id, permission)
    SELECT r.id, 'network.internal_targets' FROM roles r
     WHERE r.name = 'Admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'network.internal_targets');

-- Freigegebene interne Netze. port = 0 heißt „alle Ports“ (kein NULL, damit UNIQUE greift).
CREATE TABLE http_internal_targets (
    id         INTEGER PRIMARY KEY,
    network    TEXT    NOT NULL,                        -- normalisiert, z. B. 192.168.1.0/24, fd12:3456::/48
    port       INTEGER NOT NULL DEFAULT 0 CHECK (port BETWEEN 0 AND 65535),
    note       TEXT    NOT NULL DEFAULT '' CHECK (length(note) <= 200),
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (network, port)
);

-- Liste nach Kategorie kappen, letzter Lauf je Job.
CREATE INDEX jobs_category ON jobs (category_id);
CREATE INDEX runs_job_id ON runs (job_id, id DESC);
```

Keine Änderung an `jobs`: `config_json` und `payload_enc` reichen (E1). `ON DELETE CASCADE` von `runs` auf
`jobs` bleibt: Job löschen löscht seinen Verlauf (Audit-Eintrag bleibt). `ON DELETE SET NULL` der Kategorie
macht Jobs nur noch für uneingeschränkte Rollen sichtbar – das erweitert keine Rechte.

### 3.2 Validierung (Allowlist, ablehnen statt zurechtschneiden)

Fehler → 422 `{"error": "<was falsch ist und wie es richtig geht>", "field": "<pfad>"}`. Meldungen enthalten
**nie** den Eingabewert.

| Feld | Regel |
|---|---|
| `name` | 1–100 Zeichen UTF-8, keine Steuerzeichen, kein Leerraum am Anfang/Ende |
| `type` | `http` (Phase 3). `shell` → 422 „Shell-Jobs folgen mit Phase 4.“ |
| `category_id` | Ganzzahl einer bestehenden Kategorie, in der der Benutzer `jobs.edit_http` hat, oder `null` (nur uneingeschränkt). Unbekannt **oder** nicht erlaubt → dieselbe Meldung „Kategorie unbekannt oder nicht erlaubt.“ |
| `cron` | ≤ 100 Zeichen, `CronSchedule::forJob()` gültig, `nextAfter(jetzt)` liefert einen Termin |
| `timezone` | in `DateTimeZone::listIdentifiers()` |
| `is_enabled`, `catch_up`, `store_response` | bool (JSON `true`/`false`, keine Zahlen) |
| `overlap_policy` | `skip` · `parallel` · `queue` |
| `retry_count` | 0–`RetryPolicy::MAX_RETRIES` (10) |
| `retry_delay_seconds` | 1–3600 |
| `http.method` | `GET` · `POST` · `PUT` · `PATCH` · `DELETE` · `HEAD` |
| `http.timeout_seconds` | 1–120, Standard 30 |
| `http.expected_status` | Text `^[1-5][0-9]{2}(-[1-5][0-9]{2})?(,[1-5][0-9]{2}(-[1-5][0-9]{2})?){0,19}$`, je Bereich von ≤ bis; gespeichert als Liste `[[von, bis], …]`; Standard `200-299` |
| `http.max_redirects` | 0–5, Standard 3 |
| `request.url` | siehe 3.3 |
| `request.headers` | ≤ 30 Einträge `{name, value}`; Name RFC-7230-Token `^[!#$%&'*+.^_\`\|~0-9A-Za-z-]{1,64}$`; Wert ≤ 4096 Byte, nur `\x20-\x7E` und UTF-8 ohne CR/LF/NUL, kein Leerraum am Rand; Summe ≤ 16 KiB; verboten (Groß/klein egal): `Host`, `Content-Length`, `Transfer-Encoding`, `Connection`, `Upgrade`, `TE`, `Trailer`, `Keep-Alive`, `Expect`, `Proxy-*` |
| `request.body` | `null` oder Text ≤ 64 KiB, gültiges UTF-8; nur bei POST/PUT/PATCH/DELETE |
| Anfragekörper gesamt | ≤ 160 KiB, JSON-Tiefe ≤ 5 |

### 3.3 URL-Syntax (`UrlPolicy`, gleiche Prüfung beim Speichern und im Runner)

Ziel: PHP (`parse_url`) und curl dürfen die URL nicht unterschiedlich lesen.

- ≤ 2048 Byte, nur druckbares ASCII ohne Leerzeichen, ohne `\`, ohne `#` (Fragment wird nie gesendet:
  „Bitte den Teil ab # entfernen.“), ohne `@` in der Authority (Zugangsdaten → Header `Authorization`).
- Schema `http` oder `https` (klein geschrieben gespeichert).
- Host: entweder IPv4 in Punktschreibweise ohne führende Nullen (`^(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(…)){3}$`),
  oder IPv6 in eckigen Klammern (`FILTER_VALIDATE_IP` + `FILTER_FLAG_IPV6`, ohne Zonen-ID `%`), oder ein
  DNS-Name: Kleinbuchstaben/Ziffern/Bindestrich, Labels 1–63, gesamt ≤ 253, letztes Label nicht rein
  numerisch, kein Punkt am Ende, keine Nicht-ASCII-Zeichen (IDN als Punycode `xn--…`). Dadurch scheitern
  `http://2130706433/`, `http://0x7f.1/`, `http://127.1/`, `http://0/` schon an der Syntax.
- Namen `localhost`, `*.localhost`, `metadata.google.internal`, `metadata` → gesperrt, unabhängig von DNS.
- Port 1–65535, optional.
- Pfad und Query: erlaubte Zeichen RFC 3986 (`A-Za-z0-9-._~!$&'()*+,;=:@/?%` mit gültigen `%XX`).
- Beim **Speichern** wird nicht aufgelöst (keine DNS-Abfrage aus dem Webprozess). IP-Literale werden schon
  beim Speichern gegen die Sperrnetze geprüft (sofortige Rückmeldung). Die verbindliche Prüfung macht der
  Runner bei jedem Lauf.

---

## 4. API

### 4.1 Ablauf jedes Endpunkts (mer-security §1)

1. Sitzung (`SessionAuth`) – ohne → 401.
2. Bei POST/PUT/DELETE: `CsrfGuard::check()` → sonst 403 (vor dem Laden).
3. Eingaben validieren (3.2) → 422.
4. Datensatz über `findVisible(id, scope(view))` laden → sonst 404.
5. `AccessControl::require($grants, <Recht>, <gespeicherte Kategorie>)` → sonst 403.
6. Aktion in `Connection::transaction()`/`immediate()`.
7. Audit-Eintrag.
8. Antwort: nur freigegebene Felder, Texte durch `SecretMasker::mask()`, `Cache-Control: no-store`,
   `JSON_INVALID_UTF8_SUBSTITUTE` (H3).

Kernel-Erweiterung: `put()`, `delete()`, Anforderungen bei `post()`; Pfadparameter `{id}` mit `[1-9][0-9]{0,17}`.
Eine Ausnahme `ValidationFailed(field, message)` wird im Kernel zu 422 (Meldung ist ein fester Text).

### 4.2 Endpunkte und Rechte

„Op“ = Operator uneingeschränkt, „Op/A“ = Operator nur Kategorie A. Job X liegt in B, Job N hat keine Kategorie.

| Methode und Pfad | Recht (Kategorie aus der DB) | Admin | Op | Op/A auf A · auf B/N | Beobachter | anonym |
|---|---|---|---|---|---|---|
| `GET /api/jobs` | `jobs.view` (Scope) | alle | alle | nur A | alle | 401 |
| `GET /api/jobs/{id}` | `jobs.view` | 200 | 200 | 200 · 404 | 200 | 401 |
| `POST /api/jobs` | `jobs.edit_http` in der **Zielkategorie** (Anlegen: kein gespeicherter Datensatz) | 201 | 201 | 201 · 422 | 403 | 401 |
| `PUT /api/jobs/{id}` | `jobs.edit_http` in alter **und** neuer Kategorie | 200 | 200 | 200 · 404 (B→A: 404; A→B: 422) | 403 | 401 |
| `DELETE /api/jobs/{id}` | `jobs.edit_http` | 204 | 204 | 204 · 404 | 403 | 401 |
| `POST /api/jobs/{id}/enable` · `/disable` | `jobs.edit_http` | 200 | 200 | 200 · 404 | 403 | 401 |
| `POST /api/jobs/{id}/run` | `jobs.run` | 202 | 202 | 202 · 404 | 403 | 401 |
| `POST /api/jobs/{id}/test` | `jobs.run` | 202 | 202 | 202 · 404 | 403 | 401 |
| `GET /api/jobs/{id}/runs` | `jobs.view` | 200 | 200 | 200 · 404 | 200 | 401 |
| `GET /api/runs/{id}` | `jobs.view` (Kategorie des Jobs) | 200 | 200 | 200 · 404 | 200 | 401 |
| `GET /api/schedule/preview` | `jobs.view` in mindestens einer Kategorie (`scope` nicht leer) | 200 | 200 | 200 | 200 | 401 |
| `GET /api/categories?permission=jobs.view\|jobs.edit_http` | angefragtes Recht (Scope); andere Werte → 422 | alle | alle | nur A | view: alle, edit: leer | 401 |
| `GET /api/settings/internal-targets` | `network.internal_targets` | 200 | 403 | 403 | 403 | 401 |
| `POST /api/settings/internal-targets` | `network.internal_targets` | 201 | 403 | 403 | 403 | 401 |
| `DELETE /api/settings/internal-targets/{id}` | `network.internal_targets` | 204 | 403 | 403 | 403 | 401 |

Shell-Jobs (`type = shell`) prüfen dort, wo oben `jobs.edit_http` steht, `jobs.edit_shell` (Phase 4). Die
Funktion wählt das Recht aus dem **gespeicherten** Typ; ein Wechsel des Typs beim Ändern ist verboten (422).

### 4.3 Antworten

Job (Liste: ohne `http.expected_status` u. ä. Details; Detail: vollständig):

```json
{
  "id": 12, "name": "Hub", "type": "http",
  "category": { "id": 2, "name": "Deuba24" },
  "owner": { "id": 1, "display_name": "Alex" },
  "cron": "* * * * *", "timezone": "Europe/Berlin", "next_run_at": "2026-10-07T13:27:00+00:00",
  "is_enabled": true, "overlap_policy": "skip", "retry_count": 0, "retry_delay_seconds": 60, "catch_up": false,
  "http": {
    "method": "GET", "timeout_seconds": 30, "expected_status": "200-299", "max_redirects": 3,
    "store_response": false, "target": "https://img.deuba24.com",
    "has_url": true, "has_headers": true, "has_body": false
  },
  "last_run": { "id": 991, "status": "ok", "trigger": "schedule", "finished_at": "…", "duration_ms": 2210, "http_status": 200 },
  "running": false,
  "can": { "edit": true, "run": true },
  "created_at": "…", "updated_at": "…"
}
```

- `target` = Schema + Host (+ Port, wenn nicht Standard). Nie Pfad, Query, Header, Body.
- `can` ist Komfort für die Oberfläche, aus denselben Grants berechnet; der Server prüft trotzdem.
- `last_run`: letzter abgeschlossener Lauf (`ok/failed/timeout/aborted`) ohne Testläufe; `running`: irgendein
  Lauf `running`.
- Liste: höchstens 500 Jobs, `{"jobs": […], "truncated": bool}`; Filter `type`, `category_id`,
  `sort=name|next_run`, `enabled=1` sind Anfragen innerhalb des Scopes.

Lauf (Verlaufsliste ohne `output`; Detail mit `output`):

```json
{ "id": 991, "job_id": 12, "trigger": "manual", "status": "ok", "attempt": 1,
  "scheduled_for": "…", "started_at": "…", "finished_at": "…", "duration_ms": 2210,
  "http_status": 200, "note": "…", "output": "…",
  "started_by": { "id": 1, "display_name": "Alex" } }
```

- **H2:** `worker` und `heartbeat_at` erscheinen nie (Feld-Allowlist im Mapper, Test sucht die Besitzer-ID im
  Antworttext).
- Verlauf: `?limit=1..100` (Standard 50), `?before_id=` als Cursor, `?status=` aus dem `RunStatus`-Enum.
- Vorschau: `GET /api/schedule/preview?cron=…&timezone=…&count=1..10` →
  `{"runs": ["2026-10-07T15:27:00+02:00", …]}` in der Zeitzone des Jobs (Wanduhrzeit für „NÄCHSTE LÄUFE ·
  EUROPE/BERLIN“). Fehler 422 mit fester Meldung. Cron ist kein Geheimnis, deshalb darf er in die Query.

### 4.4 Audit-Einträge (`target` nie mit Geheimnissen; der AuditLog maskiert zusätzlich)

| Aktion | `target` |
|---|---|
| `job.created` | `job:12 Hub` |
| `job.updated` | `job:12 Hub; geändert: cron, http.method[, Kategorie Deuba24 → NAS][, Anfrage ersetzt]` (nur Feldnamen) |
| `job.deleted` | `job:12 Hub` |
| `job.enabled` / `job.disabled` | `job:12 Hub` |
| `job.run_manual` / `job.run_test` | `job:12 Hub; run:991` |
| `network.internal_target_added` / `_removed` | `192.168.1.0/24:8080` |

Abgelehnte Rechteprüfungen werden nicht protokolliert (sonst Spam durch normales Bedienen).

### 4.5 H1, H2, H3, H6

- **H1** Beim Einreihen: `require(jobs.run, gespeicherte Kategorie)` + Audit. Im `Worker::claim()`: für
  `trigger IN ('manual','test')` und für `retry` mit `started_by IS NOT NULL` fragt eine neue Schnittstelle
  `RunAuthorizer::mayStart(userId, jobId)` (frische Grants, `is_active`, Kategorie aus der DB). Nein oder
  `started_by IS NULL` bei manual/test → `skipped` mit Notiz „Übersprungen: Der auslösende Benutzer darf
  diesen Job nicht mehr starten.“ `scheduleRetry()` übernimmt `started_by`.
- **H2** siehe 4.3.
- **H3** Runner begrenzt beim Lesen (5.4); API kodiert mit `JSON_INVALID_UTF8_SUBSTITUTE`; der Runner
  speichert Nicht-UTF-8-Antworten gar nicht (Binär-Vermerk).
- **H6** Speichern (Anlegen; Ändern mit geändertem `cron`/`timezone`/`is_enabled`; Aktivieren) setzt in
  derselben Transaktion `next_run_at = NULL`; nach dem Commit ruft der Endpunkt `Planner::reschedule($id)`.
  So plant der Scheduler zwischen Commit und Neuberechnung nie nach dem alten Zeitplan. Deaktivieren setzt
  `is_enabled = 0, next_run_at = NULL` in einer Anweisung.

---

## 5. HTTP-Runner

### 5.1 Schnittstellen (Skizze)

```php
namespace Meridian\Runner;

interface Runner
{
    /** $masker gehört nur diesem Lauf (H4); der Runner registriert darin alle entschlüsselten Teile. */
    public function run(RunRequest $request, Heartbeat $heartbeat, SecretMasker $masker): RunResult;
}

final readonly class RunRequest { /* bisher + */ public ?int $startedBy; }

final readonly class RunResult
{
    /* bisher + */ public bool $retryable;   // false: Einrichtungsfehler (gesperrtes Ziel, Payload unlesbar …)
    public static function failed(string $output = '', ?string $note = null, ?int $exitCode = null,
                                  ?int $httpStatus = null, bool $retryable = true): self;
}

namespace Meridian\Schedule;

interface RunAuthorizer { public function mayStart(int $userId, int $jobId): bool; }   // H1, wirft nie

namespace Meridian\Runner\Http;

final class HttpPayload            // __debugInfo ohne Werte, __serialize wirft, Konstruktor #[\SensitiveParameter]
{
    public static function fromJson(#[\SensitiveParameter] string $json): self;   // nur v=1
    public function toJson(): string;
    public function registerIn(SecretMasker $masker): void;                      // siehe 5.3
}
final readonly class HttpJobConfig { /* method, timeoutSeconds, expectedStatus, maxRedirects, storeResponse,
                                        target, hasHeaders, hasBody; fromArray()/toArray() */ }
final class UrlPolicy      { public function parse(#[\SensitiveParameter] string $url): ParsedUrl; }  // 3.3, wirft ValidationFailed ohne Wert
final readonly class ParsedUrl { /* scheme, host, port, isIpLiteral; origin(): string; sensitive toString() */ }
interface HostResolver     { /** @return list<string> */ public function resolve(string $host): array; }
final class SystemHostResolver implements HostResolver   // gethostbynamel() (inkl. /etc/hosts, Docker-DNS) + dns_get_record(DNS_AAAA)
final class AddressPolicy  { public function check(string $ip, int $port): ?BlockReason; }   // null = erlaubt
enum BlockReason: string   { case Private; case Loopback; case LinkLocal; case Reserved; case Multicast; case Infrastructure; }
final class TargetGuard    { public function pin(ParsedUrl $url): PinnedTarget; }  // wirft TargetBlocked/TargetUnresolvable
final readonly class PinnedTarget { public ParsedUrl $url; /** @var list<string> */ public array $ips; }
interface HttpTransport    { public function send(TransportRequest $r, Heartbeat $h): TransportResponse; }  // genau ein Hop
final class CurlTransport implements HttpTransport
final class HttpRunner implements Runner
interface HttpJobSource    { public function load(int $jobId): ?StoredHttpJob; }   // liest config_json + payload_enc, schreibt nie
```

`AddressPolicy` lädt die Freigaben bei jedem Lauf frisch aus `http_internal_targets` (Änderungen gelten
sofort). Prüfung über `inet_pton` und Bitmasken, ohne Abhängigkeit.

### 5.2 Gesperrte Adressen

IPv4: `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`,
`192.0.0.0/24`, `192.0.2.0/24`, `192.88.99.0/24`, `192.168.0.0/16`, `198.18.0.0/15`, `198.51.100.0/24`,
`203.0.113.0/24`, `224.0.0.0/4`, `240.0.0.0/4` (inkl. `255.255.255.255`).
IPv6: `::/128`, `::1/128`, `::ffff:0:0/96` und `64:ff9b::/96` (eingebettete IPv4 prüfen), `2002::/16`
(6to4: eingebettete IPv4 prüfen), `100::/64`, `2001::/23`, `2001:db8::/32`, `fc00::/7`, `fe80::/10`,
`fec0::/10`, `ff00::/8`.
Freigebbar laut E5 nur „privat“ und „Loopback“ (einzeln, mit Port). Hat ein Name mehrere Adressen, muss **jede**
erlaubt sein, sonst wird abgelehnt. Keine Adresse → `failed` „Ziel nicht auflösbar: Hostname prüfen.“

### 5.3 Ablauf eines Laufs

1. `HttpJobSource::load()`; fehlt der Job oder ist `type ≠ http` → `failed`, nicht wiederholbar.
2. `beat()`. `payload_enc` entschlüsseln, `HttpPayload::fromJson()`. Fehler → feste Notiz „Gespeicherte
   Anfrage nicht lesbar (Schlüssel oder Format). Anfrage im Job neu eingeben.“, nicht wiederholbar.
3. **Sofort** `registerIn($masker)`: ganze URL; URL ohne Query; jeder Query-Wert (roh und dekodiert);
   Pfadsegmente ab 16 Zeichen; jeder Header-Wert, bei `Bearer x`/`Basic x` zusätzlich `x` und bei Basic die
   dekodierten Teile; Body komplett, bei JSON jeder Text-Blattwert ab 8 Zeichen, bei
   `application/x-www-form-urlencoded` jeder Wert. (Kürzere Werte fängt `MIN_KNOWN_LENGTH` bzw. die Muster.)
4. `UrlPolicy::parse()` (erneut, auch für Altbestände).
5. Für Hop 0 … `max_redirects`: `beat()` → `TargetGuard::pin()` → `beat()` →
   `HttpTransport::send()` mit der **verbleibenden** Gesamtzeit (`timeout_seconds` gilt für alle Hops zusammen).
   - 3xx mit `Location` und Hops übrig: relative Adresse auflösen, `UrlPolicy::parse()`; `https` → `http`
     verboten; 303 und 301/302 bei POST → GET ohne Body; anderer Ursprung (Schema/Host/Port) → **keine
     Job-Header, kein Body**; 307/308 mit Body auf anderen Ursprung → `failed` (nicht wiederholbar).
   - sonst Ende.
6. Bewertung: Endstatus in `expected_status` → `ok`, sonst `failed` „Unerwarteter Statuscode 500 (erwartet:
   200-299).“ (bei 3xx ohne weitere Hops: „… Weiterleitungen: 0 erlaubt.“).
7. Ausgabe (Rohtext, der Worker maskiert → kürzt → speichert):
   ```
   → GET https://img.deuba24.com
   ← 301 · 0,12 s · Weiterleitung 1 von 3 (gleicher Server)
   ← 200 · 2,21 s · 312 B
   Antwort (maskiert, gekürzt):
   …nur bei store_response = true und Text-UTF-8, sonst „[Binärinhalt, 12 345 B, nicht gespeichert]“
   ```
   Nie: Pfad, Query, Header-Werte, Antwort-Header außer Größe/Typ.

Fehlerzuordnung (feste Texte, nie `curl_error()`; die Meldung enthält die URL):

| Fall | Status | wiederholbar |
|---|---|---|
| Zeitlimit (`CURLE_OPERATION_TIMEDOUT`) | `timeout` „Zeitlimit von 30 s überschritten.“ | ja |
| DNS ohne Ergebnis | `failed` „Ziel nicht auflösbar …“ | ja |
| Verbindung abgelehnt/nicht erreichbar | `failed` „Verbindung fehlgeschlagen …“ | ja |
| TLS (`CURLE_PEER_FAILED_VERIFICATION`, `SSL_*`) | `failed` „TLS-Prüfung fehlgeschlagen: Zertifikat ungültig, abgelaufen oder für einen anderen Namen.“ | nein |
| Ziel gesperrt (5.2) | `failed` „Ziel gesperrt: Die Adresse liegt in einem internen oder reservierten Netz. Interne Ziele kann ein Admin freigeben.“ (ohne IP) | nein |
| Weiterleitung ungültig/Downgrade/zu viele | `failed` mit festem Text | nein |
| Antwort > 10 MiB | Bewertung nach Statuscode, Notiz „Antwort nach 10 MiB abgebrochen.“ | – |
| `beat() === false` | Übertragung sofort abbrechen, Ergebnis egal (Worker speichert nichts mehr) | – |

### 5.4 curl-Einstellungen (`CurlTransport`)

| Option | Wert |
|---|---|
| `CURLOPT_PROTOCOLS_STR`, `CURLOPT_REDIR_PROTOCOLS_STR` | `http,https` |
| `CURLOPT_FOLLOWLOCATION` | `false` (Hop-Schleife in 5.3); `CURLOPT_MAXREDIRS` 0 |
| `CURLOPT_RESOLVE` | `["host:port:ip1,ip2"]` (IPv6 in `[]`), Host exakt so klein geschrieben wie in der URL |
| `CURLOPT_PREREQFUNCTION` (PHP ≥ 8.4) | verbundene IP ∈ gepinnte IPs, sonst abbrechen (zweite Absicherung) |
| `CURLOPT_PROXY` `''`, `CURLOPT_NOPROXY` `'*'` | Proxy-Umgebungsvariablen ignorieren (sonst prüft SSRF den falschen Rechner) |
| `CURLOPT_SSL_VERIFYPEER` / `VERIFYHOST` | `true` / `2`, nie schaltbar |
| `CURLOPT_CONNECTTIMEOUT_MS` / `TIMEOUT_MS` | min(10 s, Rest) / Rest der Gesamtzeit |
| `CURLOPT_FRESH_CONNECT`, `CURLOPT_FORBID_REUSE` | `true` (keine Verbindung über Hops hinweg wiederverwenden) |
| `CURLOPT_NOSIGNAL` | `true` |
| `CURLOPT_HEADERFUNCTION` | Statuszeile, `Location`, `Content-Type`, `Content-Length` merken; Header gesamt ≤ 32 KiB, sonst abbrechen |
| `CURLOPT_WRITEFUNCTION` | erste 80 KiB behalten (64 KiB Ausgabe + Überhang fürs Maskieren), Rest nur zählen; > 10 MiB abbrechen (H3) |
| `CURLOPT_ACCEPT_ENCODING` | nicht setzen (keine Entpack-Bombe) |
| Cookies, `CURLOPT_UNRESTRICTED_AUTH` | aus |
| `CURLOPT_USERAGENT` | `Meridian/<version>` |
| HEAD | `CURLOPT_NOBODY` |

Schleife: `curl_multi_exec` + `curl_multi_select($mh, 1.0)`; nach jedem Durchgang `beat()` (drosselt selbst
auf 5 s). DNS (`gethostbynamel`) blockiert ohne eigenes Zeitlimit; `beat()` direkt davor und danach, Resolver
im Container mit `options timeout:2 attempts:2` (Restrisiko in R prüfen).

### 5.5 Verdrahtung

`bin/meridian`: `scheduler:run` lädt den Schlüssel über `KeyLoader::load($env)` erst, wenn der Befehl
ausgeführt wird (andere Befehle laufen ohne Schlüssel weiter), und registriert
`JobType::Http → new HttpRunner(new DbHttpJobSource($db), $box, new UrlPolicy(), new TargetGuard(new SystemHostResolver(), new DbAddressPolicy($db)), new CurlTransport())`.
Ohne lesbaren Schlüssel endet der Befehl mit Exit 1 und der Meldung von `KeyLoader`.

---

## 6. Oberfläche (Phase-3-Scheiben)

Gemeinsam: Hash-Router mit Parametern (`#/jobs`, `#/jobs/neu`, `#/jobs/12`, `#/jobs/12/bearbeiten`),
`request()` mit PUT/DELETE, Typen für Job/Lauf, `lib/permissions.ts` um `canEditHttp(profile, kategorie)`,
`canRun(…)` (nur Komfort; die API liefert zusätzlich `can`). Menüpunkt „Jobs“ aktiv, „Verlauf“ bleibt „bald“.
Keine neuen npm-Pakete.

| Scheibe | Inhalt | Daten |
|---|---|---|
| **U2 Übersicht „Nächste Abfahrten“** | Tafel wie Klickdummy: ZEIT (nächster Lauf, Ortszeit) · JOB · GLEIS (`HTTP`) · ZIEL (`target` ohne Schema) · STATUS (`läuft` in Signalgelb mit `pulse`, `pünktlich`, `letzter Lauf gestört`, `deaktiviert`). Leerzustand bleibt. Neu laden alle 30 s. Taktband und „Heute bisher“ folgen in Phase 5. | `GET /api/jobs?sort=next_run&enabled=1` (erste 12) |
| **U2 Jobliste** | Spalten JOB (+ Ziel) · ART · ZEITPLAN (Preset-Name oder Cron) · LETZTER LAUF · NÄCHSTER · BESITZER; Filter-Chips „Alle/HTTP/Shell/Mit Fehler“ mit Zahlen (clientseitig über die gekappte Liste); „Neuen Job anlegen“ nur bei `canEditHttp` in irgendeiner Kategorie; Hinweis „Du siehst nur Jobs deiner Kategorien“, wenn beschränkt. | `GET /api/jobs` |
| **U3 Job anlegen/bearbeiten** | Titel; Art (nur „Adresse aufrufen“ wählbar, „Befehl ausführen“ sichtbar, deaktiviert mit „folgt“); Methode; Bereich **Anfrage**: neu = Felder URL (`autocomplete="off"`, `spellcheck=false`), Header-Zeilen, Body (nur POST/PUT/PATCH/DELETE); bearbeiten = Zusammenfassung „Anfrage gesetzt · Ziel … · Header: ja · Body: nein“ + „Anfrage ersetzen“ (öffnet **leere** Felder, Hinweis „URL, Header und Body werden zusammen ersetzt“, „Abbrechen“ behält die alte). Zeitplan: Presets aus dem Klickdummy + Cron-Feld (JetBrains Mono), Vorschau nach 400 ms Pause über `/api/schedule/preview`, Kasten „NÄCHSTE LÄUFE · <ZEITZONE>“; Kategorie (nur `edit_http`-Kategorien); Zeitzone (`Intl.supportedValuesOf`, Server prüft); Zeitlimit; erwartete Statuscodes; Weiterleitungen; Wiederholen; Überlappung; Nachholen; „Antwort im Verlauf speichern (max. 64 KB)“. Knöpfe „Speichern“, „Speichern und testen“, „Löschen“ (Bestätigung). 422-Meldungen am Feld (`field`). Geheimfelder nach dem Speichern aus dem Zustand löschen. | `POST/PUT/DELETE /api/jobs…`, `/api/categories?permission=jobs.edit_http` |
| **U4 Job-Detail** | Kopf mit Name, Ziel, Zeitplan, Aktiv-Schalter (`can.edit`), „Jetzt ausführen“ (`can.run`, nur aktiv), „Testlauf“; Verlauf (Liste, „Mehr laden“ per `before_id`), Lauf auswählen → Notiz und Ausgabe als `<pre>{text}</pre>`; ausgelöster Lauf wird alle 2 s abgefragt (höchstens 3 min, danach „läuft noch – später nachsehen“). Testlauf-Ergebnis als Kasten „Testlauf erfolgreich/fehlgeschlagen … Der Testlauf zählt nicht in die Statistik.“ 409/429 als Text. | `/api/jobs/{id}`, `/runs`, `/api/runs/{id}`, `/run`, `/test` |

Pflicht aus `mer-ui`: kein `dangerouslySetInnerHTML`, Ausgaben als Text, CSRF-Header bei POST/PUT/DELETE,
nichts im Browser-Speicher, Geheimfelder nie vorbefüllt, Handybreite (Tabellen in Scroll-Box),
`prefers-reduced-motion` für `pulse`/`blink`, sichtbarer Fokus.

---

## 7. Umsetzungsreihenfolge

Jeder Schritt endet mit grünem `composer check` (bzw. `npm run build` + Typprüfung) und einem eigenen Commit.

| # | Agent | Inhalt | Abnahme / Tests | Skill |
|---|---|---|---|---|
| S1 | sicherheit | `Permission::ManageInternalTargets` (`isDangerous`), Migration `0007_http_jobs.sql` (3.1), `CategoryScope` + `AccessControl::scope()` | Migrationstest (Admin hat Recht, Operator/Beobachter nicht, zweimal einspielen ändert nichts); Eigenschaftstest `scope ≡ can` über Admin/Op/Op-A/Beobachter/leere Zuweisung/gelöschte Kategorie; beschränkte Rolle bekommt `network.internal_targets` nie | mer-security §4/§5, mer-storage §3 |
| S2 | backend | Kernel `put`/`delete`/`post` mit Anforderungen, `ValidationFailed` → 422, gemeinsamer JSON-Helfer (`no-store`, `JSON_INVALID_UTF8_SUBSTITUTE`), `JsonBody::object(…, depth)` | Kernel-Tests: 405/404, ungültige ID → 404, ungültiges UTF-8 im Text → gültiges JSON | mer-security §9 |
| S3 | sicherheit | `HttpPayload`, `UrlPolicy`, `AddressPolicy`, `BlockReason`, `HostResolver`, `TargetGuard` (rein, ohne curl) | Tabelle aller SSRF-Fälle aus 7.1 als Datenprovider; `HttpPayload`: `var_dump`/`print_r`/`json_encode`/Exception-Trace ohne Klartext, `serialize` wirft, unbekannte Version abgelehnt | mer-runner §3/§4 |
| S4 | backend | `HttpJobConfig`, `JobInput` (Validierung 3.2), `JobRepository` (`findVisible`, `listVisible`, `insert`, `update`, `delete`, `setEnabled`), `RunRepository` (`findVisible`, `listForJob`) | Roundtrip über neue `Connection`: `config_json` ohne Test-Geheimnis, `payload_enc` beginnt mit `v1:`, entschlüsselt zum Original; Fehlermeldungen enthalten keinen Eingabewert | mer-storage §8 |
| S5 | backend | Lesende API: `GET /api/jobs`, `/api/jobs/{id}`, `/api/jobs/{id}/runs`, `/api/runs/{id}`, `/api/categories`, `/api/schedule/preview` | Rollen-Matrix 4.2 (Admin, Op, Op/A, Beobachter, anonym) mit Kategorien A, B und Job ohne Kategorie; IDOR: Op/A liest Job/Lauf aus B per ID → 404; `category=B`-Filter liefert für Op/A leer; **H2**-Test; Vorschau: `count=11` → 422, ungültige Zeitzone → 422 | mer-security §5 |
| S6 | backend | Schreibende API: anlegen, ändern (E3), löschen, aktivieren/deaktivieren, **H6**, Audit 4.4 | Rollen-Matrix; CSRF fehlt/falsch/fremder Origin → 403 ohne Änderung; Kategorie-Wechsel A→B für Op/A → 422; Leak: Test-Geheimnis in URL, Header, Body erscheint in keiner Antwort, keinem Audit-Eintrag, keiner Klartextspalte; nach Anlegen/Ändern/Aktivieren ist `next_run_at` gesetzt, nach Deaktivieren NULL; GET-Methode mit gespeichertem Body → 422 | mer-security §1/§14, mer-scheduler §1 |
| S7 | sicherheit | Worker: Masker pro Lauf (**H4**, neue `Runner`-Signatur, `FakeRunner` anpassen), `RunRequest::startedBy`, **H1** im `claim()` über `RunAuthorizer`, Retry übernimmt `started_by`, Testlauf für deaktivierte Jobs, `RunResult::retryable` | Bestehende Worker-Tests grün; Geheimnis aus Lauf 1 steht nicht im Masker von Lauf 2; Benutzer deaktiviert / Recht entzogen / Job in fremde Kategorie verschoben zwischen Einreihen und Übernahme → `skipped` mit Notiz; `retryable = false` → keine Wiederholung | mer-scheduler §3/§4/§5, mer-runner §4 |
| S8 | backend | `POST /api/jobs/{id}/run` und `/test` (E4: 202, Begrenzungen 409/429, Audit) | Rollen-Matrix; Beobachter → 403; deaktiviert: run → 409, test → 202; zweiter offener manueller Lauf → 409; 61. Lauf/Stunde → 429; Lauf trägt `started_by`, `trigger` | mer-scheduler §5 |
| S9 | backend | `CurlTransport` + `HttpRunner` (5.3/5.4), Verdrahtung 5.5, Heartbeat | Integrationstests gegen `php -S 127.0.0.1:<port>` mit Testrouter unter `tests/Fixtures/http/` (Loopback per Freigabe-Eintrag erlaubt): Methoden, Header, Body, Statusbewertung, Weiterleitungen (gleich/fremder Ursprung: Header fehlen beim zweiten Ziel), Zeitlimit → `timeout`, 11 MiB → Abbruch, Binärantwort; **Pinning-Beweis**: Fake-Resolver liefert für `meridian-test.invalid` die Testserver-IP – gelingt der Aufruf, hat curl nicht selbst aufgelöst; Herzschlag: Server antwortet nach 3 s, `beat()` mindestens dreimal; `beat() → false` bricht in ≤ 2 s ab; Optionen-Test: VERIFYPEER/VERIFYHOST/FOLLOWLOCATION/PROXY | mer-runner §3/§6 |
| S10 | sicherheit | Freigabeliste: `GET/POST/DELETE /api/settings/internal-targets`, CLI `http:internal-targets list\|add\|remove`, Audit | Nur `network.internal_targets` uneingeschränkt (Admin 2xx, alle anderen 403/401); nie freigebbare Netze (Link-local, Metadaten, 0/8, Multicast, Loopback als Netz/ohne Port) → 422; Eintrag wirkt beim nächsten Lauf ohne Neustart | mer-security §4/§7 |
| S11 | tester | Leak- und SSRF-Gesamtsuite | Echo-Server gibt URL, Header und Body zurück: `runs.output`, `runs.note`, API-Antworten (Liste, Detail, Lauf), Audit, Ausgabe von `scheduler:run`, `error_log` enthalten das Test-Geheimnis weder roh noch URL-kodiert noch base64; Weiterleitung mit Geheimnis in `Location`; curl-Fehler mit URL; SSRF-Ende-zu-Ende: privates Ziel → `failed` mit fester Notiz, ohne IP | alle |
| S12 | infra | `ext-curl` in `composer.json` (Plattform-Anforderung, keine Paketabhängigkeit), `deploy/install.sh` Voraussetzungen um `php-curl`, im Image `php -m \| grep curl` und CA-Bündel (`openssl_get_cert_locations()`) prüfen, Resolver-Optionen | Image-Build grün, HTTPS-Aufruf aus dem Scheduler-Container gegen ein öffentliches Ziel ok | – |
| U1 | frontend | Router mit Parametern, `request()` PUT/DELETE, Typen, Rechte-Helfer, Menü „Jobs“ | Build ohne CSP-Fehler; unbekannte Route → Übersicht | mer-ui §5 |
| U2 | frontend | Übersicht „Nächste Abfahrten“ + Jobliste (6) | Leerzustand, Beobachter sieht keinen „Anlegen“-Knopf, Handybreite | mer-ui |
| U3 | frontend | Job-Editor (6) | Geheimfelder nie vorbefüllt, nach Speichern geleert; Vorschau entprellt; Feldfehler sichtbar | mer-ui §3 |
| U4 | frontend | Job-Detail mit Verlauf, manuellem Lauf, Testlauf (6) | Ausgabe als Text (HTML im Body wird nicht gerendert), Abfrage endet nach Abschluss | mer-ui §4 |
| S13 | backend | `category:create <name>` (CLI, Audit) – nur falls O7 zugestimmt | Name gegen `^[A-Za-z0-9 _.-]{1,64}$`, doppelt → Fehler | mer-security §8 |
| R | sicherheit | Review Phase 3 nach mer-security §15 | alle Befunde behoben oder von Alex akzeptiert | – |

Abhängigkeiten: S1 → S3/S4 → S5 → S6 → S8; S7 vor S9; S2 vor S5; U1 nach S5, U3 nach S6, U4 nach S8/S9.
Parallel möglich: S3 ‖ S4, S7 ‖ S5/S6, U2 ‖ S6.

### 7.1 SSRF-Testfälle (Pflicht in S3/S9/S11)

Syntax abgelehnt: `ftp://…`, `file:///etc/passwd`, `gopher://…`, `http://user:pw@host/`, `http://a@b@c/`,
`http://host\@evil/`, `http://2130706433/`, `http://017700000001/`, `http://0x7f.0.0.1/`, `http://127.1/`,
`http://0/`, `http://[fe80::1%25eth0]/`, URL mit Leerzeichen, CR/LF, `#`, > 2048 Byte, Nicht-ASCII-Host.
Adresse gesperrt: `127.0.0.1`, `localhost`, `[::1]`, `0.0.0.0`, `10.0.0.1`, `172.16.0.1`, `192.168.1.1`,
`100.64.0.1`, `169.254.169.254`, `metadata.google.internal`, `[fe80::1]`, `[fd00::1]`, `[::ffff:127.0.0.1]`,
`[::ffff:7f00:1]`, `[64:ff9b::7f00:1]`, `[2002:7f00:1::]`, `224.0.0.1`, `255.255.255.255`, `198.18.0.1`.
DNS: Name → privat; Name → öffentlich **und** privat (ganz abgelehnt); unauflösbar; Rebinding (zweite
Auflösung privat – es zählt nur die gepinnte). Weiterleitung: auf privat, `https → http`, mehr als
`max_redirects`, `Location` mit Zugangsdaten oder fremdem Schema, fremder Ursprung ohne Header, 307 mit Body
auf fremden Ursprung. Freigabe: `192.168.1.0/24` erlaubt `.5`, nicht `192.168.2.5`; Port-Eintrag; nie
freigebbar: `169.254.169.254`, `0.0.0.0`. Umgebung: `HTTP_PROXY`/`HTTPS_PROXY` gesetzt → wird nicht benutzt.
Header `Host` → 422.

---

## 8. Offene Entscheidungen für Alex

| # | Frage | Empfehlung |
|---|---|---|
| O1 | Interne Ziele (privat, Loopback) standardmäßig gesperrt? | **Ja.** Freigabe nur durch Admin pro Netz/Port (E5). Auf dem NAS heißt das: interne Dienste einmal freigeben. |
| O2 | Darf der Host (Ziel) in Liste und Abfahrtstafel erscheinen? | **Ja, nur Schema + Host + Port.** Pfad und Query nie. Alternative: nur Job-Name, dann fehlt „ZIEL“ in der Tafel. |
| O3 | Bearbeiten-Ansicht zeigt die URL nicht (E2) – einverstanden? „Anzeigen mit Passwort“ später? | **Nicht anzeigen.** Erweiterung frühestens nach dem MVP. Der Klickdummy wird an dieser Stelle bewusst verlassen. |
| O4 | Anfrage nur als Ganzes ersetzen (E3)? | **Ja** für das MVP. |
| O5 | Header-Namen (ohne Werte) im Editor zeigen? | **Nein** im MVP (würde eine zweite Speicherstelle brauchen). |
| O6 | Zeitlimit höchstens 120 s, Standard 30 s, bis Läufe parallel laufen (Phase 4)? | **Ja.** |
| O7 | Kategorien anlegen: CLI `category:create` jetzt, Web in Phase 5? | **Ja**, sonst haben beschränkte Operatoren keine Kategorie, in der sie anlegen können. |
| O8 | Freigaben interner Ziele global oder je Kategorie? | **Global** im MVP; je Kategorie ist eine spätere Erweiterung der Tabelle. |
| O9 | „Antwort im Verlauf speichern“ standardmäßig an oder aus? | **Aus.** Antworten können personenbezogene Daten enthalten, und Beobachter sehen den Verlauf. |
| O10 | Testlauf für deaktivierte Jobs, manueller Lauf nur für aktive (E4)? | **Ja.** |
| O11 | `ext-curl` in `composer.json` aufnehmen (Plattform-Anforderung, kein Paket)? | **Ja.** |
| O12 | Ausgehender Proxy (`HTTP_PROXY`) wird ignoriert? | **Ja.** Proxy-Unterstützung später nur mit Admin-Recht, weil die SSRF-Prüfung dann den Proxy statt des Ziels sieht. |
| O13 | Eigene CA für interne HTTPS-Ziele (`MERIDIAN_HTTP_CA_FILE`)? | **Später.** Bis dahin interne Ziele per HTTP oder mit öffentlich gültigem Zertifikat. |

---

## 9. Sicherheitsprüfung des Entwurfs

| Frage | Antwort |
|---|---|
| Wo entstehen Geheimnisse? | Im Editor (Browser) und im Anfragekörper von `POST/PUT /api/jobs`. Sie werden validiert, sofort mit `SecretBox` verschlüsselt und nur als `payload_enc` gespeichert. Der Webprozess entschlüsselt nie (E3). |
| Wo werden sie entschlüsselt? | Nur im `HttpRunner` im Scheduler-Prozess, unmittelbar vor dem Lauf; sofort im Masker des Laufs registriert (E8). |
| Wo verlassen Daten das System? | (1) Die HTTP-Anfrage an das Ziel – nur nach SSRF-Prüfung, nur an gepinnte Adressen, Header/Body nie an fremde Ursprünge. (2) API-Antworten – Feld-Allowlist, `has_*`-Flags, Texte maskiert. (3) Verlauf (`runs.output/note`) – maskiert → gekürzt → gespeichert, beim Ausliefern erneut maskiert. (4) Audit – nur Feldnamen und „Anfrage ersetzt“. (5) Prozessausgabe des Schedulers – nur IDs und Status. (6) `error_log` – nur maskiert, Runner-Ausnahmen nie mit Meldung. |
| Wo wird geprüft, wer was darf? | Jeder Endpunkt: Sitzung → CSRF → `findVisible` (Scope) → `AccessControl::require()` mit gespeicherter Kategorie. Zusätzlich beim Übernehmen manueller/Test-/Wiederholungsläufe (H1). Freigaben interner Ziele: eigenes gefährliches Recht. |
| Neue Ausgabestellen und ihre Leak-Tests | Job-Liste, Job-Detail, Verlauf, Lauf-Detail, Vorschau-Fehler, Validierungsfehler, Audit-Ziele, Run-Notizen, Runner-Ausgabe, CLI `http:internal-targets` – alle in S5/S6/S9/S10/S11 mit Test-Geheimnis abgedeckt. |
