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
| `user:password <name>` | Setzt das Passwort neu, z. B. wenn es vergessen wurde. Beendet alle Sitzungen des Benutzers und hebt eine Sperre auf. Eine eingerichtete 2FA bleibt bestehen. |
| `auth:unlock <benutzername-oder-ip>` | Hebt die Sperre nach zu vielen Fehlversuchen auf, auch wenn sich niemand mehr anmelden kann. |
| `admin:bootstrap` | Legt den ersten Admin aus `MERIDIAN_ADMIN_USER` / `MERIDIAN_ADMIN_PASSWORD` an. Nur wenn es noch keinen Benutzer gibt; der Docker-Start ruft das selbst auf. |
| `migrate` | Spielt ausstehende Datenbank-Änderungen ein. Docker-Start und Installer machen das selbst. |
| `key:generate <pfad>` | Erzeugt den Hauptschlüssel als Datei (Rechte 0600). Überschreibt nie eine vorhandene Datei. Docker-Start und Installer machen das selbst. |
| `scheduler:run [--once] [-v]` | Startet den Scheduler als Dauerprozess. Läuft als eigener Container (`meridian-scheduler`) bzw. systemd-Dienst. |

Beispiele:

```bash
# Passwort von "admin" neu setzen (Docker)
docker exec -it meridian-web php bin/meridian user:password admin

# Weiteren Benutzer anlegen
docker exec -it meridian-web php bin/meridian user:create jana --name "Jana" --role Operator

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

Die Sitzung liegt in einem `HttpOnly`-, `Secure`-, `SameSite=Strict`-Cookie (`__Host-meridian_session`). Deshalb
funktioniert die Anmeldung **nur über HTTPS**, etwa hinter einem Reverse-Proxy. Zum Ausprobieren über HTTP gibt es
`MERIDIAN_ENV=dev`. Nach 5 Fehlversuchen pro Benutzername (20 pro IP) wird gesperrt, die Sperre wächst bis 15 Minuten.
Läuft Meridian hinter einem Proxy, dessen IP oder Netz in `MERIDIAN_TRUSTED_PROXIES` eintragen; sonst zählt die
Sperre alle Clients als eine IP (im Log steht dann eine Warnung). Wer sich selbst ausgesperrt hat, hebt die Sperre an der
Befehlszeile auf: `docker compose exec web php bin/meridian auth:unlock <benutzername-oder-ip>`.

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
