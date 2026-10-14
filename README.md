<!-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
     SPDX-License-Identifier: LicenseRef-Meridian-Proprietary -->
# Meridian

[English](#english) · [Deutsch](#deutsch)

<a id="english"></a>

## English

Self-hosted scheduler for HTTP and shell jobs, with users, roles and permissions per category.

Documentation: [Operations manual (English)](docs/OPERATIONS.md), [Betriebshandbuch (Deutsch)](docs/BETRIEB.md), [License](LICENSE), [Third-party notices](THIRD_PARTY_NOTICES.md).

For time filters, clickable timelines, monitor alarms and public status history, see [History and monitoring](docs/HISTORY.md#english) ([Deutsch](docs/HISTORY.md#deutsch)).

For shell jobs on the Linux Docker host, see [Enable Docker host access](docs/DOCKER_HOST.md#english). This optional bridge requires explicit activation and a configured host user.

For explicitly approved host-root access, the complete [Docker host-root stack](compose.host-root.yaml) works in a NAS Compose editor without a second file or UID/GID variables.

### License

Copyright (c) 2026 Alexander von Pidoll. All rights reserved.

Meridian is proprietary software. The [Meridian Proprietary License](LICENSE) permits running unmodified copies on your own systems for personal and internal business use. Redistribution, resale, publicly distributed derivative versions and providing Meridian as a service to third parties require prior written permission. The built-in public status pages may be published.

For a public GitHub repository, forks permitted by GitHub's terms remain possible within GitHub; this license does not override those platform rights. Third-party components retain their own licenses; see [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

Email recipients can be configured per SMTP channel, category and job. Job-specific recipients replace the category default; without category recipients, the channel's recipients apply. An empty list means inherit. The selection applies to all selected email channels. Monitors also support channel selection and inherit their category's recipients.

Status pages have a German or English default language, set when creating or editing a page. New pages initially use your UI language. Visitors can switch using DE/EN without changing the saved default. Custom titles and display names are not translated.

### Automations

[Automation guide](docs/AUTOMATIONS.md#english): job and monitor events, schedules, AND/OR conditions, job actions and notifications.

### Notification channels

Create a channel under **Settings → Notifications**, then select it in the required jobs or monitors. Channel language follows your saved UI language by default; German and English can also be selected explicitly.

**Test message** starts one delivery attempt per click. Tests are not retried automatically after an error or worker interruption: a message may already have arrived even when confirmation was lost. Check the channel and test again manually if delivery is unconfirmed. Real event messages retain their retries after delivery errors.

HTTP workers check the outbox on every iteration, approximately once per second when idle. Tests no longer wait for the maintenance cycle that runs every minute. Running jobs and external providers can still delay delivery. For browser push, the delivery status means the push service accepted the message; it does not confirm that the device displayed it.

| Channel | Setup |
| --- | --- |
| Email (SMTP) | Enter server, port, sender, recipients and credentials when required. |
| ntfy | Enter server and topic. Browsers and mobile devices can subscribe to the topic in ntfy. |
| Telegram | Enter bot token and chat ID. |
| Webhook | Your destination URL; Meridian sends a JSON event. |
| Discord | Paste the complete HTTPS webhook URL for your Discord channel into the protected field. |
| Slack | Paste the complete Slack Incoming Webhook URL into the protected field. |
| Pushover | Enter app token and user/group key. When changing credentials, enter both keys; leaving them blank keeps existing values. Low, normal and high priorities are supported. |
| Home Assistant | Enter base URL, Long-Lived Access Token and notification destination. The HTTP worker must be able to reach Home Assistant; HTTPS certificates are checked. |
| Browser (Chrome / Firefox) | Enter the operator's contact email and save. Choose **Edit → Connect this browser**, give the device a name and allow browser notifications. |

#### Home Assistant: dashboard or phone notifications

The destination determines where messages appear:

| Action | Delivery |
| --- | --- |
| `persistent_notification.create` | Home Assistant's dashboard notification area; does not send phone push. |
| `notify.notify` | Uses the configured default notification service. Home Assistant determines recipients; the service must exist in your installation. |
| `notify.mobile_app_<device_id>` | Push to the selected device's Companion app. Copy the exact action name from your installation. |
| `notify.<group_name>` | A notification group already configured in Home Assistant, for example several phones. |

1. Set up the Home Assistant Companion app on your phone, connect it to your server and allow notifications.
2. In Home Assistant, open **Settings → Tools → Actions** and search for `notify.mobile_app`. Select your phone's actual action and test with `message: Meridian Direct Test`. If missing, reopen the Companion app and restart Home Assistant. If the direct test fails, first check the app and Android/iOS notification settings.
3. Under **User profile → Security → Long-lived access tokens**, create and copy a token named `Meridian`.
4. Create a Home Assistant channel in **Meridian → Settings → Notifications**. Enter a base URL reachable from Meridian's HTTP worker, for example `http://192.168.0.176:8123`, without `/api/services`. For private addresses, enable the channel's internal-network permission if needed. Enter only the token value, without `Bearer `; Meridian adds the header. HTTPS requires a valid certificate.
5. Under **Notification destination**, choose **Home Assistant dashboard**, **Default notification service**, or **Specific phone or custom notification group**. For the last option, copy the exact action from step 2 into **Home Assistant action name**, for example `notify.mobile_app_my_phone`. Do not copy the example unchanged. A working automation reveals its action under **⋮ → Edit in YAML**.
6. Save, send one **Test message**, then select and save the channel in the required jobs or monitors.

Android's **Sensor updates** and **Location** categories concern background app functions. Normal push messages without a custom channel use **General** by default. Enable this category and the app's overall notification permission. A visible dashboard test confirms dashboard delivery only.

Official guides: [Companion app notifications](https://companion.home-assistant.io/docs/notifications/notifications-basic/), [Troubleshooting](https://companion.home-assistant.io/docs/troubleshooting/faqs/), [Access tokens](https://developers.home-assistant.io/docs/auth_api/).

#### Browser push

Browser push requires HTTPS. An administrator creates the channel; each user then connects their browser under **My account → My browser notifications** and accepts the browser prompt. After creating a channel, its form stays at the connection step. Every browser/device needs its own permission. Users receive only events for jobs or monitors they currently have permission to view and where that browser channel is enabled. Permissions are checked again before sending. Personal test messages go only to the current user's devices.

If permission was denied, open the website settings next to the address bar, allow notifications and choose **Connect this browser** again. Meridian cannot automatically display a previously blocked permission prompt. **Remove** disconnects a device. Users see only their own devices, with up to ten per account and channel. On desktop, Chrome or Firefox must keep running; the Meridian tab may be closed. Operating-system settings can also block notifications. Expired subscriptions are removed during delivery. Updates preserve devices and keys.

Credentials are encrypted and are not displayed again when editing. Discord and Slack webhook URLs themselves contain credentials. After setup, send a **Test message** and check the last delivery. Category/job email recipients apply only to SMTP; other channels use their configured destinations or subscribed devices.

### Docker deployment

A job's Notifications settings can enable a runtime warning. Meridian compares completed successful runs against the median of the last 20 successful runs after at least five samples exist. 200 percent means twice the duration; the range is 110–1000 percent. Test runs are excluded and each warning is queued once per run.

#### Install as a stack or Docker project

[compose.yaml](compose.yaml) is sufficient. In a Docker manager with a Compose editor, such as UGOS:

1. Open `compose.yaml` and copy its **entire contents**.
2. Create a new stack or Docker project named `meridian`.
3. Paste into the Compose editor. If a file is required, save the contents as `compose.yaml` and select it.
4. Under `web`, replace the example credentials with your chosen username and a password of at least eight characters. Adjust the proxy setting if needed.
5. Deploy/start the project. Images are downloaded and required volumes are created automatically.
6. Open `http://<Docker-host-IP>:8090` and sign in with those credentials.

There are exactly two complete deployment files: [compose.yaml](compose.yaml) (3 containers) and [compose.host-root.yaml](compose.host-root.yaml) (4 containers with explicitly enabled host-root execution). See [compact Docker deployment](docs/COMPACT_DOCKER.md#english).

#### Command-line installation

Save `compose.yaml` in its own folder on the Docker host, replace the example credentials below, and run:

```bash
docker compose up -d
```

`ghcr.io/exploid1/meridian:latest` follows `main`. Docker managers may detect new images. On first start, the container creates the master key in `meridian-secrets` (never overwritten), the database in `meridian-data`, and the first admin. For first setup, set these values under `services.web.environment` (existing accounts are preserved):

```yaml
MERIDIAN_ADMIN_USER: "admin"
MERIDIAN_ADMIN_PASSWORD: "your-chosen-password"
MERIDIAN_TRUSTED_PROXIES: "172.19.0.0/16"
```

Before first start, choose your username and replace the example password with your own password of at least eight characters. Keep the quotes. No `.env` file or additional stack variables are needed. Meridian is available at `http://<host>:8090`; `MERIDIAN_PORT` controls the published port.

`MERIDIAN_TRUSTED_PROXIES` specifies which reverse proxies may supply the forwarded client IP. Its default is `172.19.0.0/16`; this does not create that Docker network. If your proxy uses another network, enter its actual IP or network. Separate multiple entries with commas. Trust only known proxies, preferably an individual IP. Without a reverse proxy, set it to `""`.

#### Updates through the admin UI

Opening the Updates page requests a check unless a task is already running or pending. Under **Settings → Updates**, administrators can check and confirm installation for both Docker Compose and systemd. Choose **Main (stable)** or **Beta (preview)** under **Update channel**. Switching checks the chosen branch but installs nothing. The choice persists across restarts. Beta may contain bugs; switching back may install older software and requires a compatible database.

The separate updater checks the selected channel on startup and every six hours. Installation requires admin confirmation. Docker updates are offered only after images for the exact commit are published. The updater downloads them before downtime, stops workers, backs up the database and key, starts the new version and checks services. If installation/startup fails, it attempts to restore the old version and its database. Power loss or updater interruption may require manual recovery.

**Docker/UGOS/Portainer:** Both Compose files start the updater automatically without an optional profile. Project and Compose paths are discovered from container metadata; Portainer paths are mapped to its host data volume when required. Missing/invalid metadata prevents installation.

For an existing installation, adopt the current Compose file and redeploy once. Pulling an application image alone cannot add the new service. In the stack folder:

```bash
docker compose up -d
```

The updater image is `ghcr.io/exploid1/meridian:updater-latest`. Private images need a Docker GHCR credential configuration. The updater receives direct Docker socket access for deployment; the web app does not. Preserve existing volumes. The supplied Compose file needs no manually entered stack path.

**systemd:** `deploy/install.sh` automatically configures and enables `meridian-updater.service`. Python 3, Git, Node.js 22+ with npm and the existing prerequisites must be installed. For existing installations, run the current installer with the original parameters. No separate `enable-updater.sh` invocation is required for initial setup.

The root service uses only the fixed Meridian repository and the selected `main` or `beta` branch. It builds the frontend before stopping services and installs through the supplied installer. Settings, the master key and enabled host shell jobs are preserved. The web UI runs no shell commands and cannot submit arbitrary update URLs or commits.

Updater backups are protected under `/var/lib/meridian-updater/backup-*` on systemd or the `meridian-updater-data` volume on Docker. They include the master key and must remain confidential. They are not automatically deleted: maintain free space and remove old backups only after checking them. Preserve existing volumes. Interrupted installation or failed recovery leaves the API in maintenance mode. After manual recovery, stop the updater, remove `/var/lib/meridian/updates/status.json` and any pending `request.json`, then restart it. See the [operations manual](docs/OPERATIONS.md).

Channel selection requires a current updater. Pull its new image and redeploy the Docker stack; on systemd rerun the installer and restart the updater service. Updating the helper itself likewise requires stack redeployment or rerunning `enable-updater.sh` and restarting the service. Compose structure changes/new prerequisites may still require manual setup.

Manual updates remain available: redeploy in your Docker manager with image pulling enabled, preserving volumes, or run:

```bash
docker compose pull && docker compose up -d
```

For local builds, use the Dockerfiles directly and set the corresponding `MERIDIAN_IMAGE`, `MERIDIAN_UPDATER_IMAGE`, `MERIDIAN_HOST_IMAGE` or `MERIDIAN_SANDBOX_IMAGE` variables in either complete Compose file.

For user management, password resets and unlocking, see [Commands](#commands). `MERIDIAN_ADMIN_USER` and `MERIDIAN_ADMIN_PASSWORD` apply only while no users exist. Editing Compose credentials does not modify an existing account. Change the password under **My account** or with `user:password`. You may remove the admin entries from Compose after initial setup; the account remains stored. Back up `meridian-secrets`: without its key, stored secrets are lost.

#### Shell jobs in Docker

Shell jobs execute through `docker exec` in an **approved container**, never in the Meridian container. Compose includes:

- Web, scheduler and HTTP workers share the application container. The separate shell worker has no network and reaches only the restricted Docker proxy and, in the root variant, the host socket.
- The maintenance container supervises both the updater and `wollomatic/socket-proxy`; only the updater has direct Docker socket access. The shell worker uses the restricted Unix socket. The proxy permits only `_ping`, `version`, container inspection and exec creation/start/inspection; other container operations such as create/start/remove return 403. It has no network or IP, only a Unix socket in `meridian-docker-proxy`, shared with `worker-shell`.
- `sandbox` (`meridian-sandbox`) provides busybox, bash, curl, jq, CA certificates and timezone data. It runs as user 1001 with no volumes, read-only storage except `/tmp`, 512 MiB memory and 256 processes. It is **not approved automatically**: under **Settings → Execution targets**, add `docker meridian-sandbox` with user `1001`. For more tools, build your own image/service using `docker/Dockerfile.sandbox` as a template.

Approve other running containers in **Settings → Execution targets**, with the `shell.targets` permission. No Compose/`.env` changes are required. Names must match exactly. Meridian rejects its own containers (`web`, `scheduler`, `worker-http`, `worker-shell`, `docker-proxy` and the `meridian-` prefix except `meridian-sandbox`) as execution targets.

The proxy image is pinned by version and digest. `MERIDIAN_PROXY_IMAGE` permits your own reviewed digest. If the Docker socket group is not root, set `MERIDIAN_DOCKER_GID`.

Docker API access effectively provides root privileges on the host. The proxy restricts access to exec operations but cannot validate request contents. Approve only containers where users with shell-job edit permission may run arbitrary commands. Preserve `meridian-data` and `meridian-secrets` during updates. See [Backup, restore and updates](docs/OPERATIONS.md).

### Commands

Management commands run on the server or in its container, not in the browser. Docker uses container `meridian-web`; add `-it` for commands that prompt for passwords:

```bash
docker exec -it meridian-web php bin/meridian <command> [arguments]
# or in the folder containing compose.yaml:
docker compose exec web php bin/meridian <command> [arguments]
```

For Linux/systemd:

```bash
sudo -u meridian env MERIDIAN_DATA_DIR=/var/lib/meridian php /opt/meridian/bin/meridian <command> [arguments]
```

| Command | Purpose |
| --- | --- |
| `list` | Lists commands. `<command> --help` explains arguments/options. |
| `user:create <name> [--name="Display name"] [--role=Admin\|Operator\|Beobachter]` | Creates a user, prompting privately for a password of at least eight characters. Default role: `Beobachter`. Role identifiers remain as shown. |
| `user:password <name>` | Resets a password, removes mandatory one-time-password change/expiry, ends all user sessions and unlocks the account. Existing 2FA remains enabled. Deleted users are rejected. |
| `category:create <name>` | Creates a category: 1–64 letters, digits, spaces, `_`, `.` or `-`, starting with a letter/digit. Case-insensitive duplicates are rejected. Category-restricted users need an assignment to see it. |
| `category:rename <name> <new-name>` | Finds the category case-insensitively and renames it using the same rules. Jobs, assignments and approvals remain. |
| `category:delete <name> [--yes]` | Deletes only categories without jobs, including disabled jobs. Shows effects first: assignments lose the category, and category-scoped internal target permissions are removed. Losing an assignment's only category grants no access, never all-category access. Prompts unless `--yes` is supplied. |
| `backup:create [--keep=14]` | Creates a checked database backup without the master key under `backups/`, retaining the newest files. Works live. The scheduler also backs up daily (`MERIDIAN_BACKUP_KEEP`). |
| `backup:list` | Lists backups. |
| `backup:restore <file> --yes` | Restores a filename from the backup directory, not an arbitrary path. Stop Meridian first and supply the matching key; see [Operations](docs/OPERATIONS.md). |
| `auth:unlock <username-or-ip>` | Clears a failed-login lock even if nobody can sign in. |
| `admin:bootstrap` | Creates the first admin from `MERIDIAN_ADMIN_USER`/`MERIDIAN_ADMIN_PASSWORD` only when no users exist. Docker startup runs it automatically. |
| `migrate` | Applies pending database migrations; Docker startup and installer run it automatically. |
| `key:generate <path>` | Creates a master key with permissions 0600, never overwriting an existing file. Startup/installer run it automatically. |
| `scheduler:run [--once] [-v]` | Runs the scheduler every five seconds as a supervised process in Docker or a dedicated systemd service. Only one scheduler plans jobs; another waits for the database lease, which expires at most 60 seconds after the first exits. SIGTERM stops cleanly; `--once` performs one iteration. |

Examples:

```bash
# Reset the admin password (Docker)
docker exec -it meridian-web php bin/meridian user:password admin

# Create another user
docker exec -it meridian-web php bin/meridian user:create jana --name "Jana" --role Operator

# Create a job category
docker exec -it meridian-web php bin/meridian category:create "Deuba24"

# Unlock a user or IP address
docker exec -it meridian-web php bin/meridian auth:unlock jana
docker exec -it meridian-web php bin/meridian auth:unlock 192.168.0.25

# Approve shell execution targets (container name and users; root requires explicit approval)
docker exec -it meridian-web php bin/meridian shell:targets add docker meridian-sandbox --user=1001 --category=NAS --note="Sandbox"
docker exec -it meridian-web php bin/meridian shell:targets add docker nextcloud --user=www-data --user=root --default-user=www-data
docker exec -it meridian-web php bin/meridian shell:targets list
docker exec -it meridian-web php bin/meridian shell:targets update 3 --user=www-data --default-user=www-data --note="ohne root"
docker exec -it meridian-web php bin/meridian shell:targets remove 3
```

Without `--category`, an execution approval is global; otherwise it applies only to that category. Without `--default-user`, the only approved user is the default. `update` changes only supplied fields (`--name`, `--category=NAME` or `--global`, `--user`, `--default-user`, `--note`). The target type stays fixed. Jobs using an old target/user are rejected from their next run. Shell scripts and environment variables are secrets: they cannot be read after saving and are replaced as a whole.

Passwords are prompted privately, never passed as arguments where shell history/process lists could expose them. Commands changing users or locks write audit events.

### Authentication API

| Endpoint | Purpose |
| --- | --- |
| `POST /api/auth/login` | Accepts `{"username":"...","password":"..."}`, sets session cookie and returns user, roles and CSRF token. |
| `GET /api/auth/me` | Returns current user, role permissions and session CSRF token. |
| `POST /api/auth/logout` | Ends session; requires `X-CSRF-Token`. |
| `POST /api/auth/2fa/setup` | Begins 2FA setup; returns secret and `otpauth://` address once. |
| `POST /api/auth/2fa/enable` | Confirms with `{"password":"...","code":"123456"}` and returns eight recovery codes once. |
| `POST /api/auth/2fa/disable` | Disables 2FA with `{"password":"...","code":"..."}`. |
| `GET /api/audit` | Newest-first audit log, requiring `users.manage`. Parameters: `limit` (1–200), `before_id`, `action`. |
| `POST /api/users/unlock` | Unlocks `{"username":"..."}` or `{"ip":"..."}`; requires `users.manage` and CSRF. |

With 2FA enabled, a correct password returns 401 and `"totp_required": true`; resend with `totp_code` containing an app or recovery code.

Sessions use an HttpOnly, SameSite=Strict cookie. HTTPS additionally uses Secure and the name `__Host-meridian_session`. Plain HTTP login works, for example `http://nas:8090` in a trusted home network, but omits Secure and shows a warning that passwords/sessions can be read on the network. Use an HTTPS proxy outside a trusted network. Failed logins lock after five attempts per username or 50 per IP, with increasing lock time up to 15 minutes.

**Reverse proxy:** Set its IP/network in `MERIDIAN_TRUSTED_PROXIES`. Compose defaults to `"172.19.0.0/16"`; adjust to the real network and redeploy. On systemd set `/etc/meridian/meridian.env` and restart the web service. Forwarded login headers (`X-Forwarded-For` or `Forwarded`) without trusted proxies configured are rejected with 503; otherwise every client would share the proxy's failed-login counter. Headers are trusted only from approved proxies; forged headers cannot change the counted IP. Restrict direct access to Meridian so requests go through the proxy. To unlock: `docker compose exec web php bin/meridian auth:unlock <username-or-ip>`.

**Always blocked HTTP targets:** the Docker socket proxy (`docker-proxy`, extra comma-separated names in `MERIDIAN_DOCKER_PROXY_HOSTS`, and internal Docker API ports 2375/2376) and Meridian's own listener on loopback/local addresses. `MERIDIAN_LISTEN_PORT` defaults to 8080. If the listener changes, for example `--listen :9000`, set the matching value for web and scheduler.

**Target URL visibility:** `http.display_path = hidden` masks path/query as `••••`. Existing jobs are tightened by the next migration. `http.display_host = hidden` also masks host/port (`settings:set http.display_host hidden`). Reverting settings never relaxes already stored display restrictions.

### Frontend

The React/Vite frontend lives in `frontend/` and is served at `/app/` with security headers. The Docker image builds it automatically.

### Linux deployment with systemd

Requires Linux/systemd, PHP CLI 8.3+ with `pdo_sqlite`, `sodium`, `curl`, `mbstring`, `openssl`, `pcntl` and `posix`; `gmp` is recommended for fast browser-push encryption. Also install Composer, Python 3, Git, rsync, FrankenPHP at `/usr/local/bin/frankenphp`, and Node.js 22+ with npm. The host agent uses `setsid` from `util-linux`; ping monitoring needs `iputils-ping`.

On Debian/Ubuntu (distribution PHP must be at least 8.3):

```bash
sudo apt update
sudo apt install php-cli php-curl php-mbstring php-gmp php-sqlite3 php-xml \
  composer python3 git curl ca-certificates rsync sudo util-linux iputils-ping nodejs npm
php --version
node --version
```

FrankenPHP has its own PHP runtime; CLI packages are still required for workers, scheduler and management commands. Download the matching binary from the [official releases](https://github.com/php/frankenphp/releases), verify its published SHA-256 and install it. Example for x86_64 version 1.13.0:

```bash
curl -fL -o frankenphp https://github.com/php/frankenphp/releases/download/v1.13.0/frankenphp-linux-x86_64
echo 'ddfedcc4597e502aa1b6c91da248fea88163c43b46acd4790c6e39dc1ed89a1f  frankenphp' | sha256sum -c
sudo install -m 0755 frankenphp /usr/local/bin/frankenphp
```

Get source, build the frontend and install:

```bash
git clone https://github.com/eXplOiD1/meridian.git
cd meridian
cd frontend
npm ci
npm run build
cd ..
sudo bash deploy/install.sh
```

The installer stores code/frontend under `/opt/meridian`, data under `/var/lib/meridian` and configuration/key under `/etc/meridian`. It generates the key only once, applies migrations and starts web, scheduler and HTTP workers. Services read the key through systemd credentials. Reinstallation preserves existing data, configuration and keys.

Interactive installation prompts for the first admin. Without a terminal, create one afterwards; the password is prompted privately:

```bash
sudo -u meridian env MERIDIAN_DATA_DIR=/var/lib/meridian \
  php /opt/meridian/bin/meridian user:create admin --role Admin
```

The UI is at `http://SERVER:8080/app/`. Outside a trusted home network, use an HTTPS reverse proxy; set its address in `/etc/meridian/meridian.env` as `MERIDIAN_TRUSTED_PROXIES` and restart `meridian-web`.

Enable host shell jobs:

```bash
sudo env MERIDIAN_ENABLE_SHELL=1 bash deploy/install.sh
```

Then approve **Host profile `default`** under **Settings → Execution targets**. Each job starts its own agent through `meridian-shell.socket` as `meridian-run`, without key/database access. The shell worker connects only through the Unix socket; host jobs require no Docker.

Check installation:

```bash
systemctl is-active meridian-web meridian-scheduler meridian-worker-http
curl -fsS http://127.0.0.1:8080/api/health
# If host shell jobs are enabled:
systemctl is-active meridian-shell.socket meridian-worker-shell
# Troubleshooting:
sudo journalctl -u meridian-web -u meridian-scheduler -u meridian-worker-http -n 50 --no-pager
sudo journalctl -u meridian-worker-shell --no-pager
sudo journalctl --unit='meridian-shell@*' --no-pager
```

Changes to `/etc/meridian/meridian.env` apply after restarting the affected service. For manual updates, back up, get new source, rebuild the frontend and rerun the installer. Previously enabled host jobs remain enabled. See [Operations](docs/OPERATIONS.md).

---

<a id="deutsch"></a>

## Deutsch

[Automationen](docs/AUTOMATIONS.md#deutsch) · [Kompakter Docker-Betrieb: zwei Compose-Dateien](docs/COMPACT_DOCKER.md#deutsch)

[English](#english) · [Deutsch](#deutsch)

Dokumentation: [Betriebshandbuch (Deutsch)](docs/BETRIEB.md), [Operations manual (English)](docs/OPERATIONS.md), [Lizenz](LICENSE), [Drittanbieterhinweise](THIRD_PARTY_NOTICES.md).

Selbst gehosteter Job-Scheduler für HTTP- und Shell-Jobs, mit Benutzern, Rollen und Rechten pro Kategorie.

### Lizenz

Copyright (c) 2026 Alexander von Pidoll. Alle Rechte vorbehalten.

Meridian ist proprietäre Software. Die [Meridian Proprietary License](LICENSE) erlaubt den unveränderten Betrieb auf eigenen Systemen für persönliche und interne betriebliche Zwecke. Weiterverbreitung, Weiterverkauf, öffentliche abgeleitete Versionen und das Anbieten von Meridian als Dienst für Dritte benötigen eine vorherige schriftliche Erlaubnis. Die vorgesehenen öffentlichen Statusseiten dürfen veröffentlicht werden.

Bei einem öffentlichen GitHub-Repository bleiben die von GitHubs Bedingungen erlaubten Forks innerhalb GitHubs möglich; die Lizenz hebt diese Plattformrechte nicht auf. Fremde Komponenten behalten ihre eigenen Lizenzen, siehe [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

E-Mail-Empfänger lassen sich im SMTP-Kanal, pro Kategorie und pro Job einstellen. Eigene Job-Empfänger ersetzen die Kategorie-Vorgabe; ohne Kategorie-Empfänger gelten die Empfänger des Kanals. Leer bedeutet erben. Die Auswahl gilt für alle gewählten E-Mail-Kanäle. Überwachungen bieten ebenfalls eine Kanalauswahl und übernehmen die Empfänger ihrer Kategorie.

Bei Statusseiten lässt sich die Standardsprache beim Anlegen und Bearbeiten auf Deutsch oder Englisch setzen. Neue Seiten übernehmen zunächst deine UI-Sprache. Besucher können auf der öffentlichen Seite über DE/EN wechseln; dieser Wechsel verändert die gespeicherte Standardsprache nicht. Eigene Titel und Anzeigenamen werden nicht übersetzt.

Zeitfilter, anklickbare Tagesansichten, Überwachungsalarme und die Historie der Statusseite erklärt [Verlauf und Überwachung](docs/HISTORY.md#deutsch) ([English](docs/HISTORY.md#english)).

Shell-Jobs auf dem Linux-Docker-Host: [Docker-Host-Zugriff aktivieren](docs/DOCKER_HOST.md#deutsch). Die optionale Bridge benötigt eine ausdrückliche Aktivierung und einen konfigurierten Host-Benutzer.

Für ausdrücklich freigegebenen Host-root-Zugriff gibt es den vollständigen [Docker-Stack mit Host-root](compose.host-root.yaml): im NAS-Editor ohne zweite Datei und ohne UID/GID-Variablen verwendbar.

### Benachrichtigungskanäle

Unter **Einstellungen → Benachrichtigungen** einen Kanal anlegen und anschließend bei den gewünschten Jobs oder Überwachungen auswählen. Die Sprache des Kanals folgt standardmäßig deiner gespeicherten UI-Sprache; Deutsch und Englisch lassen sich auch fest einstellen.

**Testnachricht** startet einen einzigen Zustellversuch pro Klick. Tests werden bei Fehlern oder nach einem Worker-Abbruch nicht automatisch wiederholt, da eine Nachricht trotz fehlender Bestätigung bereits angekommen sein kann. Bei nicht bestätigter Zustellung den Kanal prüfen und bei Bedarf erneut manuell testen. Echte Ereignismeldungen behalten ihre Wiederholungsversuche bei Zustellfehlern.

Der HTTP-Worker prüft den Benachrichtigungsausgang in jedem Durchgang, bei freien Workern etwa jede Sekunde. Tests warten deshalb nicht mehr auf die Wartung im Minutenrhythmus. Laufende Aufträge sowie der externe Anbieter können die Zustellung weiterhin verzögern. Bei Browser-Push bedeutet der Versandstatus „zugestellt“, dass der Push-Dienst die Nachricht angenommen hat; er bestätigt nicht ihre Anzeige auf dem Gerät.

| Kanal | Einrichtung |
|---|---|
| E-Mail (SMTP) | Server, Port, Absender, Empfänger und gegebenenfalls Anmeldedaten eintragen. |
| ntfy | Server und Thema eintragen; Browser und Mobilgeräte können das Thema in ntfy abonnieren. |
| Telegram | Bot-Token und Chat-ID eintragen. |
| Webhook | Eigene Zieladresse; Meridian sendet ein JSON-Ereignis. |
| Discord | Die vollständige HTTPS-Webhook-Adresse des Discord-Kanals im geschützten Feld eintragen. |
| Slack | Die vollständige Slack-Incoming-Webhook-Adresse im geschützten Feld eintragen. |
| Pushover | App-Token und User/Group-Key eintragen. Beim Ändern beide Schlüssel erneut eingeben; leer lassen behält die gespeicherten Werte. Prioritäten niedrig, normal und hoch sind unterstützt. |
| Home Assistant | Basisadresse, Long-Lived Access Token und Zielaktion eintragen. `persistent_notification.create` erzeugt Dashboard-Meldungen; `notify.mobile_app_name` sendet über die Companion-App. Der HTTP-Worker muss Home Assistant erreichen können; HTTPS-Zertifikate werden geprüft. |
| Browser (Chrome / Firefox) | Kontakt-E-Mail eintragen und den Kanal speichern. Danach **Bearbeiten → Diesen Browser verbinden**, einen Gerätenamen vergeben und die Benachrichtigungen im Browser erlauben. |

#### Home Assistant: Dashboard oder Handy-Push einrichten

Die Zielaktion entscheidet, wo eine Nachricht ankommt:

| Zielaktion | Zustellung |
| --- | --- |
| `persistent_notification.create` | Benachrichtigungsbereich im Home-Assistant-Dashboard; erzeugt keinen Handy-Push. |
| `notify.notify` | Verwendet den eingerichteten Standard-Benachrichtigungsdienst. Die Empfänger bestimmt Home Assistant; der Dienst muss dort vorhanden sein. |
| `notify.mobile_app_<gerätekennung>` | Push an die Home-Assistant-Companion-App des ausgewählten Geräts. Der vollständige Name muss aus deiner Installation übernommen werden. |
| `notify.<gruppenname>` | Eine in Home Assistant bereits konfigurierte Benachrichtigungsgruppe, beispielsweise für mehrere Handys. |

1. Für Handy-Push die Home-Assistant-Companion-App auf dem Handy einrichten, mit deinem Server verbinden und Benachrichtigungen erlauben.
2. In Home Assistant unter **Einstellungen → Werkzeuge → Aktionen** nach `notify.mobile_app` suchen. Die tatsächliche Aktion deines Handys auswählen und mit `message: Meridian Direkt-Test` testen. Fehlt die Aktion, die Companion-App erneut öffnen und Home Assistant neu starten. Kommt schon dieser direkte Test nicht an, zuerst die Companion-App und deren Android-/iOS-Benachrichtigungseinstellungen prüfen.
3. In Home Assistant unter **Benutzerprofil → Sicherheit → Langlebige Zugriffstoken** ein Token namens `Meridian` erstellen und kopieren.
4. In Meridian unter **Einstellungen → Benachrichtigungen** einen Home-Assistant-Kanal anlegen. Als Adresse die vom Meridian-HTTP-Worker erreichbare Basisadresse eintragen, beispielsweise `http://192.168.0.176:8123`, ohne `/api/services`. Bei internen Adressen gegebenenfalls die interne Netzwerkfreigabe des Kanals aktivieren. Als Token nur den Tokenwert ohne `Bearer ` einsetzen; Meridian ergänzt den Header. Bei HTTPS muss das Zertifikat gültig sein.
5. Unter **Benachrichtigungsziel** wählen: **Home-Assistant-Dashboard** für Dashboard-Meldungen, **Standard-Benachrichtigungsdienst** für das bestehende `notify.notify`, oder **Bestimmtes Handy oder eigene Benachrichtigungsgruppe**. Bei der letzten Auswahl den vollständigen Namen aus Schritt 2 im Feld **Home-Assistant-Aktionsname** eintragen, beispielsweise `notify.mobile_app_mein_handy`. Das Beispiel nicht unverändert übernehmen. Der Name einer funktionierenden Automation ist über deren Aktion **⋮ → In YAML bearbeiten** sichtbar.
6. Kanal speichern und einmal **Testnachricht** auslösen. Anschließend den Kanal bei den gewünschten Jobs oder Überwachungen auswählen und speichern.

Die Android-Kategorien **Sensor-Aktualisierungen** und **Standort** betreffen Hintergrundfunktionen der Companion-App. Eine normale Push-Nachricht ohne eigene Kanalangabe verwendet standardmäßig die Kategorie **General/Allgemein**. Diese Kategorie und die allgemeine Benachrichtigungsfreigabe der App müssen aktiviert sein. Eine sichtbare Dashboard-Testnachricht bestätigt nur die Zustellung ins Dashboard, nicht auf das Handy.

Offizielle Anleitungen: [Companion-App-Benachrichtigungen](https://companion.home-assistant.io/docs/notifications/notifications-basic/), [Fehlersuche](https://companion.home-assistant.io/docs/troubleshooting/faqs/), [Zugriffstoken](https://developers.home-assistant.io/docs/auth_api/).

#### Browser-Push

Direktes Browser-Push benötigt HTTPS. Ein Admin legt den Kanal an; danach verbindet jeder Nutzer seinen Browser unter **Mein Konto → Meine Browser-Benachrichtigungen** und bestätigt die Browser-Abfrage. Nach dem Anlegen bleibt das Kanalformular direkt beim Verbindungsschritt. Jeder Browser bzw. jedes Gerät benötigt eine eigene Freigabe. Nutzer erhalten nur Meldungen zu Jobs und Überwachungen, die sie aktuell sehen dürfen und für die dieser Browser-Kanal aktiviert ist. Entzogene Rechte werden auch vor dem Versand erneut geprüft. Die persönlichen Testnachrichten gehen nur an Geräte des eigenen Kontos.

Bei abgelehnter Freigabe die Website-Einstellungen über das Symbol neben der Browser-Adresse öffnen, Benachrichtigungen erlauben und erneut **Diesen Browser verbinden** wählen. Eine zuvor blockierte Berechtigungsabfrage darf Meridian nicht automatisch erneut anzeigen. Über **Entfernen** lässt sich ein Gerät später abmelden. Nur eigene Geräte sind sichtbar; bis zu zehn Geräte pro Konto und Kanal sind möglich. Auf Desktop muss Chrome bzw. Firefox weiter laufen, der Meridian-Tab darf geschlossen sein. Betriebssystem-Einstellungen können Benachrichtigungen zusätzlich blockieren. Abgelaufene Abonnements entfernt Meridian beim Versand. Geräte und Schlüssel bleiben bei Updates erhalten.

Zugangsdaten werden verschlüsselt gespeichert und beim Bearbeiten nicht wieder angezeigt. Bei Discord und Slack ist die Webhook-Adresse selbst ein Zugangsschlüssel. Nach der Einrichtung am Kanal **Testnachricht** auslösen und die letzte Zustellung prüfen. E-Mail-Empfänger pro Kategorie bzw. Job betreffen nur SMTP; die anderen Kanäle verwenden ihre jeweils konfigurierten Ziele oder verbundenen Geräte.

### Betrieb mit Docker

Im Job unter „Benachrichtigungen“ lässt sich eine Laufzeitwarnung aktivieren. Meridian vergleicht abgeschlossene erfolgreiche Läufe mit dem Median der letzten 20 erfolgreichen Läufe, sobald mindestens fünf Vergleichswerte vorliegen. 200 Prozent bedeutet doppelte Dauer; einstellbar sind 110 bis 1000 Prozent. Testläufe zählen nicht, und jede Warnung wird einmal pro Lauf eingereiht.

#### Installation als Stack oder Docker-Projekt

Für die Installation genügt [compose.yaml](compose.yaml). In einer Docker-Verwaltung mit Compose-Editor, zum Beispiel der UGOS-Docker-App:

1. Die Datei `compose.yaml` öffnen und ihren **gesamten Inhalt** kopieren.
2. Einen neuen Stack bzw. ein neues Docker-Projekt mit dem Namen `meridian` erstellen.
3. Den kopierten Inhalt in den Compose-Editor einfügen. Falls die Verwaltung eine Datei verlangt, den Inhalt als `compose.yaml` speichern und diese auswählen.
4. Im eingefügten Compose-Inhalt unter `web` die Beispiel-Zugangsdaten durch den gewünschten Benutzernamen und ein eigenes Passwort mit mindestens acht Zeichen ersetzen (siehe unten). Den Proxy-Eintrag bei Bedarf anpassen.
5. Den Stack bereitstellen bzw. das Projekt starten. Die Images werden heruntergeladen und die benötigten Volumes automatisch angelegt.
6. `http://<IP-des-Docker-Hosts>:8090` öffnen und mit den eingetragenen Zugangsdaten anmelden.

Es gibt genau zwei vollständige Dateien: [compose.yaml](compose.yaml) (3 Container) und [compose.host-root.yaml](compose.host-root.yaml) (4 Container mit ausdrücklich aktiviertem Host-Root-Zugriff). Siehe [kompakter Docker-Betrieb](docs/COMPACT_DOCKER.md#deutsch).

#### Installation über die Kommandozeile

Die Datei `compose.yaml` auf dem Docker-Host in einem eigenen Ordner speichern, die Beispiel-Zugangsdaten wie unten beschrieben ersetzen und dort ausführen:

```bash
docker compose up -d
```

Das Image `ghcr.io/exploid1/meridian:latest` folgt dem Branch `main`. Docker-Verwaltungen können neue Images erkennen. Beim ersten Start legt der Container selbst an: den Hauptschlüssel
(Volume `meridian-secrets`, wird nie überschrieben), die Datenbank (Volume `meridian-data`) und den ersten Admin.
Die Compose-Dateien enthalten diese direkt eingetragenen Beispielwerte unter `services.web.environment`:

```yaml
MERIDIAN_ADMIN_USER: "admin"
MERIDIAN_ADMIN_PASSWORD: "your-chosen-password"
MERIDIAN_TRUSTED_PROXIES: "172.19.0.0/16"
```

Vor dem ersten Start den Benutzernamen nach Wunsch und das Beispielpasswort unbedingt durch ein eigenes Passwort mit mindestens acht Zeichen ersetzen. Die Anführungszeichen beibehalten. Dafür ist keine `.env`-Datei und keine zusätzliche Stack-Variable nötig. Erreichbar ist Meridian unter `http://<host>:8090` (Port über `MERIDIAN_PORT`).

`MERIDIAN_TRUSTED_PROXIES` legt fest, von welchen Reverse-Proxys Meridian die weitergeleitete Client-IP übernimmt. Standardmäßig ist das Netz `172.19.0.0/16` eingetragen; dadurch wird dieses Netz nicht automatisch für Docker eingerichtet. Liegt dein Proxy in einem anderen Netz, den Wert durch seine IP oder sein tatsächliches Netz ersetzen. Mehrere Einträge mit Komma trennen. Nur vertrauenswürdige Proxy-Adressen eintragen, möglichst eine einzelne IP. Ohne Reverse-Proxy den Wert auf `""` setzen.

#### Aktualisieren

##### Updates über die Admin-Oberfläche

Beim Öffnen der Updates-Seite wird automatisch eine Prüfung angefordert, sofern kein Vorgang läuft oder bereits wartet. Unter **Einstellungen → Updates** können Administratoren nach Updates suchen und eine Aktualisierung bestätigen. Derselbe Ablauf unterstützt Docker Compose und systemd. Unter **Update-Kanal** wählen Admins **Main (stabil)** oder **Beta (Vorabversion)**. Der Wechsel prüft den gewählten Branch, installiert aber noch nichts. Die Auswahl bleibt bei Neustarts erhalten. Beta kann Fehler enthalten; ein Rückwechsel kann ältere Software installieren und setzt eine kompatible Datenbank voraus. Der getrennte Update-Dienst prüft den gewählten Kanal beim Start und automatisch alle sechs Stunden; die Installation beginnt ausschließlich nach einer Admin-Bestätigung. Bei Docker wird erst ein Update angeboten, wenn die Images für den konkreten Commit veröffentlicht sind. Der Dienst lädt sie vor der Unterbrechung herunter, beendet die Worker, sichert Datenbank und Hauptschlüssel, startet die neue Version und prüft ihre Dienste. Bei einem Installations- oder Startfehler versucht er, die vorherige Version mit ihrer Datenbank wiederherzustellen. Bei Stromausfall oder Abbruch des Update-Dienstes kann eine manuelle Wiederherstellung nötig sein.

**Docker/UGOS/Portainer:** Beide Dateien starten den Wartungsdienst automatisch. Projekt- und Compose-Pfade werden aus den Container-Metadaten ermittelt. Ungültige Metadaten verhindern ein Update.

Bestehende Installationen: die aktuelle Compose-Datei übernehmen und den Stack einmal neu bereitstellen. Ein Image-Pull allein fügt den neuen Dienst nicht hinzu. Mit der CLI im Stack-Ordner:

```bash
docker compose up -d
```

Das Image liegt unter `ghcr.io/exploid1/meridian:updater-latest`. Private Images benötigen eine Docker-Zugangskonfiguration für GHCR. Nur der Updater erhält den direkten Docker-Socket und damit Rechte zur Container-Verwaltung. Die vorhandenen Volumes beibehalten. Ein manuell eingetragener Stack-Pfad ist für die mitgelieferte Compose-Datei nicht erforderlich.

**systemd:** `deploy/install.sh` richtet `meridian-updater.service` automatisch ein und aktiviert ihn. Python 3, Git, Node.js 22+ mit npm sowie die bisherigen Voraussetzungen müssen installiert sein. Bei bestehenden Installationen den aktuellen Installer mit den bisherigen Installationsparametern erneut ausführen. Der zusätzliche manuelle Aufruf von `enable-updater.sh` entfällt.

Der root-Dienst `meridian-updater.service` verwendet ausschließlich das feste Meridian-Repository und den ausgewählten Branch `main` oder `beta`. Er baut die neue Oberfläche vor dem Stoppen der Dienste und installiert über den mitgelieferten Installer. Einstellungen, Hauptschlüssel und aktivierte Shell-Jobs bleiben erhalten. Die Oberfläche führt selbst keine Shell-Befehle aus und kann keine eigenen Update-Adressen oder Commits übergeben.

Sicherungen dieses Dienstes liegen bei systemd geschützt unter `/var/lib/meridian-updater/backup-*`, bei Docker im Volume `meridian-updater-data`. Sie enthalten den Hauptschlüssel und müssen vertraulich behandelt werden. Sie werden nicht automatisch gelöscht; ausreichend Speicher vorhalten und ältere Sicherungen erst nach Prüfung entfernen. Die bestehenden Volumes nicht löschen. Nach abgebrochener Installation oder fehlgeschlagener Wiederherstellung bleibt die API im Wartungsmodus. Erst nach manueller Wiederherstellung den Updater stoppen, `/var/lib/meridian/updates/status.json` und eine eventuell wartende `request.json` entfernen und den Updater erneut starten. Die normalen Sicherungs- und Wiederherstellungsschritte stehen in `docs/BETRIEB.md`.

Die Kanalwahl benötigt einen aktuellen Update-Dienst. Bei älteren Installationen zuerst das Updater-Image neu abrufen und den Stack bereitstellen; bei systemd den Installer erneut ausführen und den Updater-Dienst danach neu starten.

Der Update-Dienst selbst wird durch erneutes Bereitstellen des Docker-Stacks bzw. erneutes Ausführen von `enable-updater.sh` mit anschließendem Neustart von `meridian-updater.service` aktualisiert. Strukturänderungen an der Compose-Datei oder neue Systemvoraussetzungen können weiterhin einen manuellen Installationsschritt erfordern.

Die bisherigen manuellen Update-Befehle bleiben verfügbar:

In der Docker-Verwaltung den bestehenden Stack erneut bereitstellen und dabei das erneute Abrufen der Images aktivieren. Die vorhandenen Volumes beibehalten. Über die Kommandozeile:

```bash
docker compose pull && docker compose up -d
```

Zum Selbstbauen dienen die Dockerfiles. Die Images lassen sich über `MERIDIAN_IMAGE`, `MERIDIAN_UPDATER_IMAGE`, `MERIDIAN_HOST_IMAGE` und `MERIDIAN_SANDBOX_IMAGE` in einer der beiden vollständigen Compose-Dateien auswählen.

Benutzer verwalten, Passwort zurücksetzen, Sperren aufheben: siehe [Befehle](#befehle).
`MERIDIAN_ADMIN_USER` und `MERIDIAN_ADMIN_PASSWORD` gelten nur beim allerersten Start, solange es noch keinen Benutzer gibt.
Bei einer bestehenden Installation ändert das Bearbeiten der Compose-Zugangsdaten das Konto nicht. Das Passwort über „Mein Konto“ oder `user:password` ändern. Nach der ersten Einrichtung können die beiden Admin-Einträge aus der Compose-Datei entfernt werden; das Konto bleibt gespeichert.

Das Volume `meridian-secrets` sicher aufbewahren (Sicherung mitnehmen): ohne den Schlüssel sind gespeicherte
Geheimnisse verloren.

#### Shell-Jobs im Docker-Betrieb

Shell-Jobs laufen nie im Meridian-Container, sondern per `docker exec` in einem **freigegebenen Container**.
Die `compose.yaml` bringt dafür mit:

- Webserver, Scheduler und HTTP-Worker laufen gemeinsam im Anwendungscontainer. Der Shell-Worker bleibt getrennt und ohne Netzwerk.
  (kein Hauptschlüssel, kein Netz). `worker-shell` hat kein Netz und erreicht nur den Docker-Proxy.
- Der Wartungscontainer vereint Updater und eingeschränkten Docker-Socket-Proxy. Nur der Updater hat direkten Docker-Zugriff; der Shell-Worker nutzt den eingeschränkten Unix-Socket.
  Er lässt nur `_ping`, `version`, Container-Info und Exec (anlegen, starten, abfragen) durch;
  `containers/create`, `start`, `rm` u. a. gibt es nicht (403). Er hat kein Netz und keine IP-Adresse, sondern einen
  Unix-Socket im Volume `meridian-docker-proxy`, das nur er und `worker-shell` einbinden.
- `sandbox` (`meridian-sandbox`): Standard-Ausführungsort mit busybox, bash, curl, jq, ca-certificates, tzdata,
  Benutzer 1001, ohne Volumes, schreibgeschützt (nur `/tmp`), 512 MiB, 256 Prozesse. Er wird **nicht automatisch
  freigegeben**: in Meridian unter Einstellungen -> Ausführungsorte `docker meridian-sandbox` mit Benutzer `1001`
  eintragen. Mehr Werkzeuge: eigenes Image und eigener Dienst (`docker/Dockerfile.sandbox` als Vorlage).

Weitere Container freigeben: nur in Meridian unter Einstellungen -> Ausführungsorte (Admin mit dem Recht
`shell.targets`); in `compose.yaml` oder `.env` ist nichts einzutragen. Der Container muss laufen und der Name exakt
stimmen. Meridians eigene Container (`web`, `scheduler`, `worker-http`, `worker-shell`, `docker-proxy`, alles mit dem
Präfix `meridian-` außer `meridian-sandbox`) lehnt Meridian als Ausführungsort ab.

Das Proxy-Image ist standardmäßig mit Version und Digest festgelegt. `MERIDIAN_PROXY_IMAGE` erlaubt einen eigenen geprüften Digest. Hat `/var/run/docker.sock` eine andere Gruppe als root, `MERIDIAN_DOCKER_GID` setzen.

**Sicherheitshinweis:** Ein Zugang zur Docker-API ist faktisch Root auf dem Host. Der Proxy begrenzt ihn auf Exec in den
eingetragenen Containern, kann aber den Inhalt einer Anfrage nicht prüfen. Nur Container freigeben, in denen Admins mit
dem Recht „Shell-Jobs bearbeiten“ beliebige Befehle ausführen dürfen. Die Volumes `meridian-data` und `meridian-secrets` beim Aktualisieren erhalten.

Sicherung, Wiederherstellung und Aktualisieren: [`docs/BETRIEB.md`](docs/BETRIEB.md).

### Befehle

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
| `backup:create [--keep=14]` | Sichert die Datenbank (geprüft, ohne Hauptschlüssel) nach `backups/` und behält die neuesten. Läuft im Betrieb; der Planer sichert zusätzlich täglich (`MERIDIAN_BACKUP_KEEP`). |
| `backup:list` | Listet die Sicherungen. |
| `backup:restore <datei> --yes` | Spielt eine Sicherung ein (Dateiname, kein Pfad; Meridian vorher anhalten; der Hauptschlüssel muss passen). Details: [`docs/BETRIEB.md`](docs/BETRIEB.md). |
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

# Ausführungsorte für Shell-Jobs freigeben (Container-Name, erlaubte Benutzer; root nur, wenn ausdrücklich genannt)
docker exec -it meridian-web php bin/meridian shell:targets add docker meridian-sandbox --user=1001 --category=NAS --note="Sandbox"
docker exec -it meridian-web php bin/meridian shell:targets add docker nextcloud --user=www-data --user=root --default-user=www-data
docker exec -it meridian-web php bin/meridian shell:targets list
docker exec -it meridian-web php bin/meridian shell:targets update 3 --user=www-data --default-user=www-data --note="ohne root"
docker exec -it meridian-web php bin/meridian shell:targets remove 3
```

Ohne `--category` gilt die Freigabe global, sonst nur für Jobs dieser Kategorie. Ohne `--default-user` gilt der einzige
angegebene Benutzer als Standard. `update` ändert nur die genannten Felder (`--name`, `--category=NAME` oder `--global`, `--user`, `--default-user`, `--note`); die Art bleibt fest, Jobs mit dem bisherigen Ort oder Benutzer werden ab dem nächsten Lauf abgelehnt. Skript und Umgebungsvariablen eines Shell-Jobs sind Geheimnisse: Sie sind nach dem
Speichern nicht mehr lesbar und werden nur als Ganzes ersetzt.

Passwörter werden nie als Argument übergeben (sie würden in der Shell-History und der Prozessliste landen),
sondern immer verdeckt abgefragt. Jeder dieser Befehle, der Benutzer oder Sperren ändert, schreibt einen Eintrag ins Audit-Log.

### Anmeldung (API)

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

**Hinter einem Reverse-Proxy** (z. B. Nginx Proxy Manager) dessen IP oder Netz in `MERIDIAN_TRUSTED_PROXIES` eintragen.
In den Compose-Dateien ist dafür bereits `MERIDIAN_TRUSTED_PROXIES: "172.19.0.0/16"` eingetragen; an das tatsächliche Proxy-Netz anpassen und Meridian neu bereitstellen. Bei der systemd-Installation den Wert in `/etc/meridian/meridian.env` setzen und den Web-Dienst neu starten. Kommt eine Anmeldung mit einem Header
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

### Oberfläche

Die Weboberfläche (React + Vite) liegt unter `frontend/` und wird vom Server unter
`/app/` ausgeliefert, mit allen Sicherheits-Headern. Das Docker-Image baut sie selbst.

### Betrieb auf Linux mit systemd

Voraussetzungen: ein Linux-Host mit systemd, PHP 8.3 oder neuer für die CLI (mit `pdo_sqlite`, `sodium`, `curl`, `mbstring`, `openssl`, `pcntl` und `posix`; für schnelle Browser-Push-Verschlüsselung außerdem `gmp`), Composer, Python 3, Git, rsync und FrankenPHP unter `/usr/local/bin/frankenphp`. Zum Bauen der Oberfläche wird Node.js 22 oder neuer mit npm benötigt. Der Host-Agent verwendet `setsid` aus `util-linux`; für Ping-Prüfungen wird `iputils-ping` benötigt.

Unter Debian/Ubuntu die Pakete installieren (die PHP-Version der Distribution muss mindestens 8.3 sein):

```bash
sudo apt update
sudo apt install php-cli php-curl php-mbstring php-gmp php-sqlite3 php-xml \
  composer python3 git curl ca-certificates rsync sudo util-linux iputils-ping nodejs npm
php --version
node --version
```

FrankenPHP enthält eine eigene PHP-Laufzeit; die CLI-Pakete werden trotzdem für Scheduler, Worker und Verwaltungsbefehle benötigt. Die passende Linux-Binärdatei von den [offiziellen Releases](https://github.com/php/frankenphp/releases) herunterladen, die dort angegebene SHA-256-Prüfsumme prüfen und installieren. Beispiel für x86_64 mit Version 1.13.0:

```bash
curl -fL -o frankenphp https://github.com/php/frankenphp/releases/download/v1.13.0/frankenphp-linux-x86_64
echo 'ddfedcc4597e502aa1b6c91da248fea88163c43b46acd4790c6e39dc1ed89a1f  frankenphp' | sha256sum -c
sudo install -m 0755 frankenphp /usr/local/bin/frankenphp
```

Quellcode holen, Oberfläche bauen und installieren:

```bash
git clone https://github.com/eXplOiD1/meridian.git
cd meridian
cd frontend
npm ci
npm run build
cd ..
sudo bash deploy/install.sh
```

Der Installer legt Programmcode und Oberfläche unter `/opt/meridian`, Daten unter `/var/lib/meridian` und Konfiguration sowie Hauptschlüssel unter `/etc/meridian` ab. Er erzeugt den Schlüssel nur beim ersten Mal, spielt Migrationen ein und startet Web, Scheduler und HTTP-Worker. Die Dienste lesen den Hauptschlüssel über systemd-Credentials. Bestehende Daten, Schlüssel und Konfiguration bleiben bei erneuter Installation erhalten.

Beim interaktiven Start fragt der Installer nach dem ersten Admin. Bei einer Installation ohne Terminal anschließend selbst anlegen; das Passwort wird verdeckt abgefragt:

```bash
sudo -u meridian env MERIDIAN_DATA_DIR=/var/lib/meridian \
  php /opt/meridian/bin/meridian user:create admin --role Admin
```

Die Oberfläche ist unter `http://SERVER:8080/app/` erreichbar. Für Zugriff außerhalb eines vertrauenswürdigen Heimnetzes einen HTTPS-Reverse-Proxy verwenden; dessen IP in `/etc/meridian/meridian.env` als `MERIDIAN_TRUSTED_PROXIES` eintragen und `meridian-web` neu starten.

Shell-Jobs auf dem Host zusätzlich aktivieren:

```bash
sudo env MERIDIAN_ENABLE_SHELL=1 bash deploy/install.sh
```

Danach unter Einstellungen → Ausführungsorte **Host-Profil `default`** freigeben. Der Agent läuft für jeden Auftrag über `meridian-shell.socket` als eigener Benutzer `meridian-run`, ohne Zugriff auf Schlüssel oder Datenbank. Der Shell-Worker verbindet sich ausschließlich über den Unix-Socket; Docker ist für Host-Jobs nicht erforderlich.

Installation prüfen:

```bash
systemctl is-active meridian-web meridian-scheduler meridian-worker-http
curl -fsS http://127.0.0.1:8080/api/health
# Bei aktivierten Host-Jobs:
systemctl is-active meridian-shell.socket meridian-worker-shell
# Bei Fehlern:
sudo journalctl -u meridian-web -u meridian-scheduler -u meridian-worker-http -n 50 --no-pager
sudo journalctl -u meridian-worker-shell --no-pager
sudo journalctl --unit='meridian-shell@*' --no-pager
```

Änderungen an `/etc/meridian/meridian.env` werden nach dem Neustart der betreffenden Dienste wirksam. Aktualisieren: Sicherung erstellen, neue Quellen holen, die Oberfläche erneut bauen und den Installer erneut ausführen; bereits aktivierte Host-Jobs bleiben aktiviert. Einzelheiten zu Sicherung und Wiederherstellung stehen in [BETRIEB.md](docs/BETRIEB.md).
