# 0003 – Phase 3: HTTP-Jobs inklusive Job-Bildschirmen

Status: **Entschieden** (Alex, 07.10.2026: O1–O13, mit Änderungen bei O3, O6, O8, O9; R1–R3 wie empfohlen,
siehe §8.2). Verfasser: architekt. Gilt für die Umsetzung von Phase 3 in `TODO.md`
(Schritte S1–S14, U1–U5, R).

Dieser Entwurf ändert keinen Produktcode. Interfaces und die Migration stehen hier als Skizze; die
umsetzenden Agenten übernehmen sie. Eine Datei unter `migrations/` legt erst S1 an — der `Migrator` würde
sonst alles dort sofort einspielen. Die Regeländerungen für `CLAUDE.md` und die Skills stehen in §10.

---

## 1. Ausgangslage

| Vorhanden | Folgerung für Phase 3 |
|---|---|
| `jobs` mit `config_json` (Klartext) und `payload_enc` (NOT NULL), `runs` mit `trigger` inkl. `manual`/`test`, `started_by`, `http_status`, `note`, `worker` | Keine neue Job-Spalte nötig. Neu: zwei Rechte, Tabelle `settings`, Tabelle `http_internal_targets`, zwei Indizes (Migration 0007). |
| `Runner::run(RunRequest, Heartbeat)`, `RunResult`, `RunnerRegistry`, `Worker` maskiert mit **einem** globalen `SecretMasker` | Schnittstelle bekommt einen Masker **pro Lauf** (H4). `RunResult` bekommt `retryable`. |
| `Planner::reschedule()` öffnet selbst `BEGIN IMMEDIATE`; `Connection` kann nicht verschachteln | Speichern setzt `next_run_at = NULL` in der Speicher-Transaktion, `reschedule()` läuft **danach** (H6). |
| `Kernel` kennt nur `get`/`post`, Pfadparameter nur bei `get` | Kernel um `put`/`delete` und Anforderungen bei `post` erweitern. |
| `RoleGrant` arbeitet mit Kategorie-**Namen**, `null` = ausdrücklich alle | Sichtbarkeit als Wertobjekt `CategoryScope` aus `AccessControl`, nie als leere Liste. |
| `JsonBody::object()` mit fester Tiefe 4 | Tiefe als Parameter (Job-Anfrage braucht 4–5). |
| Docker-Image `dunglas/frankenphp:1-php8.4-alpine` | Die offiziellen PHP-Images bauen `curl` fest ein (`--with-curl`); FrankenPHP baut darauf auf. Keine `install-php-extensions curl` nötig, aber prüfen (S12). Lokal: PHP 8.3.6 mit curl 8.5.0. |
| Worker führt Läufe **nacheinander** im Takt-Prozess aus | Lange HTTP-Läufe halten andere Läufe auf (E9). |
| Klickdummy „Takt“: Abfahrtstafel `ZEIT · JOB · GLEIS · ZIEL · STATUS`, Jobliste `JOB · ART · ZEITPLAN · LETZTER LAUF · NÄCHSTER · BESITZER`, Filter „Alle/HTTP/Shell/Mit Fehler“, Editor mit Methode, URL (`…/cron.php?key=••••••••`), Header, „Antwort im Verlauf speichern (max. 64 KB)“, Zeitlimit, Wiederholen, Überlappung, Cron-Presets, „NÄCHSTE LÄUFE · EUROPE/BERLIN“, Testlauf-Kasten | Begriffe und Aufbau übernehmen. Die maskierte URL im Editor kommt als serverseitig erzeugte Anzeige-Version (E2). |

---

## 2. Entscheidungen

### E1 – Was ist geheim, was nicht

| Wert | Ort | Begründung |
|---|---|---|
| URL (komplett) | `payload_enc` | Pfade tragen oft das Geheimnis selbst: Telegram `/bot<token>/…`, Slack-/Discord-Webhooks, healthchecks.io-UUID. |
| Header (Namen **und** Werte) | `payload_enc` | Werte sind typisch Tokens. Namen werden nicht angezeigt (O5). |
| Body | `payload_enc` | Kann Zugangsdaten enthalten. |
| Methode, Zeitlimit, erwartete Statuscodes, Weiterleitungen, Antwort speichern (`inherit/on/off`) | `config_json` | Steuern nur das Verhalten. |
| **Ziel** = Schema + Host + Port | `config_json.http.target` | Liste und Abfahrtstafel („ZIEL“), ohne zu entschlüsseln (O2). |
| **Anzeige-URL** (`display_url`) | `config_json.http.display_url` | Maskierte Darstellung für die Bearbeiten-Ansicht, beim Speichern serverseitig erzeugt (E2). Enthält nach Konstruktion keinen Geheimnisteil. |
| `has_headers`, `header_count`, `has_body` | `config_json.http` | Flags und Anzahl für die Antwort. |

`config_json` (Version 1, Klartext, **keine Geheimnisse**):

```json
{
  "v": 1,
  "http": {
    "method": "GET",
    "timeout_seconds": 30,
    "expected_status": [[200, 299]],
    "max_redirects": 3,
    "store_response": "inherit",
    "target": { "scheme": "https", "host": "img.deuba24.com", "port": 443 },
    "display_url": "https://img.deuba24.com/tools/framework/api/cron.php?key=••••",
    "display_v": 1,
    "has_headers": true,
    "header_count": 1,
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
Speichern als v2 geschrieben, oder über einen eigenen CLI-Befehl. `config_json.v` und `display_v` analog:
Ändert sich die Maskierungsregel, wird `display_url` nicht nachträglich „gelockert“ — alte Einträge behalten
die strengere Form oder werden beim nächsten Ersetzen neu erzeugt. Fehlt `v`, gilt der Datensatz als
ungültig (keine stillen Standardwerte).

Verworfen:
- *Getrennte Spalten `url_enc`, `headers_enc`, `body_enc`*: passt nicht zu Shell-Jobs (Phase 4: Skript),
  braucht eine Tabellen-Neuanlage, bringt nur Komfort beim teilweisen Ersetzen (siehe E3).
- *Basis-URL im Klartext, nur Query verschlüsselt (cron-job.org-Stil)*: Pfad-Tokens wären Klartext in DB.

### E2 – Bearbeiten-Ansicht zeigt eine maskierte Anzeige-URL (Entscheidung Alex, Ausnahme)

Alex hat entschieden: Die Bearbeiten-Ansicht zeigt die URL maskiert wie im Klickdummy. Das ist eine
ausdrückliche Ausnahme von „keine Geheimnisse in Antworten, auch nicht maskiert“. Sie gilt **nur** für das Feld
`display_url` und nur in der hier beschriebenen Form; für Header und Body bleibt es bei `has_*`/Anzahl.

**Sicher gemacht durch Konstruktion statt durch Maskieren:**

1. `display_url` wird **nur** beim Ersetzen der Anfrage erzeugt, von genau einer Funktion
   `UrlDisplay::fromParsed(ParsedUrl $url, DisplayPathMode $mode): string`, und in `config_json` gespeichert.
   Lesende Endpunkte geben den gespeicherten Text aus; sie bilden ihn nie aus der URL und entschlüsseln nie.
2. Es ist eine **Allowlist** (was sichtbar sein darf), keine Blocklist (was versteckt wird): Jeder Bestandteil, der
   die Regel nicht erfüllt, wird durch den festen Text `••••` ersetzt — unabhängig von seiner Länge. Leere und
   lange Werte sehen gleich aus; die Länge eines Geheimnisses ist nie ableitbar.
3. Sichtbar dürfen sein:
   - **Schema, Host, Port** (wie `target`, O2).
   - **Pfadsegmente**, nur im Modus `auto` und nur, wenn das Segment **alle** Bedingungen erfüllt:
     Regex `^[a-z]+(?:[-_][a-z]+){0,3}(?:\.(?:php|html?|aspx?|jsp|cgi|json|xml|txt))?$` (nur Kleinbuchstaben,
     höchstens vier Wortteile, optional eine Endung aus fester Liste), Länge ≤ 24, kein Wortteil länger als 16,
     keine Ziffer, kein Großbuchstabe, kein `%`, kein `;`, kein `:`, kein `=`, kein `~`, kein `@`.
     Alles andere → `••••`. Damit fallen Ziffern-/Base64-/Hex-/UUID-Tokens, Telegram `bot123:ABC…`,
     Slack `T…/B…/X…`, Discord-IDs und camelCase heraus.
     Im Modus `hidden` ist **jeder** Pfad `/••••` (ein fester Platzhalter, auch die Segmentzahl verschwindet).
   - **Query-Namen** (ab `display_v` 2, S11-Review) nach derselben Wortregel wie Pfadsegmente: nur Kleinbuchstaben,
     höchstens vier Wortteile mit `-`/`_`/`.`, Länge ≤ 24, Wortteil ≤ 16, **keine Ziffern**; sonst `••••`. Ein Teil
     **ohne `=`** ist selbst ein Wert und wird ganz `••••` (`?<token>` → `?••••`). **Query-Werte immer** `••••`, auch
     wenn sie leer sind (`a&b=` → `••••&b=••••`). Im Modus `hidden` wird die ganze Query `?••••`.
     v1 (`^[a-z][a-z0-9_.-]{0,31}$`, Teil ohne `=` als Name) ließ Hex-/base36-Tokens als Namen sichtbar. Gespeicherte
     v1-Anzeigen werden ohne Entschlüsseln verschärft: beim Lesen (`HttpJobConfig::fromJson()` →
     `UrlDisplay::upgradeStored()`) und dauerhaft bei jedem `migrate` (`Job\DisplayUrlUpgrade`). Aus `name=••••` mit
     nach v2 unsichtbarem Namen wird `••••=••••`; ein v1-Teil ohne `=` ist nicht mehr erkennbar und bleibt nur sichtbar,
     wenn er die v2-Wortregel erfüllt.
   - Userinfo und Fragment kommen nicht vor (die URL-Syntax lehnt `@` und `#` ab, §3.3).
4. Sichtbar bleibt bewusst die **Struktur**: Zahl der Pfadsegmente und Query-Parameter (nur in `auto`). Das ist
   keine Information über Geheimnis-Inhalte.
5. **Restrisiko** der Heuristik: ein Token, das nur aus Kleinbuchstaben besteht und kurz ist (z. B.
   `/hooks/abcdefghqrst`), erscheint im Modus `auto`. Gegenmittel: globale Admin-Einstellung
   `http.display_path = hidden` (§2 E10). Der Hinweis im Editor neben dem URL-Feld sagt das: „Teile der URL,
   die wie Geheimnisse aussehen, werden ausgeblendet. Reine Kleinbuchstaben-Wörter bleiben sichtbar — Tokens
   gehören in einen Header oder in den Query-Wert.“ Offene Frage R1 in §8.2.
6. `display_url` läuft vor der Ausgabe zusätzlich durch `SecretMasker::mask()` (Regel 4); die Muster ändern daran
   nichts, weil es nichts mehr zu maskieren gibt.

Antwort in der Bearbeiten-Ansicht: `display_url`, `has_headers`, `header_count`, `has_body`. Die Oberfläche
zeigt `https://img.deuba24.com/tools/framework/api/cron.php?key=••••` · „Header: 1 (Werte verborgen)“ ·
„Body: nein“ und „Anfrage ersetzen“.

**Leak-Tests für `UrlDisplay` (Pflicht, S3):**

