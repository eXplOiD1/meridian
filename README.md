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

Das Volume `meridian-secrets` sicher aufbewahren (Sicherung mitnehmen): ohne den Schlüssel sind gespeicherte
Geheimnisse verloren.

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
