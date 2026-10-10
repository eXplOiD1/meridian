# 0004 – Phase 4: Shell-Jobs, Worker-Prozesse, Live-Log

Status: **Entwurf, offene Entscheidungen für Alex in §11** (bis zur Antwort gelten die Empfehlungen als
Arbeitsgrundlage, damit die Umsetzung nicht wartet). Verfasser: architekt, 09.10.2026.
Gilt für die Umsetzung von Phase 4 in `TODO.md` (Schritte S1–S12, U1–U3, R). Phase 4 beginnt **nach** Phase 4a
(Benutzer- und Rollenverwaltung, ADR 0005).

Dieser Entwurf ändert keinen Produktcode. Interfaces und Migrationen stehen hier als Skizze; die umsetzenden
Agenten übernehmen sie. Dateien unter `migrations/` legt erst S1 an (der `Migrator` spielt alles dort sofort ein).
Regeländerungen für `CLAUDE.md` und Skills: §13. Struktur wie `0003-phase3-http-jobs.md`.

**Keine neue Composer-Abhängigkeit.** Neu sind nur **Container-Images**: der Docker-Socket-Proxy (E10) und ein
Sandbox-Image auf Basis von `alpine` (E2) — beide als Rückfrage O1/O4 markiert.

---

## 1. Ausgangslage

| Vorhanden | Folgerung für Phase 4 |
|---|---|
| `jobs.type` erlaubt schon `shell` (CHECK in 0001), `JobType::Shell`, `Permission::EditShellJobs` (`jobs.edit_shell`, gefährlich, per 0001 nur Admin), `JobPermissions::edit()` kennt Shell | Kein neues Recht zum Bearbeiten nötig. `JobValidator::checkType()` lehnt `shell` noch ab, `JobService::create()` prüft fest `EditHttpJobs` → verallgemeinern (S6). |
| `Runner::run(RunRequest, Heartbeat, SecretMasker)`, `RunResult` mit `retryable`, `Worker` maskiert → kürzt → speichert, Masker je Lauf | Bleibt; Erweiterung um eine Live-Log-Senke und einen Abbruchgrund (§5.1). |
| `Worker` läuft **im Takt-Prozess** von `scheduler:run`, übernimmt nur mit Sperre (`lease->isHeld()` in `claim()`), `RunHeartbeat` verlängert die Scheduler-Sperre und prüft `worker = lease->owner()` | Läufe blockieren den Takt (bekannter Punkt in `WEITERMACHEN.md`). Phase 4 trennt Planen und Ausführen (E5): Worker-Prozesse haben eine eigene Kennung und berühren die Sperre nie. |
| `runs.output` wird erst am Ende geschrieben (64 KiB) | Live-Log braucht Zwischenstände, die der Webprozess lesen kann → Tabelle `run_log_chunks` (E8). |
| Kein Abbruch durch Benutzer; `beat() === false` heißt nur „als hängend abgebrochen“ | Neue Spalten `runs.cancel_requested_at/_by`, `Heartbeat` meldet den Grund (E7). |
| `compose.yaml`: `web` + `scheduler`, beide mit Schlüssel- und Daten-Volume, beide im Standardnetz; Docker-Proxy auskommentiert (`tecnativa`, `CONTAINERS=1 EXEC=1 POST=1`) | Diese Proxy-Rechte erlauben auch `POST /containers/create` mit `Privileged` und Host-Mounts = Root auf dem NAS. Ersetzen (E10, O1). |
| `AddressPolicy` hat `BlockReason::Infrastructure` (noch ungenutzt), TODO „Proxy-Adressen fest sperren“ | Der Proxy bekommt **keine IP-Adresse** (Unix-Socket, E10); der TODO-Punkt wird strukturell erledigt und um eine Port-Sperre ergänzt (E11). |
| systemd: `meridian-web`, `meridian-scheduler`, beide `User=meridian`, `NoNewPrivileges=true`, Schlüssel per `LoadCredential` | Ein Kindprozess des Schedulers liefe als `meridian` und könnte Schlüssel und Datenbank lesen. Host-Ausführung nur über einen getrennten Dienst mit eigenem Benutzer (E3). |
| FrankenPHP mit begrenzter Thread-Zahl (Standard 2 × CPU) | Jede SSE-Verbindung belegt einen PHP-Thread → harte Obergrenze und Rückfall auf Abfragen (E9). |
| Klickdummy: Art „Befehl ausführen“, Jobliste mit Filter „Shell“, Verlauf mit Live-Log | Begriffe übernehmen; Live-Log-Ansicht kommt jetzt im Job-Detail (U2). |

---

## 2. Entscheidungen

### E1 – Was ist ein Shell-Job

Ein Shell-Job ist ein **Skript** (Text), das an einem **Ausführungsort** von einem festen **Interpreter**
(`sh` oder `bash`) über stdin gelesen wird, mit einer **expliziten Umgebung**, optional Benutzer und
Arbeitsverzeichnis, unter Zeitlimit. Ausführungsorte:

| Art | Betrieb | Wo läuft der Befehl | Status in Phase 4 |
|---|---|---|---|
| `docker` | Docker (Compose), optional systemd mit Docker | `docker exec` in einem **bestehenden** Container aus der Allowlist, über den Socket-Proxy (E10) | **Pflicht (MVP)** |
| `docker` → `meridian-sandbox` | Docker | Sonderfall der Zeile darüber: mitgelieferter, abgeschotteter Container für „lokale“ Skripte (E2) | **Pflicht (MVP)** |
| `host` | systemd-Installation | getrennter, socket-aktivierter Dienst `meridian-shell@` als Benutzer `meridian-run` (E3) | Schritt S11, darf nach Phase 9 rutschen (O3) |

Nicht vorgesehen: Ausführung im Scheduler-/Worker-Container selbst (E2, verworfen), Anlegen neuer Container,
Ziehen von Images, Ausführung als Root auf dem Host. Ein Befehl als Argument-Liste ohne Shell
(`["php","artisan","schedule:run"]`) ist nicht MVP (O12); das Datenformat lässt Platz dafür (`payload.mode`).

### E2 – Kein Shell-Job im Meridian-Container; „lokal“ heißt Sandbox-Container

**Verworfen: (a) Ausführung im Scheduler-/Worker-Container.** Dort liegen Hauptschlüssel (`/etc/meridian`) und
Datenbank (`/var/lib/meridian`). Der Container läuft mit `cap_drop: ALL` und `no-new-privileges`; ein Wechsel auf
einen anderen Benutzer ist damit unmöglich (kein `setuid`, kein `sudo`). Ein Kindprozess als `meridian` (uid 1000)
könnte beides lesen → verstößt gegen `mer-runner` §1. Eine Lockerung (`CAP_SETUID`) würde den ganzen Container
schwächen.

**Stattdessen:** Compose bringt einen Dienst `sandbox` (Container `meridian-sandbox`) mit:

- Image aus `alpine:3.20` (Version **und** Digest festgelegt), Pakete `bash`, `curl`, `jq`, `ca-certificates`,
  `tzdata` (busybox liefert `sh`, `head`, `cut`, `setsid`, `kill`, `timeout`). Keine Compiler, kein `docker`,
  kein `ssh`. Erweitern ist Sache des Admins (eigenes Image).
- `user: "1001:1001"`, `read_only: true`, `tmpfs: /tmp:size=64m,uid=1001,gid=1001,mode=0700`, `cap_drop: ALL`,
  `no-new-privileges`, `init: true`, `mem_limit: 512m`, `pids_limit: 256`, `cpus: 1.0`,
  `command: ["sleep", "2147483647"]`, **keine Volumes**, eigenes Netz `sandbox-net` (mit Ausgang ins Internet/LAN —
  das ist der Zweck, z. B. `curl` auf interne Dienste). Kein Zugang zum Proxy-Socket, zu Schlüssel oder Datenbank.
- Wird nie automatisch freigegeben: Der Admin trägt `docker meridian-sandbox` (Benutzer `1001`) als Ausführungsort
  ein (`shell:targets add docker meridian-sandbox --user=1001` oder Einstellungsseite). Kein Seeden (`mer-storage` §3).

### E3 – Host-Ausführung (systemd): eigener Dienst, eigener Benutzer, eine Unit je Lauf

Der Worker (Benutzer `meridian`, hat Schlüssel und Datenbank) startet den Befehl **nicht selbst**:

- `meridian-shell.socket`: `ListenStream=/run/meridian-shell/default.sock`, `SocketUser=meridian`,
  `SocketGroup=meridian`, `SocketMode=0600`, `Accept=yes`, `MaxConnections=8`.
- `meridian-shell@.service` (eine Instanz je Verbindung = je Lauf): `User=meridian-run`,
  `ExecStart=/usr/bin/php /opt/meridian/bin/meridian-shell-agent`, `StandardInput=socket`,
  `StandardOutput=socket`, `StandardError=journal`, `NoNewPrivileges=yes`, `ProtectSystem=strict`,
  `ProtectHome=yes`, `PrivateTmp=yes`, `PrivateDevices=yes`,
  `InaccessiblePaths=/var/lib/meridian /etc/meridian /run/meridian-docker`, `StateDirectory=meridian-run`,
  `MemoryMax=1G`, `TasksMax=256`, `CPUQuota=100%`, `RuntimeMaxSec=86400`, `KillMode=control-group`, `UMask=0077`,
  keine `LoadCredential`, `CapabilityBoundingSet=` (leer), `SystemCallFilter=@system-service`.
- `bin/meridian-shell-agent` (S11) ist PHP **ohne Datenbank, ohne Schlüssel**, lädt nur
  `Meridian\Runner\Shell\Agent\*`. Es liest einen gerahmten Auftrag vom Socket (§5.6), startet den Interpreter über
  `LocalProcessExecutor` (§5.4), reicht die Ausgabe gerahmt zurück und endet. Schließt der Worker die Verbindung
  (Abbruch, Absturz), beendet der Agent die Prozessgruppe; systemd räumt danach die ganze cgroup ab — auch Enkel und
  Zombies.
- Benutzer wählbar: **nein** (immer `meridian-run`). Arbeitsverzeichnis: je Lauf neues Verzeichnis im privaten
  `/tmp`. Weitere Profile (andere Grenzen/Pfade) = weitere Socket-Units, vom Admin auf Betriebssystemebene angelegt,
  in Meridian als `host <profil>` freigegeben.

Verworfen: *`sudo -u meridian-run`* (setuid, scheitert an `NoNewPrivileges`); *`CAP_SETUID` für den Worker +
`setpriv`* (ein kompromittierter Worker könnte uid 0 annehmen); *`systemd-run --uid=` per polkit* (Ziel-uid nicht
sauber beschränkbar); *Kindprozess als `meridian`* (liest Schlüssel und Datenbank).

### E4 – Sicherheitsmodell und Rechte

| Aktion | Recht | Kategorie | Hinweis |
|---|---|---|---|
| Shell-Job anlegen, ändern, löschen, (de)aktivieren | `jobs.edit_shell` | gespeicherte (alt **und** neu) | gefährlich → `RoleGrant::allows()` verweigert es jeder beschränkten Rolle (auch einem beschränkten Admin). Besteht schon. |
| Shell-Job sehen (Liste, Detail, Verlauf, Live-Log) | `jobs.view` | gespeicherte | wie HTTP (O5) |
| Shell-Job manuell starten, Testlauf, **Lauf abbrechen** | `jobs.run` | gespeicherte | wie HTTP (O5); H1 im `claim()` gilt unverändert |
| Ausführungsorte (Container-/Host-Allowlist) pflegen | **neu** `shell.targets` (`Permission::ManageShellTargets`) | ohne (nur uneingeschränkt) | gefährlich, per Migration nur Admin |
| `shell.max_timeout_seconds` ändern | `settings.manage` | ohne | bestehendes Recht, neuer Schlüssel |

Eigenes Recht statt `settings.manage`, weil „Container X freigeben“ eine eigene Lockerung ist (Code-Ausführung in X),
`mer-security` §4. Falls ADR 0005 (Phase 4a) die Rechteliste/-matrix neu ordnet, wird `shell.targets` dort als
gefährliches Recht eingetragen.

**Ausführungsorte** (Tabelle `shell_targets`, §3): Art `docker` (Container-**Name**) oder `host` (Profilname),
**global oder genau eine Kategorie**, erlaubte Benutzer (nur `docker`) mit Pflicht-Standardbenutzer, Notiz.
Prüfung beim Speichern **und bei jedem Lauf** (frisch, Kategorie des Jobs aus der DB): Es muss eine Freigabe mit
gleicher Art und gleichem Namen und `category_id IS NULL OR category_id = <Kategorie des Jobs>` geben, und der
gespeicherte Benutzer muss in einer dieser Freigaben erlaubt sein. Sonst `failed`, nicht wiederholbar,
„Ausführungsort nicht (mehr) freigegeben: Ein Admin kann ihn unter Einstellungen → Ausführungsorte freigeben.“
Kategorie gelöscht → Freigaben gelöscht (`ON DELETE CASCADE`), nie global. Job verschoben → alte
Kategorie-Freigaben gelten ab dem nächsten Lauf nicht mehr.

