# Meridian

Selbst gehosteter Job-Scheduler für HTTP- und Shell-Jobs, mit Benutzern, Rollen und Rechten pro Kategorie.

> Stand: Phase 1 (Fundament) in Arbeit. Noch nicht für den Betrieb geeignet.

Plan und Aufgaben: [`docs/PLAN.md`](docs/PLAN.md), [`TODO.md`](TODO.md).

## Betrieb mit Docker

Es genügt die Datei `compose.yaml`, vorbereitet werden muss nichts. Als Projekt in Docker bereitstellen
(UGOS-Docker-App) oder auf dem Server:

```bash
docker compose up -d --build
```

Das Image wird dabei aus GitHub gebaut. Beim ersten Start legt der Container selbst an: den Hauptschlüssel
(Volume `meridian-secrets`, wird nie überschrieben), die Datenbank (Volume `meridian-data`) und den ersten Admin.
Ohne Angabe heißt er `admin` und bekommt ein zufälliges Passwort, das einmalig im Log steht
(`docker compose logs web`). Eigene Zugangsdaten über `MERIDIAN_ADMIN_USER` und `MERIDIAN_ADMIN_PASSWORD`
(mind. 8 Zeichen, oder `MERIDIAN_ADMIN_PASSWORD_FILE`), siehe `.env.example`. Erreichbar unter
`http://<host>:8090` (Port über `MERIDIAN_PORT`).

Aktualisieren auf die neueste Version:

```bash
docker compose build --pull --no-cache && docker compose up -d
```