| Fall (Test-Geheimnisse offensichtlich unecht) | Erwartung |
|---|---|
| Telegram `https://api.telegram.org/bot123456:TEST-ABCdef_ghi/sendMessage` | `https://api.telegram.org/••••/••••` |
| Slack-Format `https://hooks.slack.com/services/<T-ID>/<B-ID>/<24-Zeichen-Token>` (im Test **zur Laufzeit** aus Teilen zusammengesetzt, damit gitleaks und GitHub-Push-Protection nicht anschlagen) | `https://hooks.slack.com/services/••••/••••/••••` |
| Discord `https://discord.com/api/webhooks/123456789/TEST-token_abc` | `…/api/webhooks/••••/••••` |
| healthchecks `https://hc-ping.com/0a1b2c3d-0000-4000-8000-000000000000` | `https://hc-ping.com/••••` |
| Kurze Tokens mit Ziffer/Großbuchstabe: `/a1b2`, `/Ab`, `/x9` | `/••••` |
| Prozent-kodiert `/%74%6F%6B%65%6E`, Matrix `/seg;jsessionid=abc`, `/a:b`, `/~user` | `/••••` |
| Query `?key=TEST-SECRET-do-not-use-123&mode=` | `?key=••••&mode=••••` |
| Query-Name, der wie ein Token aussieht `?TEST1234=1` | `?••••=••••` |
| Kleinbuchstaben-Token `/hooks/abcdefghqrst` mit `display_path = hidden` | `/••••` (im Modus `auto` sichtbar — dokumentiertes Restrisiko) |
| Zwei URLs, die sich nur in der **Länge** eines Pfad-Tokens bzw. Query-Werts unterscheiden | identische `display_url` (Längen-Unabhängigkeit) |
| Für jeden Fall: alle geheimen Teile `g` (≥ 1 Zeichen bei Ziffer/Großbuchstabe, sonst ≥ 4) | `display_url` enthält weder `g` noch `rawurlencode(g)`, `urlencode(g)`, `base64_encode(g)` noch einen Teilstring von `g` mit ≥ 4 Zeichen, der nicht zugleich ein erlaubtes sichtbares Segment ist |
| Eigenschaftstest: 1000 zufällige Tokens aus `[A-Za-z0-9_-]{8,40}` mit mindestens einer Ziffer oder einem Großbuchstaben als Pfadsegment bzw. Query-Wert | nie sichtbar |
| Ende-zu-Ende: Job mit Test-Geheimnis in Pfad, Query, Header, Body anlegen | `GET /api/jobs/{id}`, Liste, Audit, `config_json` enthalten keines der Geheimnisse; `display_url` gleicht dem erwarteten Text exakt |

Verworfen: *URL gar nicht zeigen* (ursprüngliche Empfehlung, von Alex verworfen: Bedienbarkeit);
*maskierte URL bei jeder Anfrage aus dem entschlüsselten Payload bilden* (Entschlüsseln im Webprozess, jede
Lese-Anfrage berührt das Geheimnis); *Maskieren über `SecretMasker`-Muster* (Blocklist, Pfad-Tokens rutschen
durch, Länge bleibt sichtbar).

### E3 – Anfrage nur als Ganzes ersetzen (O4)

Beim Ändern ist `request` optional: fehlt es, bleiben URL, Header und Body unverändert; ist es da, ersetzt es
**alle drei** (fehlende Header = keine Header, fehlender Body = kein Body) und erzeugt `target`, `display_url`,
`header_count`, `has_*` neu.

Begründung: Die Web-API muss nie entschlüsseln (nur verschlüsseln), und gespeicherte Header lassen sich nicht auf
ein neues Ziel umlenken: Ein Operator, der den Token nie gesehen hat, könnte sonst die URL auf seinen Server
ändern und den gespeicherten `Authorization`-Header abgreifen.

Verworfen: *Teile einzeln ersetzen mit Entschlüsseln und Zusammenführen in der API*; *Teile einzeln
verschlüsselt in einem JSON-Container*.

Folge: Methode GET/HEAD bei gespeichertem Body → 422, außer die Anfrage wird im selben Schritt ersetzt.
Ändert der Admin `http.display_path`, bleiben gespeicherte `display_url` unverändert, bis die Anfrage ersetzt
wird — **außer** beim Wechsel auf `hidden`: Dann ersetzt S14 in derselben Transaktion bei allen HTTP-Jobs den
Pfad- und Query-Teil von `display_url` durch `/••••` bzw. `?••••` (geht ohne Entschlüsseln, weil nur verschärft
wird). Zurück auf `auto` lockert nichts nachträglich.

### E4 – Testlauf und manueller Lauf gehen über die Warteschlange (O10)

Beide legen einen Lauf (`trigger = test` bzw. `manual`, `started_by`, `scheduled_for = jetzt`) an und
antworten `202 {run_id}`. Der Scheduler-Prozess führt ihn im nächsten Takt (≤ 5 s) aus; die Oberfläche fragt
`GET /api/runs/{id}` ab.

- Testlauf nur für **gespeicherte** Jobs („Speichern und testen“).
- Testlauf auch für **deaktivierte** Jobs, manueller Lauf nur für aktive (409). Der `Worker` überspringt bisher
  jeden Lauf eines deaktivierten Jobs → Ausnahme für `trigger = test`.
- Begrenzung: je Job höchstens **ein** offener (`queued`/`running`) Lauf mit `trigger IN ('manual','test')`
  → sonst 409; je Benutzer höchstens 60 manuelle/Testläufe pro Stunde → sonst 429.
- Testläufe: nicht wiederholt, nicht in der Statistik, zählen nicht als „letzter Lauf“.

Verworfen: *synchroner Testlauf im Webprozess* (zweite Ausführungsstelle, lange Anfragen).

### E5 – Interne Ziele standardmäßig gesperrt, Freigaben global oder je Kategorie (O1, O8)

Meridian wird veröffentlicht und läuft auch in Unternehmensnetzen. Deshalb:

- Neues Recht `network.internal_targets` (`Permission::ManageInternalTargets`, `isDangerous() = true`, nur
  uneingeschränkt), per Migration nur der Rolle **Admin**.
- Tabelle `http_internal_targets` (§3.1). Jede Freigabe hat
  - **Art** `cidr` (Netz, z. B. `192.168.10.0/24`) oder `host` (exakter Hostname, z. B. `nextcloud` oder
    `intranet.firma.local`),
  - **Port** (0 = alle Ports),
  - **Geltungsbereich**: global (`category_id IS NULL`) oder genau eine Kategorie.
- **Auflösung pro Lauf:** Eine blockierte Adresse `ip` (Klasse „privat“ oder „Loopback“) ist erlaubt, wenn es
  eine Freigabe `f` gibt mit
  `(f.category_id IS NULL OR f.category_id = <Kategorie des Jobs aus der DB>)`
  **und** `(f.port = 0 OR f.port = <Port des aktuellen Hops>)`
  **und** `(f.kind = 'cidr' AND ip ∈ f.value OR f.kind = 'host' AND f.value = <Host des aktuellen Hops>)`.
  Ein Job ohne Kategorie bekommt nur globale Freigaben. Bei Weiterleitungen gilt die Prüfung für jeden Hop neu
  mit dessen Host und Port.
- **Host-Freigaben** geben nur die Klasse „privat“ frei (nicht Loopback): Der Name ist nur ein Schlüssel; alle
  aufgelösten Adressen müssen trotzdem privat sein und werden gepinnt. **Loopback** nur über eine `cidr`-Freigabe
  als einzelne Adresse (`/32`, `/128`) **mit** Port.
- **Nie** freigebbar (auch nicht per Host): Link-local inklusive Metadaten (`169.254.0.0/16`, `fe80::/10`),
  `0.0.0.0/8`, `::`, Multicast, Broadcast, Dokumentations-/Benchmark-/reservierte Netze und — ab Phase 4 — die
  Adressen des docker-socket-proxy (sonst wird ein HTTP-Job zum Shell-Job).
- **Kategorie gelöscht → ihre Freigaben werden gelöscht** (`ON DELETE CASCADE`), nie zu globalen
  (`SET NULL` würde die Freigabe erweitern). Ein Job, der in eine andere Kategorie verschoben wird, verliert die
  Freigaben der alten Kategorie beim nächsten Lauf.
- Pflege per API (`/api/settings/internal-targets`) und CLI (`http:internal-targets`), jeweils mit
  Audit-Eintrag; Oberfläche in U5.

Verworfen: *nur global* (in Unternehmensnetzen dürfte dann jeder Operator jeder Kategorie alle freigegebenen
internen Dienste ansprechen); *Freigabe je Job* (zu feingranular, Operatoren könnten sie nicht pflegen, und
das Recht dafür wäre faktisch `jobs.edit_http`); *Hostname-Freigaben ohne Adressprüfung* (DNS-Rebinding auf
Loopback/Metadaten).

### E6 – Sichtbarkeit: eine Funktion für Liste, Detail, Verlauf, Lauf

`AccessControl::scope(array $grants, Permission $p): CategoryScope` leitet den Bereich aus denselben
`RoleGrant::allows()`-Regeln ab wie `can()`. `CategoryScope` ist entweder ausdrücklich „alle“ oder eine Liste
von Kategorienamen (leer = nichts). Repositories bekommen den Scope und setzen **ein** SQL-Prädikat:

```sql
WHERE (:scope_all = 1 OR c.name IN (SELECT value FROM json_each(:scope_names)))
```

Jobs ohne Kategorie fallen bei beschränkten Rollen heraus. Detail, Verlauf und Lauf laden über dasselbe
Prädikat (`findVisible`) **und** rufen danach `AccessControl::require()` mit der gespeicherten Kategorie für das
konkrete Recht. Nicht sichtbar → 404; sichtbar, aber Recht fehlt → 403. Ein Eigenschaftstest prüft für alle
Rollen-/Kategorie-Kombinationen: `scope->contains(k) === can(…, k)`.

### E7 – HTTP-Runner: curl\_multi, gepinnte Adresse, eine Stelle für SSRF

Details in Abschnitt 5. Kurz: eigene DNS-Auflösung vor jeder Verbindung, Prüfung **aller** Adressen,
Festhalten per `CURLOPT_RESOLVE`, keine automatischen Weiterleitungen, Proxy-Umgebung ignoriert (O12), TLS
fest an, Herzschlag in der `curl_multi`-Schleife, Rohausgabe schon beim Lesen begrenzt. Eigene CA für interne
HTTPS-Ziele folgt später (O13).

### E8 – Masker pro Lauf (H4)

`Worker::execute()` erzeugt für jeden Lauf `new SecretMasker()` und reicht ihn an den Runner. Der Runner
registriert die entschlüsselten Teile darin; der Worker maskiert Ausgabe und Notiz mit **diesem** Masker und
verwirft ihn danach. Lesende API-Endpunkte maskieren zusätzlich mit einem frischen Masker (nur Muster).

### E9 – Zeitlimit je Job, globales Maximum durch den Admin (O6)

- Je Job `timeout_seconds`, Standard 30 s.
- Globales Maximum `http.max_timeout_seconds` (Einstellung, E10): Standard **300 s**, erlaubt 1–**3600 s**.
- Prüfung beim Speichern: `timeout_seconds` ≤ aktuelles Maximum, sonst 422 „Zeitlimit höchstens N s
  (Einstellung des Administrators).“ Senkt der Admin das Maximum später, nutzt der Runner
  `min(Job-Wert, Maximum)` und vermerkt das in der Notiz („Zeitlimit auf das Maximum von N s begrenzt.“).
  Bestehende Jobs werden nicht umgeschrieben (Admin-Einstellungen schreiben nie Job-Felder).