`root` als Benutzer nur, wenn der Admin ihn ausdrücklich in die Liste der Freigabe schreibt (O10); die Oberfläche
warnt. Ohne Benutzerangabe gilt der Standardbenutzer der **Freigabe**, nie der des Containers (oft `root`).

### E5 – Worker-Prozesse: Scheduler plant, Worker führen aus

```
scheduler:run           (genau einer, Sperre scheduler_lease) — planen, StaleRuns, Aufräumen; führt nichts aus
worker:run --type=http  (Aufseher) ─┬─ worker:run --type=http --child    (N Kinder, je ein Lauf zur Zeit)
                                    └─ …
worker:run --type=shell (Aufseher) ─┬─ worker:run --type=shell --child
                                    └─ …
```

- **Aufseher und Kinder:** PHP hat keine Threads; mehrere Läufe in einem Prozess zu verzahnen wäre fehleranfällig
  (ein blockierender DNS-Aufruf hielte alle auf). Der Aufseher startet `--concurrency=N` Kinder (Standard http 4,
  shell 2; `MERIDIAN_WORKER_CONCURRENCY`) mit
  `proc_open([PHP_BINARY, '<app>/bin/meridian', 'worker:run', '--type=…', '--child'], …, env: <Allowlist>)` und
  startet abgestürzte Kinder mit wachsendem Abstand neu (1, 2, 4 … 30 s). Umgebung der Kinder genau: `PATH`, `TZ`,
  `LANG`, `MERIDIAN_ENV`, `MERIDIAN_DATA_DIR`, `MERIDIAN_KEY_FILE` (Pfad, kein Geheimnis), `MERIDIAN_TIMEZONE`,
  `MERIDIAN_DOCKER_PROXY`, `MERIDIAN_SHELL_CONTAINERS`, `MERIDIAN_SHELL_HOST_SOCKETS` — nie
  `MERIDIAN_ADMIN_PASSWORD*` o. ä. Der Aufseher lädt keinen Schlüssel.
- **Getrennte Prozesse je Typ**, weil sie verschiedene Rechte brauchen (E10): Der HTTP-Worker hat Netz, aber keinen
  Proxy-Socket; der Shell-Worker hat den Proxy-Socket, aber **kein Netz** (`network_mode: none` bzw.
  `RestrictAddressFamilies=AF_UNIX`). Kein HTTP-Job erreicht den Docker-Proxy, kein Shell-Worker sendet ins Netz.
- **Kennung:** Jedes Kind hat eine eigene `worker_id` (Format wie `SchedulerLease::newOwnerId()`) und trägt sich in
  `workers` ein (`seen_at` alle 10 s). `runs.worker` = diese Kennung.
- **Übernahme ohne Sperre:** `Worker::claim()` prüft nicht mehr `lease->isHeld()`. Atomar bleibt sie über
  `UPDATE … WHERE id = :id AND status = 'queued'` mit `rowCount() === 1` in `immediate()`. Kandidaten nur für die
  eigenen Typen (`JOIN jobs j … WHERE j.type IN (SELECT value FROM json_each(:types))`). Die Überlappungsprüfung
  (`skip`/`queue`: läuft schon einer?) bleibt in derselben Transaktion; SQLite serialisiert `immediate()`, also
  können zwei Worker nie beide einen zweiten Lauf desselben Jobs starten. Abfrage alle 1 s.
- **Herzschlag:** `RunHeartbeat` bekommt die `worker_id` statt der Sperre; schreibt `heartbeat_at` gedrosselt (5 s),
  liest jede Sekunde `status, worker, cancel_requested_at`, verlängert **nie** die Scheduler-Sperre. Die Sperre
  verlängert der Planer in seinem Takt, der jetzt nie mehr durch Läufe blockiert ist.
- **Hängende Läufe:** `Worker::abortStale()` wandert in den Planer (`StaleRuns`), Regel unverändert (Herzschlag älter
  als 60 s → `aborted`). Neu: wartende Läufe eines Typs, für den seit 10 min kein Worker `seen_at` hat, und die älter
  als 10 min sind → `aborted` „Kein Worker für diesen Job-Typ aktiv: Dienst worker-shell bzw. worker-http starten.“;
  wartende Läufe ohne Job → `aborted` (bisher im `claim()`).
- **Neustart/SIGTERM:** Aufseher reicht SIGTERM an alle Kinder weiter, wartet bis 25 s, dann SIGKILL. Ein Kind
  übernimmt danach nichts mehr und bricht den laufenden Lauf ab (Shell: Prozessgruppe SIGTERM → 10 s → SIGKILL;
  HTTP: Übertragung abbrechen), speichert `aborted` mit `NOTE_WORKER_STOPPED` „Abgebrochen: Der Worker wurde beendet
  (Neustart oder Update). Der Lauf wird nicht automatisch wiederholt.“ Auf das Ende langer Shell-Läufe zu warten wäre
  unbegrenzt. Compose `stop_grace_period: 40s`, systemd `TimeoutStopSec=40`.
- **Absturz mitten im Docker-Lauf:** Der Prozess im Zielcontainer läuft weiter. `runs.exec_ref` hält Container,
  Exec-ID und Prozessgruppe; der Lauf wird nach 60 s `aborted`. Jeder Shell-Worker räumt beim Start und alle 60 s
  auf: beendete Läufe mit `exec_ref IS NOT NULL` → `GET /exec/{id}/json`; läuft er noch, Prozessgruppe beenden
  (§5.5); dann `exec_ref = NULL`. Die Prüfung über die Exec-ID verhindert, dass eine wiederverwendete PID getroffen
  wird.
- **Entwicklung/Tests:** `scheduler:run --inline-worker` führt wie bisher im Takt aus (Herzschlag verlängert dann
  zusätzlich die Sperre, `LeaseHeartbeat`). Im Betrieb nicht verwendet.

Verworfen: *ein Prozess mit Nebenläufigkeit über `stream_select`*; *`pcntl_fork`* (erbt offene SQLite-Verbindung und
geladenen Schlüssel); *Compose `deploy.replicas`* (verträgt sich nicht mit `container_name`, bei systemd fehlt die
Entsprechung); *Kinder vom Planer starten* (Planer bräuchte Schlüssel und Proxy-Zugang: eine Stelle mit allen
Rechten).

### E6 – Skript und Umgebung sind Geheimnisse; keine Anzeige

| Wert | Ort | Antwort |
|---|---|---|
| Skript | `payload_enc` | nur `has_script: true` |
| Umgebungsvariablen (Namen **und** Werte) | `payload_enc` | nur `has_env`, `env_count` (Namen verborgen wie Header-Namen in 0003, O9) |
| Ausführungsort (Art, Name), Interpreter, Benutzer, Arbeitsverzeichnis, Zeitlimit | `config_json.shell` | ja (keine Geheimnisse) |

- **Keine maskierte Anzeige des Skripts**, auch nicht der ersten Zeile oder der Länge. Die Ausnahme `display_url`
  (0003) gilt nur für HTTP-URLs; ein Skript hat keine Struktur, die sich per Allowlist sicher zeigen ließe.
- Ersetzen nur **als Ganzes** (`script`-Objekt mit Quelltext **und** Umgebung, wie `request` in 0003 E3). Grund: Die
  Web-API entschlüsselt nie, und gespeicherte Umgebungswerte lassen sich nicht in ein neues Skript „umleiten“
  (`echo "$TOKEN" | curl …`). Da nur uneingeschränkte Admins Shell-Jobs bearbeiten, schützt das vor allem gegen
  gestohlene Sitzungen.
- Bedienbarkeit: Das Skript ist nach dem Speichern nicht mehr lesbar; Hinweis im Editor auf die Versionsverwaltung.
  Ob Inhaber von `jobs.edit_shell` das Skript (ohne Umgebung) wieder lesen dürfen: Rückfrage **O2** (Empfehlung nein).

`config_json` (Version 1, Klartext, keine Geheimnisse):

```json
{
  "v": 1,
  "shell": {
    "target": { "kind": "docker", "name": "nextcloud" },
    "interpreter": "sh",
    "user": "www-data",
    "workdir": "/var/www/html",
    "timeout_seconds": 600,
    "has_script": true,
    "has_env": true,
    "env_count": 2
  }
}
```

`payload_enc` = `SecretBox::encrypt(json)`, Klartext Version 1:

```json
{ "v": 1, "mode": "script", "script": "#!/bin/sh\nset -eu\n…", "env": [["NC_TOKEN", "…"], ["MODE", "full"]] }
```

`ShellPayload::fromJson()` nimmt nur `v = 1` und `mode = script`; sonst feste Meldung „Gespeichertes Skript hat ein
unbekanntes Format. Skript im Job neu eingeben.“, `failed`, nicht wiederholbar. Formatwechsel nur beim nächsten
Speichern (wie 0003 E1).

### E7 – Lauf abbrechen

- `POST /api/runs/{id}/cancel` (§4): wartend → sofort `aborted`, Notiz „Abgebrochen vor dem Start.“; laufend →
  `cancel_requested_at = now, cancel_requested_by = <Benutzer>` (nur wenn noch NULL) und 202; fertig → 409.
- Der Worker sieht die Anforderung über `Heartbeat::beat()` (liest jede Sekunde) → `false` mit
  `stopReason() === StopReason::Cancelled`. Shell: SIGTERM an die Prozessgruppe → nach 10 s SIGKILL. HTTP:
  Übertragung abbrechen. Gespeichert wird `aborted`, Notiz „Abgebrochen durch Benutzer.“, keine Wiederholung; wer
  abgebrochen hat, steht in `cancel_requested_by` (API: `cancelled_by`).
- Gilt für alle Job-Typen, Recht `jobs.run` in der gespeicherten Kategorie, Audit `run.cancel_requested`. Nur per
  POST mit CSRF.

### E8 – Ausgabe: maskiert streamen, begrenzt speichern

Reihenfolge bleibt **maskieren → kürzen → speichern/senden**, jetzt für einen Strom:

1. **Lesen begrenzt:** Blöcke ≤ 64 KiB. Gesamt gelesene Rohbytes über `ShellRunner::MAX_RAW_BYTES` (64 MiB) →
   Prozessgruppe beenden, `failed` „Ausgabe über 64 MiB: Prozess beendet.“, nicht wiederholbar.
2. **`StreamMasker`** je Strom (stdout, stderr), zeilenweise: gibt nur vollständige Zeilen weiter, maskiert mit dem
   Masker des Laufs (bekannte Werte **und** Muster — die Muster enden an Leerraum, reichen also nie über eine Zeile).
   Eine Zeile ohne Umbruch über 8 KiB wird an der letzten Trennstelle (Leerraum oder `&#"'<>`) in den letzten 1 KiB
   geschnitten; zurückgehalten werden mindestens `längstes bekanntes Geheimnis − 1` Bytes. Ohne Trennstelle: Teil mit
   `maskCut()` behandeln **und** das letzte Wort durch `••••` ersetzen (Notiz „Lange Zeile ohne Umbruch: Teile
   verborgen.“). Am Laufende `flush()`.
3. Danach: Nicht-UTF-8 → U+FFFD (`mb_scrub`), NUL entfernen; `\r` und ANSI-Folgen bleiben Text.
4. **Live:** maskierte Stücke gebündelt (alle 500 ms oder 16 KiB) an `LiveLog::append()` → `run_log_chunks`,
   höchstens 1 MiB je Lauf, danach ein Eintrag „[Live-Log nach 1 MiB beendet, die gespeicherte Ausgabe enthält
   Anfang und Ende.]“.
5. **Gespeichert** (`runs.output`, ≤ 64 KiB): Kopf 16 KiB + `[… N B ausgelassen …]` + Ende 48 KiB des **maskierten**
   Stroms (Ringpuffer), in Lesereihenfolge, stderr-Zeilen mit Präfix `! ` (O7). Der Worker maskiert danach wie
   bisher noch einmal.
6. **Aufbewahrung:** Der Planer löscht `run_log_chunks` von Läufen, die seit 15 min beendet sind (Stapel zu 500 je
   Takt). Job gelöscht → Läufe → Stücke (`CASCADE`).