Benutzer verwalten, Passwort zurücksetzen, Sperren aufheben: siehe [Befehle](#befehle).
`MERIDIAN_ADMIN_USER` und `MERIDIAN_ADMIN_PASSWORD` gelten nur beim allerersten Start, solange es noch keinen Benutzer gibt.

Das Volume `meridian-secrets` sicher aufbewahren (Sicherung mitnehmen): ohne den Schlüssel sind gespeicherte
Geheimnisse verloren.

### Shell-Jobs im Docker-Betrieb

Shell-Jobs laufen nie im Meridian-Container, sondern per `docker exec` in einem **freigegebenen Container**.
Die `compose.yaml` bringt dafür mit:

- `worker-http` und `worker-shell`: getrennte Worker (`worker:run --type=http|shell`); der `scheduler` plant nur
  (kein Hauptschlüssel, kein Netz). `worker-shell` hat kein Netz und erreicht nur den Docker-Proxy.
- `docker-proxy` (`wollomatic/socket-proxy`): der **einzige** Dienst mit `/var/run/docker.sock` (nur lesend eingebunden).
  Er lässt nur `_ping`, `version`, Container-Info der erlaubten Namen und Exec (anlegen, starten, abfragen) durch;
  `containers/create`, `start`, `rm` u. a. gibt es nicht (403). Er hat kein Netz und keine IP-Adresse, sondern einen
  Unix-Socket im Volume `meridian-docker-proxy`, das nur er und `worker-shell` einbinden.
- `sandbox` (`meridian-sandbox`): Standard-Ausführungsort mit busybox, bash, curl, jq, ca-certificates, tzdata,
  Benutzer 1001, ohne Volumes, schreibgeschützt (nur `/tmp`), 512 MiB, 256 Prozesse. Er wird **nicht automatisch
  freigegeben**: in Meridian unter Einstellungen -> Ausführungsorte `docker meridian-sandbox` mit Benutzer `1001`
  eintragen. Mehr Werkzeuge: eigenes Image und eigener Dienst (`docker/Dockerfile.sandbox` als Vorlage).

Weitere Container freigeben: Name in `MERIDIAN_SHELL_CONTAINERS` eintragen (`.env`, getrennt durch `|`, nur
`A-Z a-z 0-9 _ -`, z. B. `meridian-sandbox|nextcloud`), `docker compose up -d`, und den Container zusätzlich in Meridian
als Ausführungsort freigeben. Beides muss stimmen (zwei Schichten).

Das Proxy-Image mit Digest festnageln (nie `:latest`): `MERIDIAN_PROXY_IMAGE=wollomatic/socket-proxy:1.13.1@sha256:<Digest>`
in der `.env`. Den Digest vorher selbst prüfen und eintragen, z. B. mit `docker buildx imagetools inspect
wollomatic/socket-proxy:1.13.1`. Hat `/var/run/docker.sock` eine andere Gruppe als root, `MERIDIAN_DOCKER_GID` setzen.

**Sicherheitshinweis:** Ein Zugang zur Docker-API ist faktisch Root auf dem Host. Der Proxy begrenzt ihn auf Exec in den
eingetragenen Containern, kann aber den Inhalt einer Anfrage nicht prüfen. Nur Container freigeben, in denen Admins mit
dem Recht „Shell-Jobs bearbeiten“ beliebige Befehle ausführen dürfen. Beim Update von einer älteren Version die
`compose.yaml` komplett neu einfügen; die Volumes `meridian-data` und `meridian-secrets` bleiben bestehen.

## Befehle

Meridian bringt Befehle für die Verwaltung mit. Sie laufen im Container bzw. auf dem Server, nicht im Browser.

**Aufruf im Docker-Betrieb** (Containername `meridian-web`; `-it` ist bei Befehlen nötig, die ein Passwort abfragen):

```bash
docker exec -it meridian-web php bin/meridian <befehl> [argumente]
# oder im Ordner der compose.yaml:
docker compose exec web php bin/meridian <befehl> [argumente]
```

**Aufruf bei der Linux-Installation (systemd):**

```bash
sudo -u meridian env MERIDIAN_DATA_DIR=/var/lib/meridian php /opt/meridian/bin/meridian <befehl> [argumente]
```

| Befehl | Wofür |
|---|---|
| `list` | Zeigt alle Befehle. `<befehl> --help` erklärt Argumente und Optionen. |
| `user:create <name> [--name="Anzeigename"] [--role=Admin\|Operator\|Beobachter]` | Legt einen Benutzer an. Das Passwort (mind. 8 Zeichen) wird verdeckt abgefragt. Standardrolle ist `Beobachter`. |
| `user:password <name>` | Setzt das Passwort neu, z. B. wenn es vergessen wurde. Das Passwort gilt als eigenes (ein Pflichtwechsel bzw. Ablauf eines Einmalpassworts entfällt). Beendet alle Sitzungen des Benutzers und hebt eine Sperre auf. Eine eingerichtete 2FA bleibt bestehen. Gelöschte Benutzer werden abgelehnt. |
| `category:create <name>` | Legt eine Kategorie für Jobs an (1–64 Zeichen: Buchstaben, Ziffern, Leerzeichen, `_` `.` `-`; beginnt mit Buchstabe oder Ziffer). Doppelte Namen (auch in anderer Groß-/Kleinschreibung) werden abgelehnt. Wer auf Kategorien beschränkt ist, sieht die neue Kategorie nicht, bis sie ihm zugewiesen wird. |
| `category:rename <name> <neuer-name>` | Benennt eine Kategorie um (gefunden über den Namen, Groß-/Kleinschreibung zählt nicht; der neue Name folgt denselben Regeln wie bei `category:create`). Jobs, Zuweisungen und Freigaben bleiben. |
| `category:delete <name> [--yes]` | Löscht eine Kategorie, aber nur wenn kein Job (auch kein deaktivierter) darin liegt. Zeigt vorher die Folgen: Zuweisungen verlieren die Kategorie (war sie die einzige, gewähren sie nichts mehr, nie „alle Kategorien“), Freigaben interner Ziele dieser Kategorie werden gelöscht. Ohne `--yes` wird nachgefragt. |
| `auth:unlock <benutzername-oder-ip>` | Hebt die Sperre nach zu vielen Fehlversuchen auf, auch wenn sich niemand mehr anmelden kann. |
| `admin:bootstrap` | Legt den ersten Admin aus `MERIDIAN_ADMIN_USER` / `MERIDIAN_ADMIN_PASSWORD` an. Nur wenn es noch keinen Benutzer gibt; der Docker-Start ruft das selbst auf. |
| `migrate` | Spielt ausstehende Datenbank-Änderungen ein. Docker-Start und Installer machen das selbst. |
| `key:generate <pfad>` | Erzeugt den Hauptschlüssel als Datei (Rechte 0600). Überschreibt nie eine vorhandene Datei. Docker-Start und Installer machen das selbst. |
| `scheduler:run [--once] [-v]` | Startet den Scheduler als Dauerprozess (Takt alle 5 Sekunden). Läuft als eigener Container (`meridian-scheduler`) bzw. systemd-Dienst. Es plant immer nur ein Scheduler: ein zweiter Prozess wartet, bis die Sperre in der Datenbank frei wird (spätestens 60 Sekunden nach dem Ende des ersten). `SIGTERM` beendet ihn sauber. `--once` macht genau einen Takt. |

Beispiele:

```bash
# Passwort von "admin" neu setzen (Docker)
docker exec -it meridian-web php bin/meridian user:password admin

# Weiteren Benutzer anlegen
docker exec -it meridian-web php bin/meridian user:create jana --name "Jana" --role Operator

# Kategorie für Jobs anlegen
docker exec -it meridian-web php bin/meridian category:create "Deuba24"

# Gesperrten Benutzer oder gesperrte IP freigeben
docker exec -it meridian-web php bin/meridian auth:unlock jana
docker exec -it meridian-web php bin/meridian auth:unlock 192.168.0.25
```

Passwörter werden nie als Argument übergeben (sie würden in der Shell-History und der Prozessliste landen),
sondern immer verdeckt abgefragt. Jeder dieser Befehle, der Benutzer oder Sperren ändert, schreibt einen Eintrag ins Audit-Log.

## Anmeldung (API)

| Endpunkt | Zweck |
|---|---|
| `POST /api/auth/login` | `{"username": "...", "password": "..."}`, setzt das Sitzungs-Cookie, liefert Benutzer, Rollen und CSRF-Token |
| `GET /api/auth/me` | Benutzer, Rollen mit Rechten und CSRF-Token der laufenden Sitzung |
| `POST /api/auth/logout` | beendet die Sitzung (Header `X-CSRF-Token` nötig) |
| `POST /api/auth/2fa/setup` | startet die Einrichtung der Zwei-Faktor-Anmeldung, liefert das Secret und die `otpauth://`-Adresse (einmalig) |
| `POST /api/auth/2fa/enable` | `{"password": "...", "code": "123456"}` bestätigt die Einrichtung und liefert 8 Wiederherstellungscodes (einmalig) |
| `POST /api/auth/2fa/disable` | `{"password": "...", "code": "..."}` schaltet 2FA ab |
| `GET /api/audit` | Audit-Log (neueste zuerst), nur mit Recht `users.manage`. Parameter: `limit` (1 bis 200), `before_id`, `action` |
| `POST /api/users/unlock` | `{"username": "..."}` oder `{"ip": "..."}` hebt eine Sperre auf (`users.manage`, CSRF nötig) |

Ist 2FA aktiv, antwortet die Anmeldung nach richtigem Passwort mit `401` und `"totp_required": true`; dann
`totp_code` (App-Code oder Wiederherstellungscode) mitschicken.

Die Sitzung liegt in einem `HttpOnly`-, `SameSite=Strict`-Cookie. Über HTTPS zusätzlich `Secure` und mit dem Namen
`__Host-meridian_session`. Die Anmeldung funktioniert auch über reines HTTP (z. B. `http://nas:8090` im Heimnetz); dann
verzichtet Meridian auf `Secure`, und die Oberfläche zeigt eine Warnung: Passwort und Sitzung sind im Netz mitlesbar.
Außerhalb eines vertrauenswürdigen Heimnetzes Meridian hinter einem HTTPS-Proxy betreiben.
Nach 5 Fehlversuchen pro Benutzername (50 pro IP) wird gesperrt, die Sperre wächst bis 15 Minuten.

**Hinter einem Reverse-Proxy** (z. B. Nginx Proxy Manager) dessen IP oder Netz in `MERIDIAN_TRUSTED_PROXIES` eintragen,
z. B. `MERIDIAN_TRUSTED_PROXIES=172.19.0.0/16`, und Meridian neu starten. Kommt eine Anmeldung mit einem Header
`X-Forwarded-For` oder `Forwarded` an, ohne dass `MERIDIAN_TRUSTED_PROXIES` gesetzt ist, lehnt Meridian sie ab
(HTTP 503 mit Hinweis): Sonst sähe die Sperre nach Fehlversuchen nur die IP des Proxys, und alle Clients würden
gemeinsam gesperrt. `X-Forwarded-For` gilt nur, wenn die Verbindung von einem eingetragenen Proxy kommt; ein
gefälschter Header ändert die gezählte IP nie. Meridian dann nur über den Proxy erreichbar machen.
Wer sich selbst ausgesperrt hat, hebt die Sperre an der Befehlszeile auf:
`docker compose exec web php bin/meridian auth:unlock <benutzername-oder-ip>`.

**Nie erreichbare Ziele für HTTP-Jobs**, auch nicht per Freigabe: der docker-socket-proxy (Name `docker-proxy`, weitere
Namen über `MERIDIAN_DOCKER_PROXY_HOSTS`, kommagetrennt; Docker-API-Ports 2375/2376 auf internen Adressen) und
Meridians eigener Port auf Loopback und den eigenen Adressen (`MERIDIAN_LISTEN_PORT`, Standard 8080, wie FrankenPHP
`:8080`). Lauscht Meridian auf einem anderen Port (z. B. systemd mit `--listen :9000`), `MERIDIAN_LISTEN_PORT`
entsprechend für Web **und** Scheduler setzen.

**Anzeige der Ziel-URL:** Standard ist `http.display_path = hidden` (Pfad und Query als `••••`); bestehende Jobs werden
beim nächsten `migrate` verschärft. Mit `http.display_host = hidden` verbirgt die API auch Host und Port
(`settings:set http.display_host hidden`). Zurückstellen lockert gespeicherte Anzeigen nie.

## Oberfläche

Die Weboberfläche (React + Vite, im Stil des Klickdummys „Takt“) liegt unter `frontend/` und wird vom Server unter
`/app/` ausgeliefert, mit allen Sicherheits-Headern. Das Docker-Image baut sie selbst. Zum Entwickeln:

```bash
cd frontend && npm ci
npm run dev        # Entwicklungsserver auf :5173, leitet /api an http://127.0.0.1:8080 weiter
npm run build      # Produktionsbuild nach frontend/dist
# Server lokal mit der gebauten Oberfläche (Entwicklungsmodus, weil die Anmeldung sonst HTTPS braucht):
MERIDIAN_ENV=dev MERIDIAN_UI_DIR=frontend/dist php -S 127.0.0.1:8080 -t public public/index.php
```

Nach Änderungen an `docker/Dockerfile` den Inline-Teil der `compose.yaml` neu erzeugen: `python3 docker/sync-compose.py`.

## Betrieb auf Linux mit systemd

```bash
sudo ./deploy/install.sh
```

Der Installer fragt nach dem Benutzernamen und dem Passwort des ersten Admins.