- **Warum 3600 s als technische Obergrenze:**
  1. Bis Phase 4 laufen Läufe nacheinander im Takt-Prozess. Während ein Lauf dauert, gibt es keinen Takt: der
     Planer legt keine neuen Läufe an, wartende Läufe starten nicht. Ein Termin, der dabei mehr als
     `Planner::MISSED_AFTER_SECONDS` (300 s) überfällig wird, gilt als **verpasst** (einmal nachholen bei
     `catch_up`, sonst `skipped`). Mehr als eine Stunde Stillstand pro Lauf ist kein sinnvoller Betrieb mehr.
  2. Gleiche Obergrenze wie `RetryPolicy::MAX_DELAY_SECONDS`; eine Stunde ist die längste Zeitspanne, die der
     Kern an anderer Stelle zulässt.
  3. Begrenzt den Schaden einer Fehleinstellung (ein Ziel, das nie antwortet).
- **Zusammenspiel mit Herzschlag und Sperre:** Die Sperre (`scheduler_lease`, 60 s) und der Lauf bleiben über
  `Heartbeat::beat()` lebendig — der Runner schlägt in der `curl_multi`-Schleife mindestens alle 20 s (gedrosselt
  schreibend alle 5 s). Ein langer Lauf wird deshalb **nicht** als hängend abgebrochen und kein zweiter
  Scheduler übernimmt. Nur wenn der Prozess abstürzt, veraltet der Herzschlag; nach 60 s setzt der nächste
  Inhaber den Lauf auf `aborted`.
- **Hinweis in der Oberfläche** (Editor, am Feld Zeitlimit, ab 60 s):
  „Bis Phase 4 laufen alle Jobs nacheinander. Ein Lauf mit langem Zeitlimit kann andere Läufe so lange
  aufhalten; Termine, die dabei mehr als 5 Minuten überfällig werden, gelten als verpasst.“
  In der Einstellungsseite (U5) am Maximum derselbe Hinweis.

### E10 – Globale Admin-Einstellungen (Tabelle `settings`)

- Schlüssel-Wert-Tabelle mit **Allowlist der Schlüssel** in Code **und** als `CHECK` in der DB. Werte als
  JSON-Skalar, je Schlüssel validiert. **Nie Geheimnisse** (Versand-Zugangsdaten u. ä. kommen in eigene
  `_enc`-Spalten, nicht hierher).
- Fehlt eine Zeile, gilt der Standardwert aus dem Code (kein Seeden beim Start, keine Zeile nötig).
- Neues Recht `settings.manage` (`Permission::ManageSettings`, `isDangerous() = true`), per Migration nur
  Admin. Begründung: Einstellungen lockern Schutz (Antworten speichern, Anzeige-Pfad, lange Zeitlimits) →
  eigenes Recht laut `mer-security` §4.
- Schreiben nur über die Admin-API mit CSRF und Audit-Eintrag `settings.changed` (`http.max_timeout_seconds:
  300 → 600`; Werte sind nicht geheim). Hintergrundprozesse lesen nur, schreiben nie.
- Der Runner liest die Einstellungen **bei jedem Lauf** frisch (Änderungen gelten ohne Neustart).

| Schlüssel | Typ / erlaubt | Standard | Wirkung |
|---|---|---|---|
| `http.max_timeout_seconds` | Ganzzahl 1–3600 | 300 | E9 |
| `http.response_storage` | `off` · `on` · `never` | `off` | E11 |
| `http.display_path` | `auto` · `hidden` | `hidden` (seit 09.10.2026, vorher `auto`; E14) | E2 |
| `http.display_host` | `auto` · `hidden` | `auto` (E16, Migration 0008) | E2 |

Verworfen: *Einstellungen als Umgebungsvariablen* (Admin ohne Shell-Zugang kann nichts ändern, kein Audit);
*eine Spalte je Einstellung in einer Ein-Zeilen-Tabelle* (jede neue Einstellung wäre eine Tabellen-Neuanlage).

### E11 – Antwort speichern: global und je Job (O9)

- Je Job `store_response`: `inherit` (Standard) · `on` · `off`.
- Global `http.response_storage`: `off` (Standard; `inherit` speichert nicht), `on` (`inherit` speichert),
  `never` (erzwingt aus — der Runner ignoriert den Job-Wert).
- Wirksam: `never` → nein; sonst Job-Wert `on`/`off`, bei `inherit` der globale Wert.
- Entschieden wird **im Runner bei jedem Lauf**; die API speichert nur den Job-Wunsch. Bei `never` zeigt der
  Editor das Feld deaktiviert mit „Vom Administrator abgeschaltet“.
- Gespeichert wird höchstens der maskierte, dann gekürzte Auszug (max. 64 KiB), nur Text-UTF-8. Ohne Speichern
  enthält die Ausgabe nur die Status-Zeilen (§5.3).

---

## 3. Datenmodell

### 3.1 Migration `0007_http_jobs.sql` (Entwurf, anzulegen in S1)

```sql
-- Phase 3: HTTP-Jobs.
-- Zwei neue Rechte, beide lockern Schutzfunktionen: nur für die Rolle Admin
-- (neue Rechte nie automatisch an Operator/Beobachter).
INSERT INTO role_permissions (role_id, permission)
    SELECT r.id, p.permission
      FROM roles r
      JOIN (SELECT 'network.internal_targets' AS permission UNION ALL SELECT 'settings.manage') p
     WHERE r.name = 'Admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = p.permission);

-- Globale Einstellungen. Nur bekannte Schlüssel; fehlt eine Zeile, gilt der Standard aus dem Code.
-- Nie Geheimnisse.
CREATE TABLE settings (
    key        TEXT    PRIMARY KEY CHECK (key IN ('http.max_timeout_seconds', 'http.response_storage', 'http.display_path')),
    value_json TEXT    NOT NULL CHECK (json_valid(value_json)),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT    NOT NULL
);

-- Freigaben interner Ziele für HTTP-Jobs.
--   kind = 'cidr': value ist ein normalisiertes Netz (192.168.1.0/24, fd12:3456::/48)
--   kind = 'host': value ist ein Hostname in Kleinbuchstaben (nextcloud, intranet.firma.local)
--   port = 0 heißt „alle Ports“.
--   category_id NULL = global; sonst nur für Jobs dieser Kategorie. Kategorie gelöscht -> Freigabe gelöscht
--   (CASCADE), nie zu einer globalen erweitert.
CREATE TABLE http_internal_targets (
    id          INTEGER PRIMARY KEY,
    kind        TEXT    NOT NULL CHECK (kind IN ('cidr', 'host')),
    value       TEXT    NOT NULL CHECK (length(value) BETWEEN 1 AND 253),
    port        INTEGER NOT NULL DEFAULT 0 CHECK (port BETWEEN 0 AND 65535),
    category_id INTEGER REFERENCES categories(id) ON DELETE CASCADE,
    note        TEXT    NOT NULL DEFAULT '' CHECK (length(note) <= 200),
    created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at  TEXT    NOT NULL
);
-- NULL ist in UNIQUE nie gleich: eindeutig über COALESCE.
CREATE UNIQUE INDEX http_internal_targets_unique ON http_internal_targets (kind, value, port, COALESCE(category_id, 0));
CREATE INDEX http_internal_targets_category ON http_internal_targets (category_id);

-- Liste nach Kategorie kappen, letzter Lauf je Job.
CREATE INDEX jobs_category ON jobs (category_id);
CREATE INDEX runs_job_id ON runs (job_id, id DESC);
```

Keine Änderung an `jobs`: `config_json` und `payload_enc` reichen (E1). `ON DELETE CASCADE` von `runs` auf
`jobs` bleibt: Job löschen löscht seinen Verlauf (Audit-Eintrag bleibt). `ON DELETE SET NULL` der Kategorie bei
`jobs` macht Jobs nur noch für uneingeschränkte Rollen sichtbar und nimmt ihnen die Kategorie-Freigaben – das
erweitert keine Rechte.

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
| `is_enabled`, `catch_up` | bool (JSON `true`/`false`, keine Zahlen) |
| `overlap_policy` | `skip` · `parallel` · `queue` |
| `retry_count` | 0–`RetryPolicy::MAX_RETRIES` (10) |
| `retry_delay_seconds` | 1–3600 |
| `http.method` | `GET` · `POST` · `PUT` · `PATCH` · `DELETE` · `HEAD` |
| `http.timeout_seconds` | 1 bis `http.max_timeout_seconds` (E9), Standard 30 |
| `http.expected_status` | Text `^[1-5][0-9]{2}(-[1-5][0-9]{2})?(,[1-5][0-9]{2}(-[1-5][0-9]{2})?){0,19}$`, je Bereich von ≤ bis; gespeichert als `[[von, bis], …]`; Standard `200-299` |
| `http.max_redirects` | 0–5, Standard 3 |
| `http.store_response` | `inherit` · `on` · `off`, Standard `inherit` |
| `request.url` | siehe 3.3 |
| `request.headers` | ≤ 30 Einträge `{name, value}`; Name RFC-7230-Token `^[!#$%&'*+.^_\`\|~0-9A-Za-z-]{1,64}$`; Wert ≤ 4096 Byte, nur `\x20-\x7E` und UTF-8 ohne CR/LF/NUL, kein Leerraum am Rand; Summe ≤ 16 KiB; verboten (Groß/klein egal): `Host`, `Content-Length`, `Transfer-Encoding`, `Connection`, `Upgrade`, `TE`, `Trailer`, `Keep-Alive`, `Expect`, `Proxy-*` |
| `request.body` | `null` oder Text ≤ 64 KiB, gültiges UTF-8; nur bei POST/PUT/PATCH/DELETE |
| Anfragekörper gesamt | ≤ 160 KiB, JSON-Tiefe ≤ 5 |
| Einstellungen | je Schlüssel laut Tabelle E10; unbekannter Schlüssel → 404; falscher Typ → 422 |
| Freigabe `kind`/`value` | `cidr`: gültiges Netz, auf Netzadresse normalisiert (Hostbits gesetzt → 422 statt still kürzen); IPv4-Präfix 8–32, IPv6 16–128; muss vollständig in „privat“ oder (als `/32`/`/128` mit Port ≠ 0) „Loopback“ liegen; `host`: Hostname-Regel aus 3.3, nicht `localhost`/`*.localhost`/Metadaten-Namen |
| Freigabe `port`, `category_id`, `note` | 0–65535; bestehende Kategorie oder `null`; ≤ 200 Zeichen ohne Steuerzeichen |

### 3.3 URL-Syntax (`UrlPolicy`, gleiche Prüfung beim Speichern und im Runner)

Ziel: PHP (`parse_url`) und curl dürfen die URL nicht unterschiedlich lesen.

- ≤ 2048 Byte, nur druckbares ASCII ohne Leerzeichen, ohne `\`, ohne `#` (Fragment wird nie gesendet:
  „Bitte den Teil ab # entfernen.“), ohne `@` in der Authority (Zugangsdaten → Header `Authorization`).