**Im Masker registrieren** (`ShellPayload::registerIn()`, sofort nach dem Entschlüsseln): das ganze Skript; jeder
Umgebungswert (ganz, und Teile zwischen `: ; = , @ /` ab 8 Zeichen); jedes token-artige Wort im Skript (≥ 8 Zeichen
aus `[A-Za-z0-9+/=_.-]` mit mindestens einer Ziffer **und** einem Buchstaben, oder ≥ 20 Zeichen ohne Leerraum);
Skriptzeilen ab 24 Zeichen. Überschießendes Maskieren ist in Kauf genommen. Werte unter 4 Zeichen erkennt der Masker
nicht (`MIN_KNOWN_LENGTH`) — Hinweis im Editor.

### E9 – Live-Log per Server-Sent Events, mit Grenzen und Rückfall

- Kanonisch ist `GET /api/runs/{id}/log?after=<seq>` (kurze Antwort). SSE (`GET /api/runs/{id}/live`) liefert
  dieselben Stücke als Push; die Oberfläche fällt bei 429/Fehler auf Abfragen alle 2 s zurück.
- **Thread-Budget:** höchstens `MERIDIAN_LIVE_STREAMS` (Standard 4) gleichzeitig, höchstens 2 je Benutzer, belegt über
  Tabelle `live_streams` (zählen und einfügen in einer `immediate()`-Transaktion). FrankenPHP bekommt
  `num_threads 16` (S9). Jede Verbindung endet nach 60 s mit `event: reconnect`; der Browser verbindet sich mit
  `Last-Event-ID` neu (`retry: 2000`). So hängt kein Thread länger als 60 s an einem Client.
- Details §6.

Verworfen: *WebSocket* (eigenes Protokoll, Caddy-Konfiguration, CSRF-Fragen); *nur Abfragen* (PLAN verspricht
Live-Log; bleibt Rückfall); *Live-Stücke im Arbeitsspeicher des Workers mit eigenem Port* (Web und Worker sind
getrennte Container, der Shell-Worker hat kein Netz).

### E10 – Docker nur über einen Socket-Proxy mit Pfad-Allowlist, per Unix-Socket

- **Proxy-Image:** `wollomatic/socket-proxy:1.13.1` (Version **und** Digest, Rückfrage O1). `tecnativa` kennt nur
  Abschnitte: Für `POST /containers/{id}/exec` braucht es `CONTAINERS=1` **und** `POST=1` — das erlaubt auch
  `POST /containers/create` (mit `Privileged`, Host-Mounts) und `start`/`kill`/`rm` = Root auf dem NAS. wollomatic
  prüft Methode **und Pfad** per regulärem Ausdruck (`^…$` wird ergänzt), lauscht auf Wunsch auf einem Unix-Socket
  (`-proxysocketendpoint`, TCP dann aus) und läuft mit `read_only`, `cap_drop: ALL`.
- **Erlaubt** (alles andere 403), jeweils als **eine** äußere Gruppe:
  - `GET` `((/v1\.[0-9]{2})?/_ping|/v1\.[0-9]{2}/(version|containers/(${MERIDIAN_SHELL_CONTAINERS})/json|exec/[0-9a-f]{64}/json))`
  - `HEAD` `((/v1\.[0-9]{2})?/_ping)`
  - `POST` `(/v1\.[0-9]{2}/(containers/(${MERIDIAN_SHELL_CONTAINERS})/exec|exec/[0-9a-f]{64}/start))`
- `MERIDIAN_SHELL_CONTAINERS` (Compose-Variable, z. B. `meridian-sandbox|nextcloud|paperless`) ist die **zweite
  Schicht** der Container-Allowlist, gepflegt vom Betreiber in der `.env`. Namen nur aus `[A-Za-z0-9_-]` (ein Punkt
  wäre im regulären Ausdruck ein Joker). Meridians Allowlist (`shell_targets`) ist die erste Schicht; was der Proxy
  nicht zulässt, scheitert mit 403 → Notiz „Der Docker-Proxy lässt diesen Container nicht zu: Namen in
  MERIDIAN_SHELL_CONTAINERS (compose/.env) eintragen.“ Der Shell-Worker vergleicht beim Start und warnt (nur Namen).
- **Grenze des Proxys:** Er sieht keine Anfragekörper. Ein kompromittierter Shell-Worker könnte in einem
  freigegebenen Container `Privileged: true` oder `User: root` setzen. Meridian setzt `Privileged` immer `false` und
  den Benutzer nur aus der Freigabe. Restrisiko (§12): Wer den Worker übernimmt, hat ohnehin Schlüssel und Datenbank.
- **Unix-Socket statt TCP:** Der Proxy hat `network_mode: none` und legt seinen Socket
  (`-proxysocketendpoint=/run/proxy/docker.sock`, `-proxysocketendpointfilemode=0666`) in ein **eigenes Volume**
  `meridian-docker-proxy`, das nur der Shell-Worker einbindet. Der Proxy hat damit **keine IP-Adresse**: kein HTTP-Job,
  kein Sandbox-Skript, keine Weboberfläche erreicht ihn. `MERIDIAN_DOCKER_PROXY` nimmt nur `unix:///…`; `tcp://`
  wird beim Start abgelehnt („Nur ein Unix-Socket ist erlaubt, siehe docs/decisions/0004 E10.“).
- **Nicht erreichbar** (Socket fehlt, `connect` scheitert, Zeitlimit 5 s): `failed`, wiederholbar, „Docker-Proxy nicht
  erreichbar: Dienst docker-proxy prüfen (docker compose ps).“ Der Worker läuft weiter und warnt alle 5 min.
- **systemd-Installation:** Ohne Docker gibt es nur `host`-Ausführungsorte (E3) bzw. bis S11 gar keine; der Editor
  meldet dann „Noch kein Ausführungsort freigegeben“. Mit Docker auf dem Host: Proxy als Container mit
  `-v /run/meridian-docker:/run/proxy` (Befehl als Hinweis im Installer); Verzeichnis `/run/meridian-docker`
  `root:meridian 0750` (`tmpfiles.d`). Nur `meridian-worker@shell` bekommt `ReadWritePaths=/run/meridian-docker`;
  Web und Planer `InaccessiblePaths=/run/meridian-docker`.

Verworfen: *`/var/run/docker.sock` direkt* (Root); *tecnativa mit `CONTAINERS=1 EXEC=1 POST=1`* (s. o.); *Proxy per
TCP im Standardnetz* (jede Adresse im Netz wäre ein Root-Zugang, HTTP-Jobs müssten ihn per IP-Sperre meiden);
*Docker-CLI im Image* (größer, gleiche Rechtefrage).

### E11 – HTTP-Jobs erreichen den Proxy nie (TODO aus 0003 E5)

Strukturell: kein TCP-Endpunkt, HTTP-Worker ohne Socket-Volume. Zusätzlich (wird parallel zu diesem Entwurf schon
als `Runner\Http\InfrastructureTargets` umgesetzt, Entscheidung Alex 09.10.2026; S10 prüft nur noch das Zusammenspiel):
`AddressPolicy` sperrt die
Docker-API-Ports **2375, 2376** auf jeder Adresse der Klassen „privat“ und „Loopback“ als `BlockReason::Infrastructure`
(nie freigebbar); `InternalTarget::create()` lehnt Freigaben mit diesen Ports ab (422 „Docker-API-Ports sind nie
freigebbar.“). Das trifft einen Proxy, den ein Betreiber gegen die Empfehlung per TCP betreibt.

### E12 – Zeitlimit, Ressourcen, Benutzer, Arbeitsverzeichnis, Umgebung

- **Zeitlimit** je Job, Standard 300 s, 1 bis `shell.max_timeout_seconds` (Standard 3600, erlaubt 1–86400, O8).
  Läufe blockieren den Planer nicht mehr (E5). Runner nutzt `min(Job, Maximum)` frisch je Lauf (Notiz bei Kappung).
  Ablauf → SIGTERM an die Gruppe → 10 s → SIGKILL → `timeout` „Zeitlimit von N s überschritten, Prozess beendet.“
- **Ressourcen:** `docker exec` läuft in der cgroup des Zielcontainers; Grenzen sind die des Containers (Sandbox:
  512 MiB, 256 Prozesse, 1 CPU). Die Exec-API kann keine eigenen setzen. Host: Grenzen der Unit (E3).
- **Benutzer:** nur aus der Freigabe (E4), `^[a-z_][a-z0-9_-]{0,31}$` oder `^[0-9]{1,10}(:[0-9]{1,10})?$`.
- **Arbeitsverzeichnis** (`docker`): `null` (Container-Standard) oder absoluter Pfad
  `^/(?:[A-Za-z0-9._-]+/?){0,32}$`, ≤ 255 Byte, kein Segment `.`/`..`; ablehnen statt bereinigen.
- **Interpreter:** `sh` · `bash`. Fehlt er im Container (Exit 126/127 ohne Ausgabe) → `failed` „Interpreter im
  Container nicht gefunden.“, nicht wiederholbar.
- **Umgebung:** Docker-Exec erbt die `Env` des Containers (dessen Sache) plus Job-Variablen plus `MERIDIAN_JOB_ID`,
  `MERIDIAN_RUN_ID`, `MERIDIAN_TRIGGER`, `TZ` (Zeitzone des Jobs). Host: **genau** `PATH=/usr/local/bin:/usr/bin:/bin`,
  `LANG=C.UTF-8`, `HOME=<StateDirectory>`, `TZ`, die drei `MERIDIAN_*`-Werte und die Job-Variablen. Namen
  `^[A-Z_][A-Z0-9_]{0,63}$`; verboten (Groß/klein egal): `LD_*`, `BASH_ENV`, `ENV`, `SHELLOPTS`, `BASHOPTS`, `IFS`,
  `PS4`, `PROMPT_COMMAND`, `MERIDIAN_*`, `PATH`, `HOME`, `TZ`. ≤ 50 Variablen, Wert ≤ 4096 Byte UTF-8 ohne NUL,
  Summe ≤ 32 KiB. Hinweis: Im Zielcontainer sind Umgebungswerte für Prozesse desselben Benutzers über
  `/proc/<pid>/environ` lesbar.

---

## 3. Datenmodell

Zwei Migrationen, damit S2 (Worker) ohne die Shell-Tabellen landen kann. **Nummern:** die nächsten freien nach den
Migrationen aus Phase 4a (ADR 0005, `0009_users_roles.sql`); belegt sind bei Abfassung `0008_display_host.sql`
(Einstellung `http.display_host`) und 0009 → voraussichtlich `0010` und `0011`. S1 prüft `migrations/` und nimmt die
nächsten freien Nummern.

### 3.1 `NNNN_workers_and_live_log.sql`

```sql
-- Phase 4 (docs/decisions/0004 §3.1): getrennte Worker-Prozesse, Abbruch durch Benutzer, Live-Log.

-- Abbruch: angefordert von einem Benutzer; der Worker sieht es über den Herzschlag.
ALTER TABLE runs ADD COLUMN cancel_requested_at TEXT;
ALTER TABLE runs ADD COLUMN cancel_requested_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
-- Verweis auf einen Prozess außerhalb von Meridian (Docker-Exec: Container, Exec-ID, Prozessgruppe) als JSON,
-- damit ein neu gestarteter Worker übrig gebliebene Prozesse beenden kann. Nie in API-Antworten (wie worker).
ALTER TABLE runs ADD COLUMN exec_ref TEXT CHECK (exec_ref IS NULL OR json_valid(exec_ref));
-- Gelesene Rohbytes der Ausgabe (auch der nicht gespeicherten), für „N B ausgelassen“.
ALTER TABLE runs ADD COLUMN output_bytes INTEGER;
CREATE INDEX runs_exec_ref ON runs (status) WHERE exec_ref IS NOT NULL;

-- Laufende Worker-Prozesse (Kinder). Nur Laufzustand: schreiben dürfen nur Worker selbst (und der Planer räumt auf).
CREATE TABLE workers (
    id         TEXT    PRIMARY KEY CHECK (length(id) BETWEEN 1 AND 200),
    kind       TEXT    NOT NULL CHECK (kind IN ('http', 'shell')),
    host       TEXT    NOT NULL CHECK (length(host) <= 64),
    pid        INTEGER NOT NULL,
    -- Fähigkeiten ohne Geheimnisse, z. B. {"docker":true,"host_profiles":["default"]}
    caps_json  TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(caps_json)),
    started_at TEXT    NOT NULL,
    seen_at    TEXT    NOT NULL
);
CREATE INDEX workers_kind_seen ON workers (kind, seen_at);

-- Live-Log: maskierte Stücke laufender Läufe. Wird 15 min nach Laufende gelöscht.
CREATE TABLE run_log_chunks (
    id         INTEGER PRIMARY KEY,
    run_id     INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
    seq        INTEGER NOT NULL CHECK (seq >= 1),
    stream     TEXT    NOT NULL CHECK (stream IN ('out', 'err', 'sys')),
    -- Bereits maskiert (Masker des Laufs), UTF-8, höchstens 16 KiB je Stück.
    data       TEXT    NOT NULL CHECK (length(CAST(data AS BLOB)) <= 16384),
    created_at TEXT    NOT NULL,
    UNIQUE (run_id, seq)
);

-- Offene SSE-Verbindungen (Obergrenze gegen erschöpfte PHP-Threads).
CREATE TABLE live_streams (
    id         TEXT    PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    run_id     INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
    expires_at TEXT    NOT NULL
);
CREATE INDEX live_streams_expires ON live_streams (expires_at);
```

### 3.2 `NNNN_shell_jobs.sql`

```sql
-- Phase 4: Shell-Jobs (docs/decisions/0004 §3.2).
-- Neues gefährliches Recht: Ausführungsorte pflegen. Nur Rolle Admin, ergänzt nur Fehlendes.
INSERT INTO role_permissions (role_id, permission)
    SELECT r.id, 'shell.targets' FROM roles r
     WHERE r.name = 'Admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'shell.targets');

-- Ausführungsorte (Allowlist). kind = 'docker': name = Container-Name; kind = 'host': name = Profil.
-- category_id NULL = global; Kategorie gelöscht -> Freigabe gelöscht (CASCADE), nie global.
CREATE TABLE shell_targets (
    id           INTEGER PRIMARY KEY,
    kind         TEXT    NOT NULL CHECK (kind IN ('docker', 'host')),
    name         TEXT    NOT NULL CHECK (length(name) BETWEEN 1 AND 128),
    category_id  INTEGER REFERENCES categories(id) ON DELETE CASCADE,
    -- Erlaubte Benutzer im Container (nur docker), JSON-Liste von Texten; default_user muss darin stehen.
    users_json   TEXT    NOT NULL DEFAULT '[]' CHECK (json_valid(users_json) AND json_type(users_json) = 'array'),
    default_user TEXT    CHECK (default_user IS NULL OR length(default_user) BETWEEN 1 AND 32),
    note         TEXT    NOT NULL DEFAULT '' CHECK (length(note) <= 200),
    created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at   TEXT    NOT NULL,
    CHECK (kind = 'host' OR default_user IS NOT NULL)
);
CREATE UNIQUE INDEX shell_targets_unique ON shell_targets (kind, name, COALESCE(category_id, 0));
CREATE INDEX shell_targets_category ON shell_targets (category_id);

-- settings: neuer Schlüssel shell.max_timeout_seconds. SQLite ändert kein CHECK -> Tabelle neu aufbauen.
-- Schlüsselliste = alle bis dahin erlaubten (0007, 0008 http.display_host, ggf. Phase 4a) + shell.max_timeout_seconds.
CREATE TABLE settings_new (
    key        TEXT    PRIMARY KEY CHECK (key IN ('http.max_timeout_seconds', 'http.response_storage', 'http.display_path', 'http.display_host', 'shell.max_timeout_seconds')),
    value_json TEXT    NOT NULL CHECK (json_valid(value_json)),
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT    NOT NULL
);
INSERT INTO settings_new (key, value_json, updated_by, updated_at) SELECT key, value_json, updated_by, updated_at FROM settings;
DROP TABLE settings;
ALTER TABLE settings_new RENAME TO settings;
```

`jobs` bleibt unverändert (`type` erlaubt `shell` seit 0001; Shell-Jobs gibt es noch nicht). `config_json` eines
Shell-Jobs nur über `ShellJobConfig::fromJson()` (streng, `v` Pflicht); unlesbar → Detail `shell: null`, Runner
`failed`, nicht wiederholbar.

Wer schreibt was (`mer-storage` §4): `workers`, `run_log_chunks`, `runs.exec_ref/output_bytes` → nur Worker;
`runs.cancel_requested_*` → nur `RunService::cancel()`; `live_streams` → nur der SSE-Endpunkt; Aufräumen von
`run_log_chunks`, `live_streams`, `workers` (älter 1 h) → Planer. `shell_targets`, `settings` → nur Admin-API/CLI;
Hintergrundprozesse lesen nur.

---

## 4. API

Ablauf jedes Endpunkts wie 0003 §4.1 (Sitzung → CSRF bei POST/PUT/DELETE → validieren → `findVisible` → `require`
mit gespeicherter Kategorie → Transaktion → Audit → Feld-Allowlist, maskiert, `no-store`).

### 4.1 Endpunkte und Rechte

„Op/A“ = Operator nur Kategorie A; Job X liegt in B, N ohne Kategorie. „Admin/A“ = Admin-Rolle beschränkt auf A.

| Methode und Pfad | Recht (Kategorie aus der DB) | Admin | Admin/A | Op | Op/A auf A · B/N | Beob. | anonym |
|---|---|---|---|---|---|---|---|
| `POST /api/jobs` mit `type: shell` | `jobs.edit_shell` in der Zielkategorie | 201 | 403 | 403 | 403 | 403 | 401 |
| `PUT /api/jobs/{id}` (Shell-Job) | `jobs.edit_shell` alt **und** neu | 200 | 403 | 403 | 403 · 404 | 403 | 401 |
| `DELETE`, `/enable`, `/disable` (Shell-Job) | `jobs.edit_shell` | 2xx | 403 | 403 | 403 · 404 | 403 | 401 |
| `GET /api/jobs`, `/api/jobs/{id}`, `/runs` (Shell-Job) | `jobs.view` | 200 | 200 auf A | 200 | 200 · 404 | 200 | 401 |
| `POST /api/jobs/{id}/run` · `/test` (Shell-Job) | `jobs.run` | 202 | 202 auf A | 202 | 202 · 404 | 403 | 401 |
| `POST /api/runs/{id}/cancel` (alle Typen) | `jobs.run` | 202/200 | auf A | 202/200 | 202 · 404 | 403 | 401 |
| `GET /api/runs/{id}/log?after=` | `jobs.view` | 200 | auf A | 200 | 200 · 404 | 200 | 401 |
| `GET /api/runs/{id}/live` (SSE) | `jobs.view` | 200 | auf A | 200 | 200 · 404 | 200 | 401 |
| `GET /api/shell/targets?category_id=` | `jobs.edit_shell` (uneingeschränkt) | 200 | 403 | 403 | 403 | 403 | 401 |
| `GET /api/settings/shell-targets` | `shell.targets` | 200 | 403 | 403 | 403 | 403 | 401 |
| `POST /api/settings/shell-targets` | `shell.targets` | 201 | 403 | 403 | 403 | 403 | 401 |
| `DELETE /api/settings/shell-targets/{id}` | `shell.targets` | 204 | 403 | 403 | 403 | 403 | 401 |
| `PUT /api/settings/shell.max_timeout_seconds` | `settings.manage` | 200 | 403 | 403 | 403 | 403 | 401 |

`GET /api/jobs/limits` liefert zusätzlich `shell_max_timeout_seconds`. Admin/A bei `POST /api/jobs` (shell): 403,
weil der Bereich für `jobs.edit_shell` leer ist (`CategoryScope::isEmpty()`). Typwechsel beim Ändern → 422.

### 4.2 Anfrage und Antwort (Shell-Job)

```json
{
  "name": "Nextcloud-Wartung", "type": "shell", "category_id": 3,
  "cron": "30 3 * * *", "timezone": "Europe/Berlin", "is_enabled": true,
  "overlap_policy": "skip", "retry_count": 1, "retry_delay_seconds": 300, "catch_up": true,
  "shell": { "target": { "kind": "docker", "name": "nextcloud" }, "interpreter": "sh",
             "user": "www-data", "workdir": "/var/www/html", "timeout_seconds": 900 },
  "script": { "source": "php occ maintenance:repair\n", "env": [{ "name": "NC_TOKEN", "value": "…" }] }
}
```

- `script` beim Anlegen Pflicht, beim Ändern optional; ist es da, ersetzt es Quelltext **und** Umgebung (fehlende
  `env` = keine) und erzeugt `has_script`, `has_env`, `env_count` neu.
- `shell` beim Ändern teilweise: fehlende Felder = gespeicherte Werte. `user: null` = Standardbenutzer der Freigabe;
  gespeichert wird der aufgelöste Name, damit eine spätere Änderung des Standards den Job nicht still umstellt.
- Detail `shell`: `{target: {kind, name}, interpreter, user, workdir, timeout_seconds, has_script, has_env,
  env_count}`; Liste: `shell: {target: {kind, name}}` (Spalte ZIEL: `nextcloud` bzw. `Host · default`).
- Lauf zusätzlich: `exit_code`, `cancel_requested_at`, `cancelled_by {id, display_name}|null`, `output_bytes`,
  `live` (bool, es gibt Stücke). **Nie** `worker`, `heartbeat_at`, `exec_ref` (H2-Test erweitern).

### 4.3 Validierung (Ergänzung zu 0003 §3.2)

| Feld | Regel |
|---|---|
| `type` | `http` · `shell`; Recht nach Typ (`JobPermissions::edit()`) |
| `shell.target.kind` / `.name` | `docker` · `host`; Name `docker`: `^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$` (kein Punkt, passend zur Proxy-Regel), `host`: `^[a-z][a-z0-9-]{0,31}$`; Freigabe für die **Zielkategorie** (global oder genau diese) nötig. Unbekannt **oder** nicht erlaubt → eine Meldung „Ausführungsort unbekannt oder für diese Kategorie nicht freigegeben.“ |
| `shell.interpreter` | `sh` · `bash`, Standard `sh` |
| `shell.user` | `null` oder in `users_json` einer passenden Freigabe; bei `host` immer `null` |
| `shell.workdir` | `null` oder Regel E12; bei `host` immer `null` |
| `shell.timeout_seconds` | 1 bis `shell.max_timeout_seconds`, Standard `min(300, Maximum)` |
| `script.source` | 1–262 144 Byte, gültiges UTF-8, kein NUL, mindestens ein Nicht-Leerraum-Zeichen |
| `script.env` | ≤ 50 `{name, value}` nach E12; doppelte Namen → 422 |
| Anfragekörper gesamt | ≤ 320 KiB für Shell-Jobs, Tiefe ≤ 5 |
| Freigabe (`POST /api/settings/shell-targets`) | `{kind, name, category_id, users, default_user, note}`; `users` 1–10 Einträge nach E12, `default_user ∈ users` (Pflicht bei docker); bei host `users = []`, `default_user = null`; doppelt → 422; unbekannte Felder → 422 |

Meldungen nennen nie den Eingabewert.

### 4.4 Audit-Einträge

| Aktion | `target` |
|---|---|
| `job.created` / `job.updated` | wie 0003; Feldnamen `shell.target`, `shell.interpreter`, `shell.user`, `shell.workdir`, `shell.timeout_seconds`, „Skript ersetzt“ (nie Inhalt, nie Variablennamen) |
| `run.cancel_requested` | `job:12 Nextcloud-Wartung; run:991[; vor dem Start]` |
| `shell.target_added` / `shell.target_removed` | `docker nextcloud (Kategorie NAS; Benutzer www-data, Standard www-data)` bzw. `host default (global)` |
| `settings.changed` | `shell.max_timeout_seconds: 3600 → 7200` |

Schreiben und Audit in **einer** `immediate()`-Transaktion.

### 4.5 CLI

`shell:targets list | add <docker|host> <name> [--category=NAME] [--user=U …] [--default-user=U] [--note=…] |
remove <id>` (Audit ohne Benutzer, dieselbe Prüfung wie die API über `ShellTargetStore::validate()`);
`worker:run --type=http|shell [--concurrency=N] [--child]`; `scheduler:run [--inline-worker]`.

---

## 5. Worker und Shell-Runner

### 5.1 Schnittstellen (Skizze)

```php
namespace Meridian\Runner;

enum StopReason: string { case Stale = 'stale'; case Cancelled = 'cancelled'; case WorkerStopping = 'worker_stopping'; }

interface Heartbeat
{
    public const int MAX_INTERVAL_SECONDS = 20;
    /** @phpstan-impure wirft nie; false = sofort aufhören (Grund über stopReason()). */
    public function beat(): bool;
    public function stopReason(): ?StopReason;
}

/** Maskierte Stücke für das Live-Log; begrenzt auf MAX_BYTES_PER_RUN; wirft nie. */
interface LiveLog
{
    public const int MAX_BYTES_PER_RUN = 1048576;
    public function append(LiveStream $stream, #[\SensitiveParameter] string $maskedText): void;
    public function flush(): void;
}
enum LiveStream: string { case Out = 'out'; case Err = 'err'; case Sys = 'sys'; }

interface Runner
{
    public function run(RunRequest $request, Heartbeat $heartbeat, SecretMasker $masker, LiveLog $live): RunResult;
}

final readonly class RunResult
{
    /* bisher + */ public ?int $outputBytes;
    public static function aborted(string $output, string $note): self;   // nie wiederholbar
}
```