- Schema `http` oder `https` (klein geschrieben gespeichert).
- Host: entweder IPv4 in Punktschreibweise ohne führende Nullen, oder IPv6 in eckigen Klammern
  (`FILTER_VALIDATE_IP` + `FILTER_FLAG_IPV6`, ohne Zonen-ID `%`), oder ein DNS-Name: Kleinbuchstaben/Ziffern/
  Bindestrich, Labels 1–63, gesamt ≤ 253, letztes Label nicht rein numerisch, kein Punkt am Ende, keine
  Nicht-ASCII-Zeichen (IDN als Punycode `xn--…`). Dadurch scheitern `http://2130706433/`, `http://0x7f.1/`,
  `http://127.1/`, `http://0/` schon an der Syntax.
  Zusätzlich (S11, strenger): kein Label in Hex-Form mit `0x`-Präfix (`0x7f`, `0x`) und keines in Oktalform
  (führende 0, nur Ziffern), damit kein IPv4-Parser einen Namen als Adresse liest: `http://0x7f000001/`,
  `http://0x7f.0x0.0x0.0x1/`, `http://0177.0.0.0x1/` → 422. Erlaubt bleiben `a1b2.example.com`, `x0.example.com`,
  `1.cdn.example.com`.
- Namen `localhost`, `*.localhost`, `metadata.google.internal`, `metadata` → gesperrt, unabhängig von DNS.
- Port 1–65535, optional.
- Pfad und Query: erlaubte Zeichen RFC 3986 (`A-Za-z0-9-._~!$&'()*+,;=:@/?%` mit gültigen `%XX`; `@` nur
  nach der Authority).
- Beim **Speichern** wird nicht aufgelöst (keine DNS-Abfrage aus dem Webprozess). IP-Literale werden schon
  beim Speichern gegen die Sperrnetze **und** die Freigaben für die Ziel-Kategorie geprüft (sofortige
  Rückmeldung). Die verbindliche Prüfung macht der Runner bei jedem Lauf.

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

Kernel-Erweiterung: `put()`, `delete()`, Anforderungen bei `post()`; Pfadparameter `{id}` mit `[1-9][0-9]{0,17}`,
`{key}` mit `[a-z][a-z0-9_.]{0,63}`. Eine Ausnahme `ValidationFailed(field, message)` wird im Kernel zu 422.

### 4.2 Endpunkte und Rechte

„Op“ = Operator uneingeschränkt, „Op/A“ = Operator nur Kategorie A. Job X liegt in B, Job N hat keine Kategorie.

| Methode und Pfad | Recht (Kategorie aus der DB) | Admin | Op | Op/A auf A · auf B/N | Beobachter | anonym |
|---|---|---|---|---|---|---|
| `GET /api/jobs` | `jobs.view` (Scope) | alle | alle | nur A | alle | 401 |
| `GET /api/jobs/{id}` | `jobs.view` | 200 | 200 | 200 · 404 | 200 | 401 |
| `POST /api/jobs` | `jobs.edit_http` in der **Zielkategorie** | 201 | 201 | 201 · 422 | 403 | 401 |
| `PUT /api/jobs/{id}` | `jobs.edit_http` in alter **und** neuer Kategorie | 200 | 200 | 200 · 404 (B→A: 404; A→B: 422) | 403 | 401 |
| `DELETE /api/jobs/{id}` | `jobs.edit_http` | 204 | 204 | 204 · 404 | 403 | 401 |
| `POST /api/jobs/{id}/enable` · `/disable` | `jobs.edit_http` | 200 | 200 | 200 · 404 | 403 | 401 |
| `POST /api/jobs/{id}/run` | `jobs.run` | 202 | 202 | 202 · 404 | 403 | 401 |
| `POST /api/jobs/{id}/test` | `jobs.run` | 202 | 202 | 202 · 404 | 403 | 401 |
| `GET /api/jobs/{id}/runs` | `jobs.view` | 200 | 200 | 200 · 404 | 200 | 401 |
| `GET /api/runs/{id}` | `jobs.view` (Kategorie des Jobs) | 200 | 200 | 200 · 404 | 200 | 401 |
| `GET /api/schedule/preview` | `jobs.view` in mindestens einer Kategorie | 200 | 200 | 200 | 200 | 401 |
| `GET /api/jobs/limits` | `jobs.view` in mindestens einer Kategorie | 200 | 200 | 200 | 200 | 401 |
| `GET /api/categories?permission=jobs.view\|jobs.edit_http` | angefragtes Recht (Scope); andere Werte → 422 | alle | alle | nur A | view: alle, edit: leer | 401 |
| `GET /api/settings` | `settings.manage` | 200 | 403 | 403 | 403 | 401 |
| `PUT /api/settings/{key}` | `settings.manage` | 200 | 403 | 403 | 403 | 401 |
| `GET /api/settings/internal-targets` | `network.internal_targets` | 200 | 403 | 403 | 403 | 401 |
| `POST /api/settings/internal-targets` | `network.internal_targets` | 201 | 403 | 403 | 403 | 401 |
| `DELETE /api/settings/internal-targets/{id}` | `network.internal_targets` | 204 | 403 | 403 | 403 | 401 |

Beide Admin-Rechte sind gefährlich (`isDangerous`): auch eine Admin-Rolle, die auf Kategorien beschränkt ist,
bekommt sie nicht. Shell-Jobs (`type = shell`) prüfen dort, wo oben `jobs.edit_http` steht, `jobs.edit_shell`
(Phase 4). Ein Wechsel des Typs beim Ändern ist verboten (422).

### 4.3 Antworten

Job (Liste ohne `http`-Details außer `target`; Detail vollständig):

```json
{
  "id": 12, "name": "Hub", "type": "http",
  "category": { "id": 2, "name": "Deuba24" },
  "owner": { "id": 1, "display_name": "Alex" },
  "cron": "* * * * *", "timezone": "Europe/Berlin", "next_run_at": "2026-10-07T13:27:00+00:00",
  "is_enabled": true, "overlap_policy": "skip", "retry_count": 0, "retry_delay_seconds": 60, "catch_up": false,
  "http": {
    "method": "GET", "timeout_seconds": 30, "expected_status": "200-299", "max_redirects": 3,
    "store_response": "inherit", "target": "https://img.deuba24.com",
    "has_request": true,
    "display_url": "https://img.deuba24.com/tools/framework/api/cron.php?key=••••",
    "has_url": true, "has_headers": true, "header_count": 1, "has_body": false
  },
  "last_run": { "id": 991, "status": "ok", "trigger": "schedule", "finished_at": "…", "duration_ms": 2210, "http_status": 200 },
  "running": false,
  "can": { "edit": true, "run": true },
  "created_at": "…", "updated_at": "…"
}
```

- `target` = Schema + Host (+ Port, wenn nicht Standard); die Liste und die Abfahrtstafel zeigen nur den Host (O2).
- `display_url` nur im **Detail** (nicht in der Liste), gespeicherter Text aus E2, beim Lesen auf die aktuellen
  Einstellungen verschärft (E14, E16). `display_url`, `has_url`, `has_headers`, `header_count` und `has_body` nur für
  Benutzer mit `can.edit` (E15); alle anderen bekommen `target` und `has_request: true`.
- Bei `http.display_host = hidden` ist `target` überall `https://••••` bzw. `http://••••` (E16).
- `can` ist Komfort für die Oberfläche; der Server prüft trotzdem.
- `last_run`: letzter abgeschlossener Lauf (`ok/failed/timeout/aborted`) ohne Testläufe; `running`: irgendein
  Lauf `running`.
- Liste: höchstens 500 Jobs, `{"jobs": […], "truncated": bool}`; Filter `type`, `category_id`,
  `sort=name|next_run`, `enabled=1` sind Anfragen innerhalb des Scopes.
- `GET /api/jobs/limits` → `{"max_timeout_seconds": 300, "response_storage": "off"}` (für Editor-Hinweise; nur
  diese zwei Werte, keine weiteren Einstellungen).

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
- Vorschau: `GET /api/schedule/preview?cron=…&timezone=…&count=1..10` → `{"runs": ["2026-10-07T15:27:00+02:00", …]}`
  in der Zeitzone des Jobs. Fehler 422 mit fester Meldung. Cron ist kein Geheimnis, deshalb darf er in die Query.

Einstellungen:

- `GET /api/settings` → `{"settings": [{"key": "http.max_timeout_seconds", "value": 300, "default": 300,
  "updated_at": "…"|null, "updated_by": {"display_name": "Alex"}|null}, …]}` (alle Schlüssel der Allowlist, auch
  ohne Zeile).
- `PUT /api/settings/{key}` mit `{"value": …}` → neuer Eintrag; `{"value": null}` setzt auf den Standard zurück
  (Zeile löschen).
- `GET /api/settings/internal-targets` → `{"targets": [{"id", "kind", "value", "port", "category": {id,name}|null,
  "note", "created_at", "created_by": {display_name}|null}]}`; `POST` mit `{kind, value, port, category_id, note}`.

### 4.4 Audit-Einträge (`target` nie mit Geheimnissen; der AuditLog maskiert zusätzlich)

| Aktion | `target` |
|---|---|
| `job.created` | `job:12 Hub` |
| `job.updated` | `job:12 Hub; geändert: cron, http.method[, Kategorie Deuba24 → NAS][, Anfrage ersetzt]` (nur Feldnamen; nie `display_url`) |
| `job.deleted` | `job:12 Hub` |
| `job.enabled` / `job.disabled` | `job:12 Hub` |
| `job.run_manual` / `job.run_test` | `job:12 Hub; run:991` |
| `settings.changed` | `http.max_timeout_seconds: 300 → 600` (Werte sind laut Allowlist nie geheim) |
| `network.internal_target_added` / `_removed` | `cidr 192.168.1.0/24:8080 (Kategorie NAS)` bzw. `host nextcloud:* (global)` |

Abgelehnte Rechteprüfungen werden nicht protokolliert.

### 4.5 H1, H2, H3, H6

- **H1** Beim Einreihen: `require(jobs.run, gespeicherte Kategorie)` + Audit. Im `Worker::claim()`: für
  `trigger IN ('manual','test')` und für `retry` mit `started_by IS NOT NULL` fragt `RunAuthorizer::mayStart(userId,
  jobId)` (frische Grants, `is_active`, Kategorie aus der DB). Nein oder `started_by IS NULL` bei manual/test →
  `skipped` mit Notiz „Übersprungen: Der auslösende Benutzer darf diesen Job nicht mehr starten.“
  `scheduleRetry()` übernimmt `started_by`.
- **H2** siehe 4.3.
- **H3** Runner begrenzt beim Lesen (5.4); API kodiert mit `JSON_INVALID_UTF8_SUBSTITUTE`; der Runner speichert
  Nicht-UTF-8-Antworten nicht (Binär-Vermerk).
- **H6** Speichern (Anlegen; Ändern mit geändertem `cron`/`timezone`/`is_enabled`; Aktivieren) setzt in derselben
  Transaktion `next_run_at = NULL`; nach dem Commit ruft der Endpunkt `Planner::reschedule($id)`. Deaktivieren
  setzt `is_enabled = 0, next_run_at = NULL` in einer Anweisung.

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

namespace Meridian\Settings;