```php
namespace Meridian\Schedule;

final class Worker            // nur Übernehmen + Ausführen; kennt workerId und Typen, nicht die Sperre
{
    /** @param non-empty-list<JobType> $types */
    public function __construct(Connection $db, Clock $clock, WorkerIdentity $me, array $types,
                                RunnerRegistry $runners, RunAuthorizer $authorizer, LiveLogFactory $live);
    public function work(callable $stopRequested): ?RunEvent;   // höchstens ein Lauf je Aufruf
}
final class StaleRuns         // im Planer: hängende/verwaiste Läufe, kein Worker für Typ, Aufräumen der Live-Tabellen
final class WorkerSupervisor  // Aufseher: Kinder starten/neu starten, Signale weiterreichen
final readonly class WorkerIdentity { public string $id; public JobType $kind; }
final class RunHeartbeat implements Heartbeat   // (db, clock, workerId, runId): liest Abbruch je 1 s, schreibt je 5 s
final class LeaseHeartbeat implements Heartbeat // nur --inline-worker: dekoriert RunHeartbeat, verlängert die Sperre
```

```php
namespace Meridian\Runner\Shell;

final class ShellPayload      // Werte in Sealed, __debugInfo nur Flags/Anzahl, __serialize wirft; fromJson() nur v=1/script
{
    public static function fromJson(#[\SensitiveParameter] string $json): self;
    public function toJson(): string;
    public function registerIn(SecretMasker $masker): void;        // E8
    public function script(): string;
    /** @return list<array{0:string,1:string}> */ public function env(): array;
}
final readonly class ShellJobConfig { /* target, interpreter, user, workdir, timeoutSeconds, hasScript, hasEnv, envCount */
    public static function fromJson(string $json): self; }
final readonly class ShellTarget { public ShellTargetKind $kind; public string $name; }
interface ShellTargetSource { /** @return list<ShellTargetGrant> */ public function grantsFor(ShellTarget $t, ?int $categoryId): array; }
final class ShellTargetPolicy { public function check(ShellTarget $t, ?string $user, ?int $categoryId): ?ShellTargetRefusal; } // null = erlaubt

final readonly class ExecSpec  { /* runId, target, interpreter, user, workdir, nonce (hex 32), Sealed script, Sealed env */ }
interface Executor   { public function start(#[\SensitiveParameter] ExecSpec $spec): Execution; } // wirft ExecFailed (fester Text + retryable)
interface Execution
{
    /** Wartet höchstens $maxWait s; null = nichts Neues. Blöcke ≤ 64 KiB. */
    public function read(float $maxWait): ?OutputBlock;
    public function finished(): bool;
    public function exitCode(): ?int;
    public function terminate(): void;   // SIGTERM an die Prozessgruppe
    public function kill(): void;        // SIGKILL an die Prozessgruppe
    public function ref(): ?ExecRef;     // für runs.exec_ref (Docker), sonst null
    public function close(): void;       // räumt auf, reapt, idempotent
}
final readonly class OutputBlock { public LiveStream $stream; /* Bytes in Sealed */ }
final class DockerExecExecutor implements Executor      // §5.5
final class HostSocketExecutor implements Executor      // §5.6 (S11)
final class LocalProcessExecutor implements Executor    // proc_open; im Host-Agenten und in Tests, nie im Worker registriert
final class StreamMasker    // E8: zeilenweise, Überhang, feed()/flush()
final class OutputCollector // Kopf 16 KiB + Ende 48 KiB, zählt Rohbytes
final class ShellRunner implements Runner               // §5.3
interface ShellJobSource { public function load(int $jobId): ?StoredShellJob; } // type, category_id, config_json, payload_enc
```

`#[\SensitiveParameter]` an jedem Parameter mit Skript, Umgebung, Ausgabeblock, `ExecSpec`, Rahmen-Daten. Klassen mit
Geheimnissen (`ShellPayload`, `ExecSpec`, `OutputBlock`) in den Darstellungs-Test aufnehmen.

### 5.2 Worker-Ablauf (Kind)

1. Start: `KeyLoader::load()`, Eintrag in `workers`, SIGTERM → Flag.
2. Schleife: `seen_at` alle 10 s; `claim()` alle 1 s; Lauf ausführen mit `RunHeartbeat`, Masker je Lauf,
   `DbLiveLog` je Lauf; Ergebnis speichern wie bisher (`WHERE status = 'running' AND worker = :me`).
3. Nach dem Lauf: `Cancelled` → `aborted` „Abgebrochen durch Benutzer.“; `WorkerStopping` → `aborted`
   `NOTE_WORKER_STOPPED`; `Stale` → nichts speichern (wie bisher). Nie Wiederholung bei `aborted`.
4. Shell-Kinder: beim Start und alle 60 s `exec_ref`-Aufräumen (E5).
5. Ende: eigene `workers`-Zeile löschen.

### 5.3 Ablauf eines Shell-Laufs (`ShellRunner`)

1. `ShellJobSource::load()`; fehlt der Job oder `type ≠ shell` → `failed`, nicht wiederholbar. `ShellJobConfig`
   streng lesen. Einstellungen frisch: Zeitlimit `min(Job, shell.max_timeout_seconds)`.
2. `ShellTargetPolicy::check(target, user, Kategorie aus der DB)` → Ablehnung = `failed`, nicht wiederholbar.
3. `beat()`. `payload_enc` entschlüsseln, `ShellPayload::fromJson()`, **sofort** `registerIn($masker)`.
4. `ExecSpec` (Nonce `bin2hex(random_bytes(16))`), `Executor::start()`, `ref()` sofort in `runs.exec_ref` (über ein
   Worker-eigenes Objekt; der Runner selbst schreibt nicht in die DB).
5. Schleife bis `finished()`: `read(min(1.0, Restzeit))` → Rohbytes zählen → `StreamMasker::feed()` → maskierte
   Zeilen an `OutputCollector` und `LiveLog`; nach jedem Durchgang `beat()` (drosselt selbst).
   `beat() === false` → `terminate()`, bis 10 s weiter leeren (nicht speichern), dann `kill()`, `aborted`.
   Restzeit ≤ 0 → `terminate()` → 10 s → `kill()` → `timeout`. Rohbytes > 64 MiB → ebenso, `failed`.
6. `StreamMasker::flush()`, `LiveLog::flush()`, `close()`.
7. Bewertung: Exit 0 → `ok`; sonst `failed` „Beendet mit Exit-Code N.“ (wiederholbar); Signal → „Beendet durch Signal
   N.“; 126/127 ohne Ausgabe → Interpreter fehlt (nicht wiederholbar).
8. Ausgabe = `OutputCollector::text()` (maskiert; Worker maskiert erneut und kürzt sicher).

Fehlerzuordnung (feste Texte, nie Meldungen aus Docker oder dem System):

| Fall | Status | wiederholbar |
|---|---|---|
| Proxy nicht erreichbar / Verbindung abgebrochen | `failed` „Docker-Proxy nicht erreichbar …“ | ja |
| Proxy 403 | `failed` „Der Docker-Proxy lässt diesen Container nicht zu …“ | nein |
| Container 404 / nicht laufend (409) | `failed` „Container nicht gefunden oder gestoppt.“ | ja |
| Ausführungsort nicht freigegeben | `failed` (E4) | nein |
| Skript unlesbar | `failed` (E6) | nein |
| Zeitlimit | `timeout` | ja |
| Exit ≠ 0 | `failed` | ja |
| Ausgabe > 64 MiB | `failed` | nein |
| Host-Agent-Socket fehlt | `failed` „Host-Ausführung nicht eingerichtet: meridian-shell.socket prüfen.“ | ja |

### 5.4 `LocalProcessExecutor` (Pflicht-Pattern `mer-runner` §1)

```php
$process = proc_open(
    ['/usr/bin/setsid', $interpreter, '-s'],                     // Argument-Array; setsid → eigene Prozessgruppe
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $workDir,                                                     // je Lauf neu, 0700
    $env,                                                         // explizit (E12), nie null
);
```

- Das Kind von `proc_open` ist nie Gruppenführer → `setsid` forkt nicht, PID = PGID. Nach dem Start prüfen
  (`posix_getpgid($pid) === $pid`), sonst sofort beenden und `failed` (fester Text).
- Skript über stdin, nicht blockierend in Stücken schreiben (`stream_select` auf Schreiben), danach stdin schließen.
- Lesen: `stream_set_blocking(false)`, `stream_select([$out, $err], …, ≤ 1 s)`, `fread` ≤ 64 KiB.
- `terminate()` = `posix_kill(-$pgid, SIGTERM)`, `kill()` = `posix_kill(-$pgid, SIGKILL)`; danach reapen
  (`proc_get_status` bis `running = false`, `proc_close`). Enkel mit eigener Sitzung erreicht die Gruppe nicht → Host:
  systemd räumt die cgroup (E3); Container: `init: true`.
- Braucht `ext-posix` (in den PHP-Images enthalten; Build und Installer prüfen es zusätzlich zu `pcntl`).

### 5.5 `DockerExecExecutor`

- JSON-Aufrufe über curl mit `CURLOPT_UNIX_SOCKET_PATH` (aus `MERIDIAN_DOCKER_PROXY`), URL `http://docker/v1.41/…`,
  Zeitlimit 10 s, Antwort ≤ 1 MiB, nie `curl_error()` in Notizen. API-Version beim Start über `GET /_ping` (Header
  `API-Version`, mindestens 1.41); sonst Warnung, Ausführungsort nicht nutzbar.
- `GET /containers/{name}/json` → `State.Running`, sonst „Container nicht gefunden oder gestoppt.“
- `POST /containers/{name}/exec` mit
  `{"AttachStdin":true,"AttachStdout":true,"AttachStderr":true,"Tty":false,"Privileged":false,"User":U,
  "WorkingDir":W,"Env":[…],"Cmd":["/bin/sh","-c",WRAP,"meridian-wrap",INTERP,NONCE,LEN,PAYLOAD_WRAP]}`.
  Alles im `Cmd` ist konstant oder validiert (Interpreter-Enum, Nonce-Hex, Länge als Zahl). Das Skript ist **nie**
  Teil des `Cmd` (Prozessliste, Docker-Log).
- `POST /exec/{id}/start` mit `{"Detach":false,"Tty":false}` über einen eigenen Socket
  (`stream_socket_client('unix://…')`), Header `Connection: Upgrade`, `Upgrade: tcp`; Antwort 101 (oder 200), dann
  Docker-Mehrfachstrom: Rahmen `[typ:1][0:3][länge:4 BE][daten]`, typ 1 = stdout, 2 = stderr; Rahmen > 1 MiB →
  Protokollfehler. Lesen mit `stream_select` ≤ 1 s.
- **Skript ohne stdin-Ende:** Ein Go-`httputil.ReverseProxy` beendet eine hochgestufte Verbindung, sobald eine
  Richtung endet — ein halbes Schließen (`STREAM_SHUT_WR`) würde die Ausgabe abschneiden. Deshalb liest der Wrapper
  genau `LEN` Bytes (`head -c`), und Meridian hält die Schreibseite bis zum Laufende offen.
- **Wrapper (Kandidat; exakte Fassung und Prüfung in S8):**
  ```sh
  # WRAP (konstant): eigene Prozessgruppe herstellen, ohne dass setsid forkt
  if [ "$(cut -d' ' -f5 /proc/$$/stat 2>/dev/null)" != "$$" ] && command -v setsid >/dev/null 2>&1; then
    exec setsid /bin/sh -c "$4" meridian-run "$1" "$2" "$3"
  fi
  exec /bin/sh -c "$4" meridian-run "$1" "$2" "$3"
  # PAYLOAD_WRAP (konstant, = $4): Kennung melden, genau LEN Bytes Skript an den Interpreter
  printf 'MERIDIAN-PGID %s %s %s\n' "$2" "$$" "$(cut -d' ' -f5 /proc/$$/stat 2>/dev/null)" >&2
  head -c "$3" | "$1" -s
  ```
  Ist das Exec-Kind kein Gruppenführer, forkt `setsid` nicht (PID bleibt, wird Gruppenführer); ist es schon einer,
  ist PGID = PID. Die erste stderr-Zeile muss `MERIDIAN-PGID <nonce> <pid> <pgrp>` sein, sonst Protokollfehler →
  beenden; sie wird nie ausgegeben. `pid ≠ pgrp` (kein `setsid` im Container) → Abbruch nur der PID, Notiz
  „Container ohne setsid: Kindprozesse werden beim Abbruch eventuell nicht beendet.“
- `terminate()`/`kill()`: neuer Exec im selben Container mit
  `Cmd: ["/bin/sh","-c","kill -s \"$1\" -- \"-$2\" 2>/dev/null || kill -s \"$1\" \"$2\"","meridian-kill","TERM",PGID]`
  (PGID validierte Zahl), Benutzer wie der Lauf. Vorher `GET /exec/{id}/json`: `Running = false` → nichts tun.
- Ende: Strom-EOF → `GET /exec/{id}/json` → `ExitCode`. EOF bei `Running = true` → `failed` „Verbindung zum
  Docker-Proxy abgebrochen.“ + `terminate()`-Versuch.
- `ref()` = `{"kind":"docker","container":…,"exec_id":…,"pgid":…,"user":…}` → `runs.exec_ref`.

### 5.6 `HostSocketExecutor` und Agent-Protokoll (S11)

Verbindung zu `/run/meridian-shell/<profil>.sock` (Profil aus `MERIDIAN_SHELL_HOST_SOCKETS`, Allowlist der Namen).
Rahmen `[typ:1][länge:4 BE][daten]`, Länge ≤ 1 MiB:

| Richtung | Typ | Inhalt |
|---|---|---|
| Worker → Agent | `S` | JSON `{v:1, run_id, interpreter, env:[[n,v]…], nonce, script_bytes}` (einmal, zuerst) |
| Worker → Agent | `I` | Skript-Daten (bis `script_bytes`) |
| Worker → Agent | `T` / `K` | SIGTERM / SIGKILL an die Prozessgruppe |
| Agent → Worker | `P` | JSON `{nonce, pgid}` |
| Agent → Worker | `O` / `E` | stdout / stderr |
| Agent → Worker | `X` | JSON `{exit_code, signal}` (zuletzt) |

Der Agent prüft alles erneut (Interpreter-Enum, E12, Größen), schreibt nur feste Fehlertexte ins Journal, nie Skript
oder Werte. EOF ohne `X` → Gruppe beenden, Ende.

---

## 6. Live-Log (SSE)

### 6.1 `GET /api/runs/{id}/live`

1. Sitzung (`SessionAuth`) → sonst 401 (JSON, kein Strom). Nur Cookie-Sitzung (EventSource kann keine eigenen Header;
   ein Token in der URL ist verboten).
2. **Herkunft:** `Sec-Fetch-Site` vorhanden und nicht `same-origin` → 403. Ein CSRF-Token ist nicht nötig (GET ändert
   keine Daten außer der Belegungszeile); fremde Seiten können den Strom nicht lesen (keine CORS-Header, Cookie
   `SameSite=Strict`).
3. `RunRepository::findVisible(id, scope(jobs.view))` → 404; `require(jobs.view, gespeicherte Kategorie)` → 403.
4. Belegung `live_streams` (global `MERIDIAN_LIVE_STREAMS`, je Benutzer 2) → sonst 429 „Zu viele Live-Verbindungen.
   Die Ansicht aktualisiert sich stattdessen alle 2 Sekunden.“
5. `StreamedResponse` mit `Content-Type: text/event-stream; charset=utf-8`, `Cache-Control: no-store`,
   `X-Accel-Buffering: no`, alle `SecurityHeaders` (CSP unverändert: `connect-src 'self'` deckt EventSource).
6. Fortsetzen ab `Last-Event-ID` bzw. `?after=` (Ganzzahl, sonst 422 vor dem Strom).
7. Schleife alle 500 ms: neue Stücke (`seq > after`, höchstens 64 je Abfrage) →
   `id: <seq>\nevent: chunk\ndata: <json>\n\n` mit `{stream, text}`; Text **erneut** durch einen frischen
   `SecretMasker` (Muster), `JSON_INVALID_UTF8_SUBSTITUTE`; JSON enthält keine rohen Zeilenumbrüche, also keine
   SSE-Einschleusung. Laufstatus → `event: status {status, exit_code}`; alle 15 s `: ping`. Alle 15 s Rechte neu
   (Sitzung gültig, Benutzer aktiv, `findVisible` + `require`) → sonst `event: end {reason: "forbidden"}`.
8. Ende bei: Lauf fertig und alles gesendet (`end finished`), 60 s erreicht (`event: reconnect`),
   `connection_aborted()` nach `flush()`, Lauf gelöscht (`end gone`), Fehler (nur `ErrorLog::unexpected()`, kein Text
   an den Client). Belegungszeile im `finally` löschen; abgelaufene räumt der Planer.
9. **Back-Pressure:** Der Worker wartet nie auf Leser; je Lauf höchstens 1 MiB, je Verbindung höchstens 60 s. Ein
   blockierendes `flush()` endet spätestens mit `max_execution_time` 75 s für diesen Endpunkt.

### 6.2 `GET /api/runs/{id}/log?after=<seq>&limit=1..200`

Schritte 1–3 und 6, Antwort `{"chunks":[{seq, stream, text}], "status": "running", "done": false}`; nach Laufende und
Aufräumen `chunks: []`, `done: true` (Oberfläche lädt dann `GET /api/runs/{id}` mit `output`).

### 6.3 Verbindungsabbruch

Browser schließt → `connection_aborted()` → Schluss, Slot frei. Netz weg ohne FIN → spätestens nach 60 s.
Abgemeldet → beim nächsten 15-s-Check. Job gelöscht → `end gone`.

---

## 7. Infrastruktur

### 7.1 `compose.yaml` (Skizze, `x-common` wie bisher)

```yaml
services:
  web:                         # wie bisher; KEIN Proxy-Volume
    environment: { FRANKENPHP_CONFIG: "num_threads 16", MERIDIAN_LIVE_STREAMS: "4" }
  scheduler:                   # plant nur; braucht weder Schlüssel noch Netz
    command: ["php", "bin/meridian", "scheduler:run", "-v"]
    network_mode: none
    volumes: [meridian-data:/var/lib/meridian]
  worker-http:
    command: ["php", "bin/meridian", "worker:run", "--type=http", "-v"]
    init: true
    stop_grace_period: 40s
    volumes: [meridian-data:/var/lib/meridian, meridian-secrets:/etc/meridian:ro]
  worker-shell:
    command: ["php", "bin/meridian", "worker:run", "--type=shell", "-v"]
    network_mode: none
    init: true
    stop_grace_period: 40s
    environment:
      MERIDIAN_DOCKER_PROXY: unix:///run/meridian-docker/docker.sock
      MERIDIAN_SHELL_CONTAINERS: ${MERIDIAN_SHELL_CONTAINERS:-meridian-sandbox}
    volumes: [meridian-data:/var/lib/meridian, meridian-secrets:/etc/meridian:ro,
              meridian-docker-proxy:/run/meridian-docker]
  docker-proxy:
    image: wollomatic/socket-proxy:1.13.1@sha256:<Digest, in S9 festlegen>
    container_name: meridian-docker-proxy
    restart: unless-stopped
    network_mode: none
    read_only: true
    cap_drop: [ALL]
    security_opt: ["no-new-privileges:true"]
    command:
      - -proxysocketendpoint=/run/proxy/docker.sock
      - -proxysocketendpointfilemode=0666
      - -loglevel=WARN
      - -watchdoginterval=300
      - -stoponwatchdog
      - -allowGET=((/v1\.[0-9]{2})?/_ping|/v1\.[0-9]{2}/(version|containers/(${MERIDIAN_SHELL_CONTAINERS:-meridian-sandbox})/json|exec/[0-9a-f]{64}/json))
      - -allowHEAD=((/v1\.[0-9]{2})?/_ping)
      - -allowPOST=(/v1\.[0-9]{2}/(containers/(${MERIDIAN_SHELL_CONTAINERS:-meridian-sandbox})/exec|exec/[0-9a-f]{64}/start))
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - meridian-docker-proxy:/run/proxy
  sandbox:                     # E2
    build: { … alpine:3.20@sha256:<Digest>, apk add --no-cache bash curl jq ca-certificates tzdata … }
    container_name: meridian-sandbox
    user: "1001:1001"
    command: ["sleep", "2147483647"]
    init: true
    read_only: true
    tmpfs: ["/tmp:size=64m,uid=1001,gid=1001,mode=0700"]
    cap_drop: [ALL]
    security_opt: ["no-new-privileges:true"]
    mem_limit: 512m
    pids_limit: 256
    cpus: 1.0
    networks: [sandbox-net]
networks:
  sandbox-net: {}
volumes:
  meridian-data:
  meridian-secrets:
  meridian-docker-proxy:
```

- `scheduler` verliert den Schlüssel (braucht ihn nicht mehr). `worker-shell` und `docker-proxy` ohne Netz; nur sie
  teilen das Proxy-Volume.
- Ein Image für web/scheduler/worker (Dockerfile und Inline-Block); `posix`-Prüfung im Build ergänzen;
  `docker/sync-compose.py` nutzen.
- In S9 prüfen: Compose ersetzt `${…}` in `command`; die Regex-Gruppen sind korrekt verankert. Test im
  Worker-Shell-Container: `curl --unix-socket … -X POST http://d/v1.41/containers/create` → 403,
  `…/containers/meridian-sandbox/exec` → 201.

### 7.2 systemd

- `meridian-scheduler.service`: ohne `LoadCredential`, `RestrictAddressFamilies=AF_UNIX`, `IPAddressDeny=any`,
  `InaccessiblePaths=/run/meridian-docker`.
- `meridian-worker@.service` (`%i` = `http`/`shell`): `ExecStart=… worker:run --type=%i -v`, `LoadCredential` wie
  bisher, `TimeoutStopSec=40`, `KillMode=mixed`. Drop-ins: `@shell` `RestrictAddressFamilies=AF_UNIX`,
  `IPAddressDeny=any`, `ReadWritePaths=/run/meridian-docker /run/meridian-shell`; `@http`
  `InaccessiblePaths=/run/meridian-docker /run/meridian-shell`.
- S11: `meridian-shell.socket`, `meridian-shell@.service` (E3), Benutzer `meridian-run`
  (`useradd --system --home /var/lib/meridian-run --shell /usr/sbin/nologin meridian-run`), `tmpfiles.d` für
  `/run/meridian-docker`.
- Installer: Scheduler führt nichts mehr aus → `meridian-worker@http` immer, `@shell` nur mit eingerichtetem
  Proxy-/Host-Socket (sonst Hinweis).

---

## 8. Oberfläche

Keine neuen npm-Pakete. Pflicht aus `mer-ui` wie in 0003 §6.

| Scheibe | Inhalt | Daten |
|---|---|---|
| **U1 Job-Editor Shell** | Art „Befehl ausführen“ aktiv nur mit `canEditShell` (uneingeschränkt). Ausführungsort (Auswahl aus `/api/shell/targets?category_id=`, Anzeige `Container nextcloud` / `Host · default`, neu laden bei Kategoriewechsel), Interpreter, Benutzer (erlaubte, Standard markiert; `root` mit roter Warnung), Arbeitsverzeichnis (nur docker), Zeitlimit (Grenze aus `/api/jobs/limits`). Bereich **Skript**: neu = Textfeld (JetBrains Mono, `autocomplete="off"`, `spellcheck=false`) und Zeilen für Umgebungsvariablen (Name Text, Wert `type="password"`); bearbeiten = „Skript: gesetzt · Umgebungsvariablen: N (Namen und Werte verborgen)“ + „Skript ersetzen“ (leere Felder, Hinweis „Skript und Umgebungsvariablen werden zusammen ersetzt“, „Abbrechen“ behält das alte). Hinweise: „Das Skript ist nach dem Speichern nicht mehr lesbar. Bewahre es zusätzlich in deiner Versionsverwaltung auf.“, „Geheimnisse gehören in Umgebungsvariablen (mindestens 4 Zeichen, sonst kann Meridian sie in der Ausgabe nicht verbergen).“ Ohne Ausführungsorte: „Noch kein Ausführungsort freigegeben (Einstellungen → Ausführungsorte).“ Geheimfelder nach dem Speichern aus dem Zustand löschen. Jobliste: ZIEL = Container/Host. | `POST/PUT /api/jobs`, `/api/shell/targets`, `/api/jobs/limits` |
| **U2 Live-Log und Abbrechen** | Job-Detail: laufender Lauf öffnet Live-Ansicht `<pre>` (Text, nie HTML), stderr-Zeilen mit Farbe **und** Präfix, „Automatisch mitlaufen“ (aus bei manuellem Scrollen), Status, Dauer. `EventSource('/api/runs/{id}/live')`; bei Fehler/429 Rückfall auf `/log?after=` alle 2 s; Schließen beim Verlassen (Cleanup). Nach Ende gespeicherte Ausgabe aus `GET /api/runs/{id}`, Hinweis bei `[… ausgelassen …]`. „Lauf abbrechen“ (nur `canRun`, `Confirm` „Lauf wirklich abbrechen? Der Prozess erhält SIGTERM, nach 10 s SIGKILL.“, Fokus auf dem sicheren Knopf), danach „Abbruch angefordert …“; 409 als Text. Auch für HTTP-Läufe. `prefers-reduced-motion`: kein animiertes Mitscrollen. | `/api/runs/{id}/live`, `/log`, `/cancel` |
| **U3 Einstellungen Shell** | Karte „Shell-Jobs“ (`settings.manage`): Maximum Zeitlimit (1–86400). Karte „Ausführungsorte“ (`shell.targets`): Tabelle Art · Name · Gilt für · Benutzer (Standard) · Notiz · angelegt; Formular, Entfernen mit `Confirm`; Warnung „Eine Freigabe erlaubt Admins mit dem Recht ‚Shell-Jobs bearbeiten‘ beliebige Befehle in diesem Container. Der Container muss zusätzlich in MERIDIAN_SHELL_CONTAINERS stehen.“ | `/api/settings…`, `/api/settings/shell-targets…` |