enum SettingKey: string { case HttpMaxTimeout = 'http.max_timeout_seconds'; case HttpResponseStorage = 'http.response_storage'; case HttpDisplayPath = 'http.display_path'; case HttpDisplayHost = 'http.display_host'; /* 0008, E16 */ }
final class Settings        // liest/validiert; Standardwerte im Code; schreibt nur über set() aus der Admin-API
{
    public function maxTimeoutSeconds(): int;          // 1..3600
    public function responseStorage(): ResponseStorage; // enum off|on|never
    public function displayPath(): DisplayPathMode;     // enum auto|hidden
    public function set(SettingKey $key, mixed $value, int $userId): void;  // validiert, Audit macht der Controller
}

namespace Meridian\Runner\Http;

final class HttpPayload            // __debugInfo ohne Werte, __serialize wirft, Konstruktor #[\SensitiveParameter]
{
    public static function fromJson(#[\SensitiveParameter] string $json): self;   // nur v=1
    public function toJson(): string;
    public function registerIn(SecretMasker $masker): void;                      // siehe 5.3
}
final readonly class HttpJobConfig { /* method, timeoutSeconds, expectedStatus, maxRedirects, storeResponse,
                                        target, displayUrl, hasHeaders, headerCount, hasBody */ }
final class UrlPolicy      { public function parse(#[\SensitiveParameter] string $url): ParsedUrl; }  // 3.3
final readonly class ParsedUrl { /* scheme, host, port, isIpLiteral; origin(): string */ }
final class UrlDisplay     { public static function fromParsed(#[\SensitiveParameter] ParsedUrl $url, DisplayPathMode $mode): string; } // E2, einzige Stelle
interface HostResolver     { /** @return list<string> */ public function resolve(string $host): array; }
final class SystemHostResolver implements HostResolver   // gethostbynamel() + dns_get_record(DNS_AAAA)
final class AddressPolicy  { public function check(string $ip, string $host, int $port, ?int $categoryId): ?BlockReason; } // null = erlaubt
enum BlockReason: string   { case Private; case Loopback; case LinkLocal; case Reserved; case Multicast; case Infrastructure; }
final class TargetGuard    { public function pin(ParsedUrl $url, ?int $categoryId): PinnedTarget; }  // wirft TargetBlocked/TargetUnresolvable
final readonly class PinnedTarget { public ParsedUrl $url; /** @var list<string> */ public array $ips; }
interface HttpTransport    { public function send(TransportRequest $r, Heartbeat $h): TransportResponse; }  // genau ein Hop
final class CurlTransport implements HttpTransport
final class HttpRunner implements Runner
interface HttpJobSource    { public function load(int $jobId): ?StoredHttpJob; }   // config_json, payload_enc, category_id; schreibt nie
```

`AddressPolicy` lädt die Freigaben bei jedem Lauf frisch aus `http_internal_targets`, gefiltert auf
`category_id IS NULL OR category_id = :job_category`. Prüfung über `inet_pton` und Bitmasken, ohne Abhängigkeit.

### 5.2 Gesperrte Adressen

IPv4: `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`,
`192.0.0.0/24`, `192.0.2.0/24`, `192.88.99.0/24`, `192.168.0.0/16`, `198.18.0.0/15`, `198.51.100.0/24`,
`203.0.113.0/24`, `224.0.0.0/4`, `240.0.0.0/4` (inkl. `255.255.255.255`).
IPv6: `::/128`, `::1/128`, `::ffff:0:0/96` und `64:ff9b::/96` (eingebettete IPv4 prüfen), `2002::/16`
(6to4: eingebettete IPv4 prüfen), `100::/64`, `2001::/23`, `2001:db8::/32`, `fc00::/7`, `fe80::/10`,
`fec0::/10`, `ff00::/8`.
Metadaten-Dienste innerhalb privater Netze stehen **davor** und sind nie freigebbar (Klasse Link-local, S11-Review):
`100.100.100.200/32` (Alibaba Cloud, in `100.64/10`) und `fd00:ec2::254/128` (AWS IMDS IPv6, in `fc00::/7`). Eine
Freigabe genau dieser Adressen wird abgelehnt; ein größeres privates Netz bleibt freigebbar, gibt sie aber nie frei.
Freigebbar laut E5 nur „privat“ (`cidr` oder `host`) und „Loopback“ (nur `cidr` einzeln, mit Port). Hat ein Name
mehrere Adressen, muss **jede** erlaubt sein, sonst wird abgelehnt. Keine Adresse → `failed` „Ziel nicht
auflösbar: Hostname prüfen.“

### 5.3 Ablauf eines Laufs

1. `HttpJobSource::load()`; fehlt der Job oder ist `type ≠ http` → `failed`, nicht wiederholbar.
   Einstellungen frisch lesen: Zeitlimit = `min(Job, http.max_timeout_seconds)`; Antwort speichern nach E11.
2. `beat()`. `payload_enc` entschlüsseln, `HttpPayload::fromJson()`. Fehler → feste Notiz „Gespeicherte
   Anfrage nicht lesbar (Schlüssel oder Format). Anfrage im Job neu eingeben.“, nicht wiederholbar.
3. **Sofort** `registerIn($masker)`: ganze URL; URL ohne Query; jeder Query-Wert (roh und dekodiert);
   Pfadsegmente ab 8 Zeichen, die die Sichtbarkeitsregel aus E2 **nicht** erfüllen; jeder Header-Wert, bei
   `Bearer x`/`Basic x` zusätzlich `x` und bei Basic die dekodierten Teile; Body komplett, bei JSON jeder
   Text-Blattwert ab 8 Zeichen, bei `application/x-www-form-urlencoded` jeder Wert.
4. `UrlPolicy::parse()` (erneut, auch für Altbestände).
5. Für Hop 0 … `max_redirects`: `beat()` → `TargetGuard::pin(url, Kategorie des Jobs)` → `beat()` →
   `HttpTransport::send()` mit der **verbleibenden** Gesamtzeit (das Zeitlimit gilt für alle Hops zusammen).
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
   …nur wenn Speichern wirksam (E11) und Text-UTF-8, sonst „[Binärinhalt, 12 345 B, nicht gespeichert]“
   ```
   Nie: Pfad, Query, Header-Werte, Antwort-Header außer Größe/Typ. (Die Ausgabe nutzt `target`, nicht
   `display_url`.)

Fehlerzuordnung (feste Texte, nie `curl_error()`; die Meldung enthält die URL):

| Fall | Status | wiederholbar |
|---|---|---|
| Zeitlimit (`CURLE_OPERATION_TIMEDOUT`) | `timeout` „Zeitlimit von 30 s überschritten.“ | ja |
| DNS ohne Ergebnis | `failed` „Ziel nicht auflösbar …“ | ja |
| Verbindung abgelehnt/nicht erreichbar | `failed` „Verbindung fehlgeschlagen …“ | ja |
| TLS (`CURLE_PEER_FAILED_VERIFICATION`, `SSL_*`) | `failed` „TLS-Prüfung fehlgeschlagen: Zertifikat ungültig, abgelaufen oder für einen anderen Namen.“ | nein |
| Ziel gesperrt (5.2) | `failed` „Ziel gesperrt: Die Adresse liegt in einem internen oder reservierten Netz. Ein Admin kann interne Ziele freigeben (global oder für die Kategorie des Jobs).“ (ohne IP) | nein |
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
| `CURLOPT_PROXY` `''`, `CURLOPT_NOPROXY` `'*'` | Proxy-Umgebungsvariablen ignorieren (O12) |
| `CURLOPT_SSL_VERIFYPEER` / `VERIFYHOST` | `true` / `2`, nie schaltbar |
| `CURLOPT_CONNECTTIMEOUT_MS` / `TIMEOUT_MS` | min(10 s, Rest) / Rest der Gesamtzeit |
| `CURLOPT_FRESH_CONNECT`, `CURLOPT_FORBID_REUSE` | `true` |
| `CURLOPT_NOSIGNAL` | `true` |
| `CURLOPT_HEADERFUNCTION` | Statuszeile, `Location`, `Content-Type`, `Content-Length` merken; Header gesamt ≤ 32 KiB, sonst abbrechen |
| `CURLOPT_WRITEFUNCTION` | erste 80 KiB behalten (64 KiB Ausgabe + Überhang fürs Maskieren), Rest nur zählen; > 10 MiB abbrechen (H3). Der Schnitt liegt vor dem Maskieren: `HttpRunner` ruft für einen gekürzten Body `SecretMasker::maskCut()` (maskieren, dann ein Textende entfernen, das Anfang eines bekannten Geheimnisses ist) |
| `CURLOPT_ACCEPT_ENCODING` | nicht setzen (keine Entpack-Bombe) |
| Cookies, `CURLOPT_UNRESTRICTED_AUTH` | aus |
| `CURLOPT_USERAGENT` | `Meridian/<version>` |
| HEAD | `CURLOPT_NOBODY` |

Schleife: `curl_multi_exec` + `curl_multi_select($mh, 1.0)`; nach jedem Durchgang `beat()` (drosselt selbst
auf 5 s). DNS (`gethostbynamel`) blockiert ohne eigenes Zeitlimit; `beat()` direkt davor und danach, Resolver
im Container mit `options timeout:2 attempts:2` (Restrisiko in R prüfen).

### 5.5 Verdrahtung

`bin/meridian`: `scheduler:run` lädt den Schlüssel über `KeyLoader::load($env)` erst, wenn der Befehl
ausgeführt wird, und registriert
`JobType::Http → new HttpRunner(new DbHttpJobSource($db), $box, new Settings($db), new UrlPolicy(), new TargetGuard(new SystemHostResolver(), new DbAddressPolicy($db)), new CurlTransport())`.
Ohne lesbaren Schlüssel endet der Befehl mit Exit 1 und der Meldung von `KeyLoader`.

---

## 6. Oberfläche (Phase-3-Scheiben)

Gemeinsam: Hash-Router mit Parametern (`#/jobs`, `#/jobs/neu`, `#/jobs/12`, `#/jobs/12/bearbeiten`,
`#/einstellungen`), `request()` mit PUT/DELETE, Typen für Job/Lauf/Einstellung, `lib/permissions.ts` um
`canEditHttp(profile, kategorie)`, `canRun(…)`, `canManageSettings`, `canManageInternalTargets` (nur Komfort).
Menüpunkt „Jobs“ aktiv, „Einstellungen“ nur bei einem der beiden Admin-Rechte, „Verlauf“ bleibt „bald“.
Keine neuen npm-Pakete.

| Scheibe | Inhalt | Daten |
|---|---|---|
| **U2 Übersicht „Nächste Abfahrten“** | Tafel wie Klickdummy: ZEIT · JOB · GLEIS (`HTTP`) · ZIEL (nur Host) · STATUS (`läuft` in Signalgelb mit `pulse`, `pünktlich`, `letzter Lauf gestört`, `deaktiviert`). Leerzustand bleibt. Neu laden alle 30 s. Taktband und „Heute bisher“ folgen in Phase 5. | `GET /api/jobs?sort=next_run&enabled=1` (erste 12) |
| **U2 Jobliste** | Spalten JOB (+ Host) · ART · ZEITPLAN (Preset-Name oder Cron) · LETZTER LAUF · NÄCHSTER · BESITZER; Filter-Chips „Alle/HTTP/Shell/Mit Fehler“ mit Zahlen; „Neuen Job anlegen“ nur bei `canEditHttp`; Hinweis „Du siehst nur Jobs deiner Kategorien“, wenn beschränkt. | `GET /api/jobs` |
| **U3 Job anlegen/bearbeiten** | Titel; Art (nur „Adresse aufrufen“, „Befehl ausführen“ deaktiviert mit „folgt“); Methode; Bereich **Anfrage**: neu = Felder URL (`autocomplete="off"`, `spellcheck=false`, Hinweis aus E2 Punkt 5), Header-Zeilen, Body (nur POST/PUT/PATCH/DELETE); bearbeiten = `display_url` als Text (JetBrains Mono, nicht editierbar, nicht als Link) · „Header: N (Werte verborgen)“ · „Body: ja/nein“ + „Anfrage ersetzen“ (öffnet **leere** Felder, Hinweis „URL, Header und Body werden zusammen ersetzt“, „Abbrechen“ behält die alte). Zeitplan: Presets + Cron-Feld, Vorschau nach 400 ms Pause, Kasten „NÄCHSTE LÄUFE · <ZEITZONE>“; Kategorie; Zeitzone; Zeitlimit (Obergrenze aus `/api/jobs/limits`, Hinweis aus E9 ab 60 s); erwartete Statuscodes; Weiterleitungen; Wiederholen; Überlappung; Nachholen; „Antwort im Verlauf speichern (max. 64 KB)“ als Auswahl „Wie global (aus/an)“/„An“/„Aus“, bei `never` deaktiviert mit „Vom Administrator abgeschaltet“. Knöpfe „Speichern“, „Speichern und testen“, „Löschen“ (Bestätigung). 422-Meldungen am Feld. Geheimfelder nach dem Speichern aus dem Zustand löschen. | `POST/PUT/DELETE /api/jobs…`, `/api/categories?permission=jobs.edit_http`, `/api/jobs/limits` |
| **U4 Job-Detail** | Kopf mit Name, Host, Zeitplan, Aktiv-Schalter, „Jetzt ausführen“ (nur aktiv), „Testlauf“; Verlauf (Liste, „Mehr laden“), Lauf auswählen → Notiz und Ausgabe als `<pre>{text}</pre>`; ausgelöster Lauf wird alle 2 s abgefragt (höchstens Zeitlimit + 60 s, danach „läuft noch – später nachsehen“). Testlauf-Kasten „… Der Testlauf zählt nicht in die Statistik.“ 409/429 als Text. | `/api/jobs/{id}`, `/runs`, `/api/runs/{id}`, `/run`, `/test` |
| **U5 Einstellungen (Admin)** | Karte „HTTP-Jobs“: Maximum Zeitlimit (1–3600, mit Hinweis E9), Antworten speichern (Aus · An · Nie), Pfad in der URL-Anzeige (Automatisch · Immer verbergen, mit Erklärung des Restrisikos); „Auf Standard zurücksetzen“. Karte „Interne Ziele“: Tabelle Art · Netz/Host · Port · Gilt für (global/Kategorie) · Notiz · angelegt; Formular zum Hinzufügen, Löschen mit Bestätigung; Hinweis, welche Netze nie freigebbar sind. Jede Karte nur, wenn das passende Recht vorhanden ist. | `/api/settings…`, `/api/settings/internal-targets…`, `/api/categories?permission=jobs.view` |

Pflicht aus `mer-ui`: kein `dangerouslySetInnerHTML`, Ausgaben als Text, CSRF-Header bei POST/PUT/DELETE,
nichts im Browser-Speicher, Geheimfelder nie vorbefüllt (die `display_url` ist kein Eingabefeld und wird nie in
ein Eingabefeld kopiert), Handybreite, `prefers-reduced-motion`, sichtbarer Fokus.

---

## 7. Umsetzungsreihenfolge

Jeder Schritt endet mit grünem `composer check` (bzw. `npm run build` + Typprüfung) und einem eigenen Commit.

| # | Agent | Inhalt | Abnahme / Tests | Skill |
|---|---|---|---|---|
| S1 | sicherheit | `Permission::ManageInternalTargets` und `Permission::ManageSettings` (beide `isDangerous`), Migration `0007_http_jobs.sql` (3.1), `CategoryScope` + `AccessControl::scope()` | Migrationstest: Admin hat beide Rechte, Operator/Beobachter nicht, zweimal einspielen ändert nichts; `settings` lehnt unbekannten Schlüssel und ungültiges JSON per `CHECK` ab; Freigabe mit Kategorie wird beim Löschen der Kategorie gelöscht (nicht global); doppelte globale Freigabe scheitert am eindeutigen Index; Eigenschaftstest `scope ≡ can`; beschränkte Rolle bekommt die neuen Rechte nie | mer-security §4/§5, mer-storage §2/§3 |
| S2 | backend | Kernel `put`/`delete`/`post` mit Anforderungen, `ValidationFailed` → 422, JSON-Helfer (`no-store`, `JSON_INVALID_UTF8_SUBSTITUTE`), `JsonBody::object(…, depth)` | Kernel-Tests: 405/404, ungültige ID → 404, ungültiges UTF-8 → gültiges JSON | mer-security §9 |
| S3 | sicherheit | `HttpPayload`, `UrlPolicy`, **`UrlDisplay`** (E2), `AddressPolicy` (mit Kategorie-Freigaben), `BlockReason`, `HostResolver`, `TargetGuard` (rein, ohne curl) | SSRF-Fälle aus 7.1; **alle `UrlDisplay`-Leak-Tests aus E2** inkl. Längen-Unabhängigkeit und Eigenschaftstest; Freigabe-Auflösung 7.2; `HttpPayload`: `var_dump`/`print_r`/`json_encode`/Trace ohne Klartext, `serialize` wirft, unbekannte Version abgelehnt | mer-runner §3/§4, mer-security §11 |
| S4 | backend | `HttpJobConfig`, Validierung 3.2, `JobRepository`, `RunRepository` mit gemeinsamem Scope-Prädikat, **`Settings`** (lesen, Standardwerte, validieren) | Roundtrip über neue `Connection`: `config_json` ohne Test-Geheimnis, `display_url` exakt wie erwartet, `payload_enc` beginnt mit `v1:` und entschlüsselt zum Original; `Settings`: fehlende Zeile → Standard, kaputter Wert in der DB → Standard **und** Fehlerlog ohne Wert (nie lockerer als Standard) | mer-storage §8 |
| S5 | backend | Lesende API: Jobs, Detail, Verlauf, Lauf, Kategorien, Vorschau, `/api/jobs/limits` | Rollen-Matrix 4.2 (Admin, Op, Op/A, Beobachter, anonym) mit Kategorien A, B und Job ohne Kategorie; IDOR (Op/A liest Job/Lauf aus B → 404); `category=B` für Op/A leer; **H2**; `display_url` nur im Detail; Vorschau `count=11` → 422 | mer-security §5 |
| S6 | backend | Schreibende Job-API (E3), löschen, aktivieren/deaktivieren, **H6**, Audit; Zeitlimit gegen globales Maximum | Rollen-Matrix; CSRF fehlt/falsch/fremder Origin → 403 ohne Änderung; Kategorie-Wechsel A→B für Op/A → 422; Leak: Test-Geheimnis in Pfad, Query, Header, Body erscheint in keiner Antwort (außer als `••••`), keinem Audit-Eintrag, keiner Klartextspalte; `next_run_at` nach Anlegen/Ändern/Aktivieren gesetzt, nach Deaktivieren NULL; Zeitlimit > Maximum → 422; GET mit gespeichertem Body → 422 | mer-security §1/§14, mer-scheduler §1 |
| S7 | sicherheit | Worker: Masker pro Lauf (**H4**), `RunRequest::startedBy`, **H1** über `RunAuthorizer`, Retry übernimmt `started_by`, Testlauf für deaktivierte Jobs, `RunResult::retryable` | Bestehende Worker-Tests grün; Geheimnis aus Lauf 1 nicht im Masker von Lauf 2; Benutzer deaktiviert / Recht entzogen / Job verschoben zwischen Einreihen und Übernahme → `skipped`; `retryable = false` → keine Wiederholung | mer-scheduler §3/§4/§5, mer-runner §4 |
| S8 | backend | `POST /api/jobs/{id}/run` und `/test` (E4) | Rollen-Matrix; Beobachter → 403; deaktiviert: run → 409, test → 202; zweiter offener Lauf → 409; 61. Lauf/Stunde → 429; Lauf trägt `started_by`, `trigger` | mer-scheduler §5 |
| S9 | backend | `CurlTransport` + `HttpRunner` (5.3/5.4), Verdrahtung 5.5, Heartbeat, Einstellungen pro Lauf | Integrationstests gegen `php -S 127.0.0.1:<port>` (Loopback per Freigabe erlaubt): Methoden, Header, Body, Statusbewertung, Weiterleitungen (fremder Ursprung ohne Header), Zeitlimit → `timeout`, Maximum gesenkt → Lauf nach Maximum abgebrochen mit Notiz, 11 MiB → Abbruch, Binärantwort; **Antwort speichern**: alle 9 Kombinationen global×Job, `never` gewinnt immer; **Pinning-Beweis** über `meridian-test.invalid` mit Fake-Resolver; Herzschlag: Antwort nach 3 s → `beat()` ≥ 3×; `beat() → false` bricht in ≤ 2 s ab; Optionen-Test VERIFYPEER/VERIFYHOST/FOLLOWLOCATION/PROXY | mer-runner §3/§5/§6 |
| S10 | sicherheit | Freigaben interner Ziele (E5): `GET/POST/DELETE /api/settings/internal-targets`, CLI `http:internal-targets list\|add\|remove [--category=NAME] [--port=N]`, Audit | Nur `network.internal_targets` uneingeschränkt (Admin 2xx; Op, Op/A, Beobachter 403; anonym 401; Admin-Rolle mit Kategorie-Beschränkung 403); nie freigebbare Netze/Namen → 422; Hostbits gesetzt → 422; Freigabe wirkt beim nächsten Lauf ohne Neustart; Auflösungsfälle 7.2 Ende-zu-Ende | mer-security §4/§7, mer-runner §3 |
| S11 | tester | Leak- und SSRF-Gesamtsuite | Echo-Server gibt URL, Header und Body zurück: `runs.output`, `runs.note`, API-Antworten (Liste, Detail, Lauf, Einstellungen), Audit, Ausgabe von `scheduler:run`, `error_log` enthalten das Test-Geheimnis weder roh noch URL-kodiert noch base64; `display_url` nur mit `••••` an geheimen Stellen; Weiterleitung mit Geheimnis in `Location`; curl-Fehler mit URL; SSRF-Ende-zu-Ende ohne IP in der Notiz | alle |
| S12 | infra | `ext-curl` in `composer.json` (O11), `php-curl` im Installer, im Image `php -m \| grep curl` und CA-Bündel prüfen, Resolver-Optionen | Image-Build grün, HTTPS-Aufruf aus dem Scheduler-Container gegen ein öffentliches Ziel ok | – |
| S13 | backend | `category:create <name>` (CLI, Audit) (O7) | Name gegen `^[A-Za-z0-9 _.-]{1,64}$`, doppelt → Fehler, Audit-Eintrag ohne Benutzer | mer-security §8 |
| S14 | sicherheit | Einstellungs-API `GET /api/settings`, `PUT /api/settings/{key}` (E10), Verschärfung bestehender `display_url` beim Wechsel auf `hidden` (E3) | Rollen-Matrix (nur `settings.manage` uneingeschränkt); CSRF; unbekannter Schlüssel → 404; falscher Typ/Bereich (`0`, `3601`, `"300"`, `"maybe"`) → 422; Audit mit altem und neuem Wert; Roundtrip; nach `hidden` enthält keine `display_url` mehr einen Pfad oder Query-Namen; Zurück auf `auto` ändert nichts | mer-security §4/§14, mer-storage §3/§4 |
| U1 | frontend | Router mit Parametern, `request()` PUT/DELETE, Typen, Rechte-Helfer, Menü „Jobs“/„Einstellungen“ | Build ohne CSP-Fehler; unbekannte Route → Übersicht | mer-ui §5 |
| U2 | frontend | Übersicht „Nächste Abfahrten“ + Jobliste (6) | Leerzustand, Beobachter ohne „Anlegen“, Handybreite, nur Host als Ziel | mer-ui |
| U3 | frontend | Job-Editor (6) | Geheimfelder nie vorbefüllt, nach Speichern geleert; `display_url` nur als Text; Zeitlimit-Hinweis ab 60 s; „Antwort speichern“ bei `never` deaktiviert | mer-ui §3 |
| U4 | frontend | Job-Detail mit Verlauf, manuellem Lauf, Testlauf (6) | Ausgabe als Text (HTML im Body wird nicht gerendert), Abfrage endet nach Abschluss | mer-ui §4 |
| U5 | frontend | Einstellungen für Admins (6) | Karten nur mit Recht sichtbar; Fehler 422 am Feld; nie freigebbare Netze erklärt | mer-ui §5 |
| R | sicherheit | Review Phase 3 nach mer-security §15, inkl. Prüfung der Ausnahme E2 | alle Befunde behoben oder von Alex akzeptiert | – |

Abhängigkeiten: S1 → S3/S4 → S5 → S6 → S8; S4 → S14; S7 vor S9; S2 vor S5; U1 nach S5, U3 nach S6, U4 nach
S8/S9, U5 nach S10/S14. Parallel möglich: S3 ‖ S4, S7 ‖ S5/S6, S10 ‖ S14, U2 ‖ S6.

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
auf fremden Ursprung. Umgebung: `HTTP_PROXY`/`HTTPS_PROXY` gesetzt → wird nicht benutzt. Header `Host` → 422.

### 7.2 Freigabe-Auflösung (Pflicht in S3/S10)

| Freigabe | Job | Ziel | Erwartung |
|---|---|---|---|
| `cidr 192.168.1.0/24`, global | Kategorie A | `192.168.1.5:80` | erlaubt |
| dieselbe | Kategorie A | `192.168.2.5:80` | gesperrt |
| `cidr 192.168.1.0/24`, Kategorie A | Kategorie A · B · ohne | `192.168.1.5` | erlaubt · gesperrt · gesperrt |
| `cidr 10.0.0.0/8` Port 8080, global | A | `10.1.2.3:8080` · `:8081` | erlaubt · gesperrt |
| `host nextcloud`, Kategorie A | A | `nextcloud` → `172.18.0.4` | erlaubt (IP gepinnt) |
| dieselbe | A | `nextcloud` → `127.0.0.1` bzw. `169.254.169.254` | gesperrt (Host gibt nur „privat“ frei) |
| dieselbe | A | `cloud.example` → `172.18.0.4` | gesperrt (anderer Name) |
| `host nextcloud`, global | A | Weiterleitung von `nextcloud` auf `db:5432` (`172.18.0.5`) | gesperrt (Hop neu geprüft) |
| `cidr 127.0.0.1/32` Port 8099, global | A | `127.0.0.1:8099` · `127.0.0.1:8080` | erlaubt · gesperrt |
| Anlegen `cidr 127.0.0.0/8`, `cidr 127.0.0.1/32` Port 0, `cidr 169.254.0.0/16`, `cidr 0.0.0.0/0`, `cidr 192.168.1.5/24`, `host localhost`, `host metadata.google.internal` | – | – | 422 |
| Kategorie A gelöscht | Job war in A | `192.168.1.5` | Freigabe weg → gesperrt |
| Job von A nach B verschoben | – | `192.168.1.5` | gesperrt ab dem nächsten Lauf |

---

## 8. Entscheidungen von Alex

### 8.1 Entschieden (07.10.2026)

| # | Frage | Entscheidung |
|---|---|---|
| O1 | Interne Ziele standardmäßig gesperrt? | Ja; Admin gibt frei (E5). |
| O2 | Host in Liste und Abfahrtstafel? | Ja, nur Host. |
| O3 | URL in der Bearbeiten-Ansicht? | **Maskiert anzeigen wie im Klickdummy** — ausdrückliche Ausnahme, sicher umgesetzt nach E2. |
| O4 | Anfrage nur als Ganzes ersetzen? | Ja (E3). |
| O5 | Header-Namen im Editor? | Nein; nur `has_headers` und Anzahl. |
| O6 | Zeitlimit? | Je Job, Standard 30 s; globales Maximum durch den Admin, Standard 300 s, höchstens 3600 s (E9). |
| O7 | Kategorien anlegen? | CLI `category:create` jetzt (S13), Web in Phase 5. |
| O8 | Freigaben interner Ziele? | Global **oder** je Kategorie, nach Host oder CIDR plus Port (E5). |
| O9 | Antwort speichern? | Global (Standard aus, „nie“ erzwingbar) und je Job (erben/an/aus) (E11). |
| O10 | Testlauf für deaktivierte Jobs? | Ja; manueller Lauf nur für aktive. |
| O11 | `ext-curl` in `composer.json`? | Ja. |
| O12 | Proxy-Umgebung ignorieren? | Ja. |
| O13 | Eigene CA für interne HTTPS-Ziele? | Später. |

### 8.2 Rückfragen (entschieden von Alex, 07.10.2026: jeweils wie empfohlen)

| # | Frage | Empfehlung |
|---|---|---|
| R1 | Standard für `http.display_path`: `auto` (Wörter aus Kleinbuchstaben im Pfad sichtbar, Restrisiko E2 Punkt 5) oder `hidden`? | **`auto`** für den Klickdummy-Eindruck, mit Hinweis im Editor; Unternehmen stellen auf `hidden`. Wer das Restrisiko nicht will: `hidden` als Standard. |
| R2 | Bekommen Admins die zwei neuen Rechte in bestehenden Installationen automatisch (per Migration)? | **Ja**, nur der Rolle Admin (Regel „neue Rechte nur an Admin“). |
| R3 | Soll ein Operator sehen, **dass** sein Ziel an einer fehlenden Freigabe scheitert (Notiz nennt „Admin kann freigeben“)? | **Ja** (Notiz ohne IP, wie in 5.3). |

Entschieden: R1 `auto` (Standard in `DisplayPathMode::default()`; **am 09.10.2026 geändert auf `hidden`**, E14), R2 ja, nur Rolle Admin (Migration 0007), R3 ja,
ohne IP; bei nie freigebbaren Netzen nennt die Notiz stattdessen, dass keine Freigabe möglich ist (`TargetBlocked`).

---

## 9. Sicherheitsprüfung des Entwurfs

| Frage | Antwort |
|---|---|
| Wo entstehen Geheimnisse? | Im Editor (Browser) und im Anfragekörper von `POST/PUT /api/jobs`. Sie werden validiert, sofort mit `SecretBox` verschlüsselt und nur als `payload_enc` gespeichert. Daneben entsteht im selben Schritt `display_url` nach Allowlist-Regel (E2). Der Webprozess entschlüsselt nie (E3). Einstellungen und Freigaben enthalten nie Geheimnisse. |
| Wo werden sie entschlüsselt? | Nur im `HttpRunner` im Scheduler-Prozess, unmittelbar vor dem Lauf; sofort im Masker des Laufs registriert (E8). |
| Wo verlassen Daten das System? | (1) Die HTTP-Anfrage an das Ziel – nur nach SSRF-Prüfung und Freigabe-Auflösung je Hop, nur an gepinnte Adressen, Header/Body nie an fremde Ursprünge. (2) API-Antworten – Feld-Allowlist, `has_*`/Anzahl, `display_url` nur mit festen `••••` an möglichen Geheimnisstellen, Texte maskiert. (3) Verlauf – maskiert → gekürzt → gespeichert, nur wenn Speichern wirksam ist (E11); beim Ausliefern erneut maskiert. (4) Audit – Feldnamen, „Anfrage ersetzt“, Einstellungswerte (nicht geheim), Freigaben. (5) Prozessausgabe des Schedulers – nur IDs und Status. (6) `error_log` – nur maskiert, Runner-Ausnahmen nie mit Meldung. |
| Wo wird geprüft, wer was darf? | Jeder Endpunkt: Sitzung → CSRF → `findVisible` (Scope) → `AccessControl::require()` mit gespeicherter Kategorie. Beim Übernehmen manueller/Test-/Wiederholungsläufe erneut (H1). Freigaben und Einstellungen: je ein eigenes gefährliches Recht, nur uneingeschränkt. Freigaben je Kategorie wirken nur für Jobs, deren **gespeicherte** Kategorie passt; gelöschte Kategorie löscht ihre Freigaben. |
| Neue Ausgabestellen und ihre Leak-Tests | Job-Liste, Job-Detail (inkl. `display_url`), Verlauf, Lauf-Detail, Vorschau-Fehler, Validierungsfehler, `/api/jobs/limits`, Einstellungen, Freigaben, Audit-Ziele, Run-Notizen, Runner-Ausgabe, CLI `http:internal-targets`, `category:create` – abgedeckt in S3/S5/S6/S9/S10/S11/S13/S14. |

---

## 10. Regeländerungen für `CLAUDE.md` und Skills

Vom Koordinator einzutragen, sobald dieser Entwurf gilt, spätestens mit dem Schritt, der die Regel zuerst im
Code umsetzt (Pflicht „Skill mitpflegen“). Herleitung bleibt hier.

**`CLAUDE.md`, Sicherheitsnetz:**

1. Zeile „Keine Geheimnisse in Antworten — auch nicht maskiert, höchstens ein `has_*`-Flag.“ ergänzen um:
   „Einzige Ausnahme (Entscheidung Alex, 0003): `display_url` eines HTTP-Jobs, beim Speichern von `UrlDisplay`
   nach Allowlist erzeugt, mögliche Geheimnisstellen als festes `••••` ohne Längeninformation; nie für Header,
   Body oder Skripte.“
2. Neue Zeile: „Globale Einstellungen nur über die Allowlist der Schlüssel in `settings`, nie Geheimnisse;
   Einstellungen und Freigaben, die Schutz lockern, nur mit eigenem gefährlichen Recht und Audit-Eintrag.“ (`mer-security`, `mer-storage`)
3. Neue Zeile: „Freigaben interner Ziele gelten global oder für eine Kategorie; eine gelöschte Kategorie löscht
   ihre Freigaben, nie werden sie global.“ (`mer-security`, `mer-runner`)

**`mer-security`:**

- §4: Rechte `network.internal_targets` und `settings.manage` nennen (gefährlich, nur Admin, nie beschränkt).
- §5: `AccessControl::scope()`/`CategoryScope` als die eine Sichtbarkeitsfunktion benennen.
- §7 SSRF: Freigaben mit Geltungsbereich (global/Kategorie), Art `cidr`/`host`, Port; Host-Freigabe gibt nur
  „privat“ frei; Loopback nur einzeln mit Port; nie freigebbar: Link-local/Metadaten, `0.0.0.0/8`, Multicast,
  reserviert, Docker-Proxy.
- §11 Geheimnisse: Ausnahme `display_url` mit den Regeln aus E2 (nur beim Speichern, nur `UrlDisplay`,
  Allowlist, festes `••••`, Längen-Unabhängigkeit, Pflicht-Leak-Tests); „Anfrage nur als Ganzes ersetzen“;
  Web-API entschlüsselt nie.
- §14 Audit: neue Ereignisse `settings.changed`, `network.internal_target_added/_removed`, `job.run_test`.
- Verboten: „`display_url` aus dem entschlüsselten Payload oder per `SecretMasker` bilden“; „Einstellung ohne
  Allowlist-Schlüssel oder mit Geheimnis“; „Kategorie-Freigabe bei gelöschter Kategorie zu global machen“.
- Checkliste: „Neue Anzeige eines geheimen Werts? Nur über eine dokumentierte Ausnahme mit Allowlist und
  Leak-Test.“

**`mer-runner`:** §3 Freigabe-Auflösung je Hop mit Kategorie des Jobs aus der DB; Einstellungen je Lauf frisch
lesen; Zeitlimit = `min(Job, Maximum)`; Antwort speichern nach E11 (`never` gewinnt); Proxy-Umgebung ignorieren;
§4 Masker pro Lauf (neue `Runner`-Signatur).

**`mer-scheduler`:** §3 Testläufe laufen auch für deaktivierte Jobs; H1-Prüfung im `claim()`; Retry übernimmt
`started_by`; §5 Hinweis, dass lange Läufe bis Phase 4 den Takt aufhalten (verpasste Termine nach 300 s).

**`mer-storage`:** §2/§3 Tabelle `settings` (Schlüssel-`CHECK`, `json_valid`, keine Zeile = Standard, nie
beim Start seeden), Hintergrundprozesse schreiben keine Einstellungen; `ON DELETE CASCADE` von
`http_internal_targets.category_id` als Beispiel einer Kaskade, die Rechte **verengt**.

**`mer-ui`:** §3 `display_url` ist Anzeige-Text, nie Eingabefeld-Wert; Einstellungsseite nur mit Recht.

**Hook (`rule-router.js`):** optional ein Muster, das `display_url` außerhalb von `UrlDisplay`/Repository-Mapper
als Zuweisung meldet (NOTE).

## Nachtrag 09.10.2026 – Entscheidungen Alex (E13–E17)

### E13 – Proxy-Header ohne `MERIDIAN_TRUSTED_PROXIES`: ablehnen (Variante A)

Kommt bei einem Endpunkt mit IP-Sperre (`POST /api/auth/login`, `/api/auth/2fa/enable`, `/api/auth/2fa/disable`)
ein Header `X-Forwarded-For` oder `Forwarded` an und ist `MERIDIAN_TRUSTED_PROXIES` leer, antwortet Meridian mit
**503** `{"error": "<Meldung>", "trusted_proxies_required": true}` (`Http\ClientIp::forThrottle()`), bevor ein
Passwort geprüft oder ein Fehlversuch gezählt wird. Die Meldung sagt, was falsch ist und wie man es behebt
(`MERIDIAN_TRUSTED_PROXIES` auf IP/Netz des Proxys setzen, neu starten); das Fehlerlog nennt keinen Eingabewert.
Bei 2FA kommt die Prüfung nach Sitzung und CSRF (ohne Sitzung weiter 401).
Mit gesetzten Proxys glaubt Symfony `X-Forwarded-For` nur, wenn die Verbindung von einem dieser Proxys kommt
(`ClientIp::trust()`, `public/index.php`); `Forwarded` wird nie geglaubt. Ein gefälschter Header ändert die gezählte
IP also nie (Tests `TrustedProxyTest`: gesperrte IP bleibt gesperrt bei wechselnden gefälschten Adressen, eine
vorangestellte Fälschung hinter dem echten Proxy hilft nicht).
Verworfen: *nur warnen* (bisheriger Stand: alle Clients teilen sich die IP des Proxys, die Sperre trifft alle oder
keinen); *`X-Forwarded-For` ohne Konfiguration glauben* (jeder Client wählt seine IP und umgeht die IP-Sperre).

### E14 – Standard `http.display_path = hidden`

Ohne gespeicherte Einstellung gilt `hidden`, für neue **und** bestehende Installationen. Bestehende `display_url`
werden ohne Entschlüsseln verschärft: beim Lesen (`JobPresenter` → `UrlDisplay::tightenStored()`) und dauerhaft bei
jedem `migrate` (`Job\DisplayUrlUpgrade::run()` liest die Einstellungen und ruft `tightenAll()`; idempotent; legt
keine Einstellung an). Ein gespeichertes `auto` bleibt `auto` (Migration 0008 übernimmt die Zeilen). Zurück auf
`auto` lockert gespeicherte Anzeigen nie.

### E15 – Anfrage-Details nur mit Bearbeitungsrecht

`display_url`, `has_url`, `has_headers`, `header_count`, `has_body` stehen im Detail nur, wenn `can.edit` gilt
(`JobPermissions::edit(type)` mit der **gespeicherten** Kategorie, dieselbe Prüfung wie das Flag). Alle anderen
sehen `method`, `timeout_seconds`, `expected_status`, `max_redirects`, `store_response`, `target` und
`has_request: true`. Nicht sichtbare Jobs bleiben 404 (Beobachter in A auf Job in B). Rollen-Matrix in
`JobReadApiTest` (Admin, Operator, Operator mit Recht in A, Operator nur in B und Beobachter in A, Beobachter,
Beobachter in A).

### E16 – Neue Einstellung `http.display_host = auto|hidden` (Standard `auto`)

Allowlist-Schlüssel per Migration 0008 (CHECK neu aufgebaut, Zeilen übernommen). Bei `hidden`:
- `target` in Liste, Detail und Übersicht: `<schema>://••••` (Host **und** Port verborgen), für jede Rolle — auch
  für Admins und Bearbeiter. Den Host sieht nur, wer die Anfrage neu eingibt.
- `display_url`: Ursprung ersetzt (`https://••••/api?x=••••`), beim Speichern (`UrlDisplay::build()`), beim Lesen
  und dauerhaft beim Ändern der Einstellung in derselben Transaktion wie Audit `settings.changed`
  (`http.display_host: auto → hidden; Anzeige-URL bei N Job(s) verschärft`) sowie bei `migrate`.
  `UrlDisplay::hideStored()` erkennt einen schon verborgenen Ursprung, damit späteres Pfad-Verbergen den Host nie
  zurückbringt.
- Laufausgabe: neue Läufe schreiben `→ GET https://••••` (`HttpRunSettings::hidesHost()`, je Lauf frisch); ältere
  gespeicherte Zeilen `→ METHODE <ursprung>` verbirgt die API beim Lesen (`UrlDisplay::hideHostInRunOutput()`).
- Zurück auf `auto`: gespeicherte Anzeigen bleiben verschärft; `target` folgt der aktuellen Einstellung (es wird
  nicht gespeichert, sondern aus `config_json.http.target` gebildet).
- Nicht betroffen: gespeicherte Antwortkörper (`store_response`) und `config_json.http.target` (Klartextspalte, nie
  direkt ausgegeben).

### E17 – Infrastruktur nie freigebbar (`BlockReason::Infrastructure`)

`Runner\Http\InfrastructureTargets`, geprüft in `AddressPolicy::check()` nach den nie freigebbaren Klassen und
**vor** jeder Freigabe (eine breite Freigabe hilft nie):
- Docker-API-Ports **2375/2376** auf jeder Adresse der Klassen privat/Loopback (wie 0004 E11);
  `InternalTarget::create()` lehnt Freigaben mit diesen Ports ab (auch beim Laden aus der DB).
- Host-/Dienstnamen des docker-socket-proxy (`MERIDIAN_DOCKER_PROXY_HOSTS`, kommagetrennt; `docker-proxy` ist immer
  dabei) auf jedem Port und jeder Adresse; Host-Freigaben dieser Namen → 422.
- Meridians eigener Listen-Port (`MERIDIAN_LISTEN_PORT`, Standard 8080 wie FrankenPHP `:8080`) auf Loopback und auf
  jeder Adresse der eigenen Netzwerkschnittstellen (`net_get_interfaces()`); Loopback-Freigabe mit diesem Port → 422.
  Restliches Loopback bleibt nur einzeln mit ausdrücklichem Port freigebbar.
- Meldung wie bei anderen nie freigebbaren Netzen (ohne Adresse, ohne Freigabe-Versprechen).
Offen: Im Compose-Betrieb erreicht der Scheduler das Web unter dem Dienstnamen `web:8080` (andere Adresse, nicht
die eigene Schnittstelle) und über den veröffentlichten Host-Port (`MERIDIAN_PORT`, Standard 8090); beides fängt
die Regel nur, wenn der Admin es nicht per Freigabe öffnet.


## Nachtrag N1 (2026-10-10): Gespeicherte Anfrage im Editor anzeigen (`jobs.reveal_for_edit`)

Entscheidung Alex (im Chat bestätigt, übermittelt vom Hauptagenten): „Ja, so bauen, aber hinter einer Einstellung,
Standard ist wie jetzt.“ Grund: Beim Bearbeiten musste jedes Mal die ganze Anfrage neu eingegeben werden.

- Neue globale Einstellung `jobs.reveal_for_edit` = `off` | `on`, **Standard `off`** (Migration 0012 erweitert den
  CHECK von `settings.key`). Ändern nur mit `settings.manage`, Audit `settings.changed` (API und CLI gleich).
- Weitere Ausnahme von „keine Geheimnisse in Antworten“ neben `display_url`: **nur** der dedizierte Endpunkt
  `GET /api/jobs/{id}/source`, nie Detail, Liste oder Verlauf. Antwort unmaskiert
  `{job_id, type: "http", url, headers: [{name, value}], body}`.
- Prüfreihenfolge: Sitzung (401, Pflichtwechsel 403) → `Sec-Fetch-Site: same-origin` Pflicht (403) → Einstellung
  (403 `reveal_disabled`, „Die Anzeige gespeicherter Skripte und Links ist deaktiviert (Einstellungen).“) → Sicht
  `jobs.view` (404) → `jobs.edit_http` für die gespeicherte Kategorie (403) → Rate-Limit 60 je Benutzer und Stunde
  (429 + `Retry-After`, gezählt über die Audit-Einträge) → Audit `job.source_viewed` (`job:ID Name`, nie Inhalt) je
  Abruf → Entschlüsseln im Web-Prozess (`JobSourceReader`), `Cache-Control: no-store`, `Pragma: no-cache`.
- Der Inhalt erscheint in keinem Log, keiner Exception, keiner Fehlermeldung (`#[\SensitiveParameter]`, `Sealed`,
  feste Meldung `JobSourceUnreadable`). Die Oberfläche hält die Werte nur im Zustand des Editors und löscht sie beim
  Speichern und Verlassen; nie Browser-Speicher oder URL. Bearbeiten bleibt: `PUT` ersetzt die Anfrage als Ganzes.
- Restrisiko (bewusst akzeptiert): Wer `jobs.edit_http` für eine Kategorie hat, sieht bei `on` Tokens in URLs,
  Header-Werte und Bodies dieser Jobs im Klartext.