`lib/permissions.ts`: `canEditShell(profile)` (nur uneingeschränkt), `canManageShellTargets`. Die Rechte-Matrix
(Phase 4a/5) kennzeichnet `jobs.edit_shell` und `shell.targets` als gefährlich.

---

## 9. Umsetzungsreihenfolge

Jeder Schritt endet mit grünem `composer check` (bzw. `npm run build` + Typprüfung) und eigenem Commit. Ein Agent je
Schritt. Die Tabelle steht gekürzt in `TODO.md`.

| # | Agent | Inhalt | Abnahme / Tests | Skill |
|---|---|---|---|---|
| S1 | sicherheit | Beide Migrationen (§3), `Permission::ManageShellTargets` (`shell.targets`, in `isDangerous()`), `SettingKey::ShellMaxTimeout` + `Settings::shellMaxTimeoutSeconds()` (1–86400, Standard 3600) | zweimal einspielen ändert nichts; Admin hat `shell.targets`, Operator/Beobachter nicht, beschränkte Admin-Rolle nie; `settings`-Zeilen überleben den Neuaufbau (Roundtrip über neue Verbindung); `SettingKey`-Liste ≡ CHECK-Liste (Test liest `sqlite_master`); `shell_targets`: Kategorie gelöscht → Zeile weg, doppelte globale Freigabe scheitert, docker ohne `default_user` scheitert; `run_log_chunks` > 16 KiB und ungültiges `exec_ref` scheitern | mer-storage §2/§3, mer-security §4 |
| S2 | scheduler | Worker-Trennung (E5, §5.1/§5.2): `WorkerIdentity`, `RunHeartbeat` mit Worker-ID, `StopReason`, `claim()` ohne Sperre mit Typfilter, `workers`, `WorkerSupervisor`, `StaleRuns` im Planer, `scheduler:run` plant nur (`--inline-worker`), `worker:run`, Verdrahtung `bin/meridian` | bestehende Tests grün (angepasst); zwei Worker parallel: jeder Lauf genau einmal; `queue`/`skip` mit zwei Workern nie doppelt; Planer-Takt läuft während eines 30-s-Laufs weiter; Absturz → `aborted` nach 60 s; kein Shell-Worker → `aborted` nach 10 min; SIGTERM an Aufseher → Kinder beenden, Lauf `aborted` `NOTE_WORKER_STOPPED`, Exit 0; Kind-Umgebung ohne `MERIDIAN_ADMIN_PASSWORD`; Worker schreibt nie `scheduler_lease` | mer-scheduler §2/§3/§5 |
| S3 | sicherheit | `StreamMasker`, `OutputCollector`, `LiveLog`/`DbLiveLog`, neue `Runner`-Signatur, `RunResult::aborted()`/`outputBytes`, `HttpRunner` schreibt Status-Zeilen live | Geheimnis über jede Blockgrenze (alle Versätze) nie sichtbar; Muster über Blockgrenze; 100-KiB-Zeile ohne Umbruch mit Geheimnis an der Kante; Nicht-UTF-8; 1-MiB-Grenze; Kopf/Ende mit Zähler; Eigenschaftstest zufällige Blockgrößen × Positionen | mer-runner §4/§5 |
| S4 | backend | `POST /api/runs/{id}/cancel` (E7), `GET /api/runs/{id}/log`, neue Lauf-Felder | Rollen-Matrix §4.1; IDOR → 404; CSRF → 403 ohne Änderung; wartend → `aborted`, laufend → 202, fertig → 409, idempotent; Audit; H2 um `exec_ref`; `limit` 0/201 → 422 | mer-security §1/§5/§14 |
| S5 | backend | `GET /api/runs/{id}/live` (§6.1) mit `StreamedResponse`, Belegung, Neuprüfung | SSE-Tests §10.6 | mer-security §5/§9/§10, mer-runner §5 |
| S6 | sicherheit | `ShellPayload`, `ShellJobConfig`, `ShellTargetPolicy`, `ShellTargetStore` + API/CLI, `GET /api/shell/targets`, `JobValidator`/`JobService`/`JobDraft`/`JobRepository`/`JobPresenter` für `type = shell` (Recht nach Typ) | Rollen-Matrix (Shell-Zeilen); Validierung §4.3; Leak: Test-Geheimnis in Skript und Umgebung in keiner Antwort, keinem Audit, nicht in `config_json`; Roundtrip `payload_enc`; Freigabe für B nicht wählbar für Job in A; „Skript ersetzen“ ersetzt Umgebung mit; Typwechsel → 422; Darstellungs-Test | mer-security §4/§11, mer-storage §8 |
| S7 | sicherheit | `Executor`/`Execution`, `LocalProcessExecutor` (§5.4), `ShellRunner` (§5.3), `ShellJobSource` | Prozess-Tests §10.4; Herzschlag ≥ alle 20 s bei stiller Ausgabe; `beat() → false` beendet die Gruppe in ≤ 12 s | mer-runner §1/§6 |
| S8 | backend | `DockerProxyClient`, `DockerExecExecutor` (§5.5) mit Wrapper, Kill-Exec, `exec_ref`-Aufräumen, Start-Prüfungen | Fake-Proxy-Tests §10.5; `@group docker` gegen echten wollomatic-Proxy mit `alpine` und `debian:stable-slim`: Ausgabe, Exit-Code, Zeitlimit tötet auch `sleep 600 &`, Container ohne `setsid` → Notiz | mer-runner §2 |
| S9 | infra | Compose §7.1, Dockerfile (`posix`), `sync-compose.py`, `FRANKENPHP_CONFIG`, systemd §7.2 (ohne Host-Agent), Installer, `.env.example`, Deploy-Hinweise in `WEITERMACHEN.md` | `docker compose config` gültig; im `worker-shell`: `containers/create` → 403, Exec in Sandbox → 201; `worker-http` ohne Socket; `scheduler` ohne `/etc/meridian`; Sandbox `id` = 1001, Schreiben außer `/tmp` scheitert | mer-runner §2 |
| S10 | sicherheit | Abgleich mit dem schon begonnenen `InfrastructureTargets` (Ports 2375/2376, Proxy-Hostnamen als `Infrastructure`, E11); Freigaben mit diesen Ports → 422; `MERIDIAN_DOCKER_PROXY` nur `unix://` | `10.0.0.5:2375` trotz Freigabe `10.0.0.0/8` gesperrt; `127.0.0.1:2376` trotz `/32`-Freigabe gesperrt; `tcp://…` → Worker-Start abgelehnt | mer-security §7, mer-runner §3 |
| S11 | sicherheit | Host-Ausführung (E3, §5.6): Agent, `HostSocketExecutor`, Units, `meridian-run` — darf nach Phase 9 rutschen (O3) | Agent ohne Zugriff auf Daten/Schlüssel; Protokoll-Fuzz → Ende ohne Ausführung; Verbindungsabbruch → Gruppe beendet; Umgebung exakt E12 | mer-runner §1, mer-security §8 |
| S12 | tester | Gesamt-Leak- und Prozess-Suite §10 (`tests/Integration/Phase4`) | alle Fälle §10; Geheimnis an keiner Ausgabestelle aus §12 | alle |
| U1 | frontend | Job-Editor Shell (§8) | Geheimfelder nie vorbefüllt, nach Speichern geleert; „Befehl ausführen“ nur mit `canEditShell`; Build ohne CSP-Fehler | mer-ui §3 |
| U2 | frontend | Live-Log, Rückfall, Abbrechen (§8) | Ausgabe als Text; Rückfall bei 429; EventSource beim Verlassen geschlossen; `Confirm` | mer-ui §4 |
| U3 | frontend | Einstellungen Shell (§8) | Karten nur mit Recht; 422 am Feld; Warntext | mer-ui §5 |
| R | sicherheit | Review Phase 4 nach `mer-security` §15 (Proxy-Allowlist, Kind-Umgebungen, Strom-Maskierung, SSE-Autorisierung) | alle Befunde behoben oder von Alex akzeptiert | – |

Abhängigkeiten: S1 → S2 → S3 → S7 → S8; S1 → S6; S3 → S4 → S5; S6 + S7 → S8; S2 → S9; S8 + S9 → S12;
S10 nach S1; S11 nach S7. U1 nach S6, U2 nach S4/S5, U3 nach S6. Parallel: S6 ‖ S2/S3, S10 ‖ alles, U1 ‖ S7/S8.

---

## 10. Testplan

Test-Geheimnisse offensichtlich unecht (`TEST-SECRET-do-not-use-123`, `TEST-ENV-VALUE-xyz-789`); Slack-/GitHub-Formate
zur Laufzeit aus Teilen zusammensetzen (gitleaks, Push-Protection).

### 10.1 Rollen-Matrix und IDOR
- Jede Zeile in §4.1 für Admin, Admin/A, Op, Op/A (auf A, B, ohne Kategorie), Beobachter, anonym; Kategorien A, B und
  ein Job ohne Kategorie. Mutation: `require()` in Cancel/Live/Log entfernen → Test fällt.
- IDOR: Op/A liest `/log`, `/live` oder bricht ab für Lauf in B → 404; Beobachter bricht ab → 403.
- Ausführungsort nur für A: Job in A ok; nach B verschoben → nächster Lauf `failed` (nicht wiederholbar); Kategorie A
  gelöscht → Freigabe weg, Job `failed`.
- H1 (Rechte zwischen Einreihen und Start entzogen) auch für Shell-Läufe.

### 10.2 Leak-Tests (je Ausgabestelle)
Geheimnis in **Skript** (Literal), **Umgebungswert** und **Ausgabe** (`echo "$NC_TOKEN"`,
`printf %s "$NC_TOKEN" | base64`, `env`, `set -x`, über Blockgrenzen, auf stderr): `runs.output`, `runs.note`,
`run_log_chunks.data`, `/log`, `/live`, Lauf-Detail, Job-Liste/-Detail, 422-Meldungen, Audit, `config_json`,
`workers.caps_json`, `exec_ref`, Prozessausgabe von `scheduler:run` und `worker:run`, `error_log`, Docker-`Cmd` im
Fake-Proxy-Protokoll (Skript nie im `Cmd`), Agent-Journal. Erwartung: weder roh noch URL-kodiert noch base64.
Kind-Umgebung: Test setzt `MERIDIAN_ADMIN_PASSWORD`, das Worker-Kind sieht es nicht; `LocalProcessExecutor`-Kind sieht
nur die explizite Umgebung (kein `MERIDIAN_KEY_FILE`).

### 10.3 Datenhaltung
Roundtrip Shell-Job über neue Verbindung; `payload_enc` ohne Klartext; `ShellJobConfig` ohne `v` → ungültig;
`ShellPayload` mit `v: 2` → feste Meldung.

### 10.4 Prozess-Tests (echte Prozesse)
- Exit 0/1/127, stdout/stderr, Skript 256 KiB über stdin ohne Blockieren.
- **Zeitlimit:** `sleep 600` mit 2 s → `timeout` in ≤ 12 s. **Gruppenkill:** `sleep 600 &` und `sh -c 'sleep 600' &`
  → danach keine Prozesse der Gruppe (`posix_kill(-pgid, 0)` scheitert).
- **SIGTERM ignoriert:** `trap '' TERM; sleep 600` → SIGKILL nach 10 s.
- **Zombie:** nach `close()` kein `<defunct>`-Kind.
- **Ausgabeflut:** `yes` → Abbruch bei 64 MiB, Speicherzuwachs des Workers < 64 MiB, Live-Log 1 MiB mit Abschluss,
  Ausgabe = Kopf + Ende.
- **Stille:** `sleep 45` → `beat()` ≥ 3× (CountingHeartbeat).
- **Abbruch:** `beat()` ab Sekunde 3 `false` (Cancelled) → `aborted`, Gruppe weg in ≤ 12 s.
- **Umgebung:** `env` liefert genau die erwartete Liste; verbotene Namen → 422.
- **Nicht-UTF-8/NUL/`\r`** → gültiges UTF-8 gespeichert.

### 10.5 Docker-Proxy-Tests mit Fake-Proxy
`tests/Support/FakeDockerProxy.php`: Unix-Socket-Server in eigenem Prozess, spielt Docker-Antworten ab, protokolliert
Anfragen.
- Exec-Anlage: Körper exakt (`Privileged:false`, `Tty:false`, Benutzer aus Freigabe, `Cmd` konstant + validiert, kein
  Skript); Skript kommt mit exakt `LEN` Bytes; Schreibseite nicht halb geschlossen.
- Rahmen: stdout/stderr gemischt, über mehrere `read()` verteilt, > 1 MiB → Protokollfehler; falsche/fehlende
  `MERIDIAN-PGID`-Zeile → Abbruch.
- Fehler: Socket fehlt, Verbindung abgelehnt, 403, 404, 409, 500, Zeitlimit, EOF bei `Running: true` — je feste Notiz
  und richtiges `retryable`.
- Abbruch: Kill-Exec mit validierter PGID nach Exec-Inspect; `Running:false` → kein Kill.
- `exec_ref`-Aufräumen nach simuliertem Absturz: Kill nur bei `Running: true`.
- Konfiguration: `tcp://…` abgelehnt; Name mit Punkt → 422; Freigabe nicht in `MERIDIAN_SHELL_CONTAINERS` →
  Startwarnung (nur Name).

### 10.6 SSE-Tests
- Ohne Login → 401; fremde Kategorie → 404; ohne `jobs.view` → 403; `Sec-Fetch-Site: cross-site` → 403.
- Header `text/event-stream`, `no-store`, CSP vorhanden.
- Reihenfolge; `Last-Event-ID` setzt ohne Lücke/Dopplung fort; `after=abc` → 422.
- Lauftext mit `\n\ndata: fake` erzeugt kein zweites Ereignis.
- Rechte während des Stroms entzogen / Sitzung gelöscht → `end forbidden` beim nächsten Check (Uhr injiziert).
- Grenzen: 5. Verbindung global bzw. 3. je Benutzer → 429; Slot nach Ende frei; abgelaufene Zeile räumt der Planer.
- Ende: Lauf fertig → `end finished`; 60 s → `reconnect`; Client trennt → Slot frei.
- Muster-Maskierung (`token=…`) greift auch beim Ausliefern eines Stücks.

---

## 11. Offene Entscheidungen für Alex (mit Empfehlung)

| # | Frage | Empfehlung |
|---|---|---|
| O1 | Proxy-Image `wollomatic/socket-proxy` (Pfad-Allowlist, Unix-Socket) statt `tecnativa`? Neue Image-Abhängigkeit. | **Ja, 1.13.1 mit Digest.** tecnativa erlaubt mit den nötigen Schaltern auch Container-Anlage (= Root). |
| O2 | Darf, wer `jobs.edit_shell` hat, das Skript (ohne Umgebung) wieder lesen? Zweite Ausnahme von „keine Geheimnisse in Antworten“, Web-API müsste entschlüsseln. | **Nein** (nur schreibend, Hinweis auf Versionsverwaltung). Falls doch: eigener Endpunkt, Audit `job.script_viewed`, Umgebung nie. |
| O3 | Host-Ausführung (systemd, `meridian-shell@`) in Phase 4 oder mit Phase 9? | **Phase 9**; Phase 4 liefert Docker + Sandbox (NAS-Betrieb), Schnittstelle steht. |
| O4 | Sandbox-Container standardmäßig in Compose, Werkzeuge busybox, bash, curl, jq, ca-certificates, tzdata? | **Ja**; mehr per eigenem Image. |
| O5 | Dürfen kategoriebeschränkte Operatoren Shell-Jobs ihrer Kategorie sehen, starten und abbrechen? | **Ja** (nur vom Admin geschriebene Skripte; Bearbeiten bleibt gefährlich). Strenger: eigenes `jobs.run_shell`. |
| O6 | Live-Log per SSE, global 4 Verbindungen, Rückfall auf Abfragen? | **Ja.** |
| O7 | Ausgabe Kopf 16 KiB + Ende 48 KiB; Live-Log 1 MiB; Live-Stücke 15 min nach Ende löschen? | **Ja.** |
| O8 | `shell.max_timeout_seconds` Standard 3600, höchstens 86400; Job-Standard 300 s? | **Ja.** |
| O9 | Namen der Umgebungsvariablen in der Bearbeiten-Ansicht zeigen? | **Nein** (wie Header-Namen). |
| O10 | `root` als Exec-Benutzer, wenn der Admin ihn ausdrücklich freigibt? | **Ja, mit Warnung**; nie als Standard. |
| O11 | Compose ändert sich deutlich (Scheduler ohne Schlüssel und Netz, zwei Worker, Proxy, Sandbox); NAS-Compose neu einfügen. | **Ja**, Hinweis in `WEITERMACHEN.md`. |
| O12 | Befehl als Argument-Liste ohne Shell (`mode: argv`)? | **Später**; Format vorbereitet. |

---

## 12. Sicherheitsprüfung des Entwurfs

| Frage | Antwort |
|---|---|
| Wo entstehen Geheimnisse? | Im Editor (Skript, Umgebungswerte) → `POST/PUT /api/jobs` → validiert, sofort mit `SecretBox` verschlüsselt, nur `payload_enc`. Der Webprozess entschlüsselt nie (O2). Nicht geheim, aber nie in Antworten: `exec_ref`. |
| Wo werden sie entschlüsselt? | Nur im Shell-Worker-Kind, unmittelbar vor dem Lauf, sofort im Masker des Laufs registriert. Weitergabe: Docker-Exec-Körper (`Env`) und hochgestufter Strom (Skript) über den Unix-Socket an den Proxy; Host: Rahmen über den Unix-Socket an den Agenten. Nie als Kommandozeilen-Argument. |
| Wo verlassen Daten das System? | (1) An den Zielcontainer bzw. `meridian-run` — gewollt, nur an freigegebene Orte (zwei Schichten: `shell_targets` + Proxy-Regex), nie `Privileged`. (2) API: Feld-Allowlist, `has_*`/Anzahl, maskiert. (3) Verlauf/Live-Log: Strom-Maskierung → kürzen → speichern; beim Ausliefern erneut maskiert. (4) SSE: nur nach Sitzung + Scope + `require`, Neuprüfung alle 15 s, JSON-kodiert. (5) Audit: Feldnamen, „Skript ersetzt“, Freigaben. (6) Prozessausgaben: IDs, Status, feste Texte. (7) Docker-Daemon-Log sieht `Env` nur im Debug-Modus des Daemons (Restrisiko); Proxy-Log nur Pfade. |
| Wo wird geprüft, wer was darf? | Endpunkte: Sitzung → CSRF/Herkunft → `findVisible` → `require` mit gespeicherter Kategorie. Bearbeiten nur `jobs.edit_shell`, Ausführungsorte nur `shell.targets` (beide gefährlich). Übernahme: H1. Jeder Lauf: `ShellTargetPolicy` mit Kategorie aus der DB. Infrastruktur: Proxy-Pfad-Allowlist, kein Netz für Shell-Worker/Proxy/Planer, Proxy-Socket nur im Shell-Worker. |
| Kindprozesse erben keine Geheimnisse? | Worker-Kinder: Umgebungs-Allowlist (E5). Lokaler/Host-Prozess: exakte Umgebung (E12). Docker-Exec: läuft im Zielcontainer, erbt nichts von Meridian. |
| Restrisiken | Kompromittierter Shell-Worker kann in freigegebenen Containern beliebig (auch privilegiert) ausführen — er hat aber ohnehin Schlüssel und Datenbank. Container ohne `setsid`: Abbruch trifft nur die PID. Lange Zeilen ohne Umbruch: Teile verdeckt. Umgebungswerte < 4 Zeichen nicht maskierbar. Exec-Grenzen = Containergrenzen. |
| Neue Ausgabestellen und Leak-Tests | Job-Liste/-Detail (Shell), 422-Meldungen, `/api/shell/targets`, `/api/settings/shell-targets`, Audit (`shell.target_*`, `run.cancel_requested`), Lauf-Detail (neue Felder), `/log`, `/live`, `run_log_chunks`, `workers`, Ausgaben `worker:run`, CLI `shell:targets`, Agent-Journal — S3–S8, S11, S12. |

---

## 13. Regeländerungen für `CLAUDE.md` und Skills

Vom Koordinator einzutragen, spätestens mit dem Schritt, der die Regel zuerst umsetzt („Skill mitpflegen“).

**`CLAUDE.md`, Sicherheitsnetz (neue Zeilen):**
1. „Shell-Jobs laufen nie im Meridian-Container und nie als Meridian-Benutzer: Docker-Exec in freigegebene Container
   über den Socket-Proxy (nur Unix-Socket, Pfad-Allowlist) oder Host-Agent als eigener Benutzer.“ (`mer-runner`,
   `mer-security`)
2. „Skript und Umgebungsvariablen eines Shell-Jobs sind Geheimnisse: nur `has_*`/Anzahl in Antworten, keine maskierte
   Anzeige, nur als Ganzes ersetzen.“ (`mer-security`)
3. „Planer und Worker sind getrennte Prozesse; Worker berühren die Scheduler-Sperre nie.“ (`mer-scheduler`)
4. Routing: `mer-runner` um „Worker-Prozesse, Docker-Exec, Live-Log-Tabelle“, `mer-scheduler` um „Planer/Worker-
   Trennung, Abbruch“ ergänzen.

**`mer-security`:** §4 Recht `shell.targets` (gefährlich), Shell-Rechte (E4, O5); §5 Live-Log und Abbrechen über
`findVisible` + `require`; §10 SSE-Grenzen; §11 Skript/Umgebung nur schreibend, Ausnahme `display_url` gilt nicht für
Skripte; §14 Audit `run.cancel_requested`, `shell.target_added/_removed`; Verboten: Skript als Argument oder im
Docker-`Cmd`, Proxy per TCP, Container ohne Freigabe, Exec mit `Privileged: true`.

**`mer-runner`:** §1 `setsid` + Prozessgruppe, PGID-Prüfung, reapen, `ext-posix`; §2 durch E10 ersetzen (wollomatic,
Unix-Socket, Pfad-Regex, zwei Schichten, `head -c`-Wrapper, kein halbes Schließen, Kill-Exec, `exec_ref`); §5
`StreamMasker`, Kopf/Ende, Live-Log-Grenzen; §6 `StopReason`, Abbruch durch Benutzer; Fehler-Notizen §5.3.

**`mer-scheduler`:** §2 Sperre nur für den Planer; §3 Übernahme durch Worker ohne Sperre, mit Typfilter;
`RunHeartbeat` mit Worker-ID; verwaiste Läufe/kein Worker → `aborted`; §5 Prozessmodell E5, SIGTERM bricht laufende
Läufe ab, `--inline-worker` nur dev; Hinweis „lange Läufe halten den Takt auf“ entfernen.

**`mer-storage`:** §2 Beispiel `settings`-Neuaufbau; §4 wer `workers`, `run_log_chunks`, `live_streams`,
`runs.exec_ref`, `runs.cancel_requested_*` schreibt; §7 Aufbewahrung `run_log_chunks` 15 min.

**`mer-ui`:** §3 Skript/Umgebung nur schreibend („Skript ersetzen“); §4 Live-Log als Text, EventSource schließen,
Rückfall auf Abfragen; §5 „Befehl ausführen“ nur mit `jobs.edit_shell`, Warnung bei `root`.

**Hook (`rule-router.js`):** `Privileged.{0,5}true` und `tcp://` bei `MERIDIAN_DOCKER_PROXY` als Verstoß;
`proc_open(` ohne `setsid` in `src/Runner/Shell` als Hinweis.

## Nachtrag N1 (2026-10-10): Gespeichertes Skript im Editor anzeigen (`jobs.reveal_for_edit`)

Wie ADR 0003 N1 (Entscheidung Alex, Standard aus): Bei `jobs.reveal_for_edit = on` liefert
`GET /api/jobs/{id}/source` für Shell-Jobs `{job_id, type: "shell", script, env: [{name, value}]}` unmaskiert, nur mit
`jobs.edit_shell` (gefährlich, also nur uneingeschränkt) für die gespeicherte Kategorie; Beobachter, Operatoren und
eingeschränkte Admins nie. Damit entschlüsselt der Web-Prozess `payload_enc` von Shell-Jobs an genau dieser Stelle
(`JobSourceReader`); E6 („nur der Shell-Worker liest es“) gilt sonst weiter. Der Web-Dienst mountet
`meridian-secrets` (compose.yaml, `&common`). Skript und Umgebung bleiben „nur als Ganzes ersetzen“ (PUT).
