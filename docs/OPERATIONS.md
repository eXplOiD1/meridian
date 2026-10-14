<!-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
     SPDX-License-Identifier: LicenseRef-Meridian-Proprietary -->
# Operations: backup, restore and updates

[English](OPERATIONS.md) · [Deutsch](BETRIEB.md) · [README](../README.md#english)

Optional host commands from Docker: [setup, permissions and bridge updates](DOCKER_HOST.md#english).

Applies to both deployment modes. Run management commands with `docker compose exec web php bin/meridian …` on Docker, or `sudo -u meridian env MERIDIAN_DATA_DIR=/var/lib/meridian php /opt/meridian/bin/meridian …` on systemd.

## Storage locations

| Item | Docker | systemd |
| --- | --- | --- |
| Database: jobs, users, history, encrypted secrets | `meridian-data` volume (`/var/lib/meridian`) | `/var/lib/meridian/meridian.sqlite` |
| **Master key** | `meridian-secrets` volume (`/etc/meridian/master.key`) | `/etc/meridian/master.key` |
| Backups | `…/backups` in `meridian-data` | `/var/lib/meridian/backups` |

**Without the master key, all stored secrets are unreadable**, including URL parameters, headers, scripts, notification credentials and 2FA secrets. Backups made with `backup:create` deliberately exclude the key. Store it separately in a password manager/vault and make a copy immediately after installation. The updater's protected deployment backups additionally include the master key.

## Backup

```bash
php bin/meridian backup:create            # retains the newest 14
php bin/meridian backup:create --keep=30
php bin/meridian backup:list
```

- Works while Meridian is running. It uses `VACUUM INTO`, never a copy of the active database file. Each backup passes `quick_check` before receiving its final name (`meridian-YYYYMMDD-HHMMSS.sqlite`, permissions `0600`). Available disk space is checked first; failure reports an error without creating a backup.
- **Automatic:** the scheduler backs up daily and keeps the newest seven. `MERIDIAN_BACKUP_KEEP` accepts 1–365, or `0` to disable. Failure creates a `backup.failed` audit event; another attempt follows after an hour.
- **Backups share the database's volume.** They protect against mistakes, not storage-device failure. Copy them off the server regularly:

  ```bash
  # Docker: copy backups to a local directory
  mkdir -p meridian-backups
  docker cp meridian-web:/var/lib/meridian/backups/. ./meridian-backups/
  # systemd
  rsync -a /var/lib/meridian/backups/ nas:/path/meridian-backups/
  ```

## Restore

1. **Stop Meridian:** `docker compose stop web worker-shell`, or `systemctl stop meridian-web meridian-scheduler meridian-worker-http meridian-worker-shell`. Stop the updater during manual recovery as well: `docker compose stop updater` or `systemctl stop meridian-updater`. On systemd, stop only installed services. Restore is rejected while the scheduler is active.
2. Supply the **correct master key** (`MERIDIAN_KEY_FILE`). A backup belongs to the key used when it was created. Meridian checks before its first write and changes nothing if the key does not match.
3. On Docker, run a one-off container with the same volumes:

   ```bash
   docker compose run --rm --no-deps web php bin/meridian backup:list
   docker compose run --rm --no-deps web php bin/meridian backup:restore meridian-20261011-020000.sqlite --yes
   ```

   On systemd, run the same management command as `meridian`. The argument must be a **filename** from the backup directory, never an arbitrary path.
4. The previous database is retained as `meridian-…-vor-wiederherstellung.sqlite` in the backup directory and is never deleted automatically. Run `php bin/meridian migrate` when restoring an older schema, then restart Meridian and the updater. Restore creates a `backup.restored` audit entry.

To restore on a **new machine**, install Meridian, **place the old key at the key location first**, copy the backup into `backups`, and follow the same restore steps. Without the key, restoration stops when encrypted values are present.

## Updates

First run `backup:create` and verify that the key has a separate backup.

**Docker**

```bash
docker compose pull && docker compose up -d
```

Volumes are preserved and migrations run automatically on startup. On a NAS without Git, replace the entire Compose configuration with the current `compose.yaml`. Afterwards, verify login and a test run.

**systemd:** obtain the new source, then run as root:

```bash
systemctl stop meridian-web meridian-scheduler meridian-worker-http
# If host shell jobs are enabled, also stop:
systemctl stop meridian-worker-shell
sudo -u meridian env MERIDIAN_DATA_DIR=/var/lib/meridian php /opt/meridian/bin/meridian backup:create
cd frontend && npm ci && npm run build && cd ..
sudo bash deploy/install.sh
```

**Returning to an older version:** migrations only run forward. Restore the database backup made before the update (see Restore), then start the old application version.

## Host shell jobs on systemd

For an isolated development container, start the agent with `MERIDIAN_ENV=dev php bin/meridian-shell-agent --development-container`. Only that combination bypasses the container check. The agent must still use a separate non-root user without access to Meridian's key/database. Normal systemd units and release Compose do not use this flag. Container agent tests do not replace verification of systemd socket units.

The installer enables web, scheduler and `meridian-worker-http`. Jobs need a worker. Host shell jobs are disabled by default. Enable them with:

```bash
sudo env MERIDIAN_ENABLE_SHELL=1 bash deploy/install.sh
```

This creates `meridian-run` without key/database access, enables `meridian-shell.socket` and `meridian-worker-shell`, and sets `MERIDIAN_SHELL_HOST_SOCKETS`. In **Settings → Execution targets**, approve `host default`. Each run receives its own `meridian-run` process with a fixed environment. See [Linux installation and approvals](../README.md#linux-deployment-with-systemd).

## Post-installation checklist

- [ ] Master key stored separately from backups.
- [ ] `backup:create` run manually and visible in `backup:list`.
- [ ] Backups regularly copied off the server.
- [ ] Restore rehearsed on a test instance.

## Active monitoring

Meridian checks web URLs, TCP ports and hosts using ping. Checks run in `worker-http`, which needs network access. Without this service, no checks run.

- **Ping** requires the `ping` program (`iputils` in the image) and permission to send ICMP. The supplied Compose file sets `net.ipv4.ping_group_range` for `worker-http`, without adding a capability. On systemd, install ping and set `sysctl net.ipv4.ping_group_range="0 2147483647"`. If unavailable, the monitor reports that ping is not available in this installation.
- **Private targets**, such as 192.168.x.x and Docker hostnames, are blocked until approved under **Settings → Internal targets**, preferably per category and port.
- Alerts after the configured number of failures use the same channels as jobs, configured under **Settings → Notifications**.


[Compact Docker deployment and migration](COMPACT_DOCKER.md#english). [Automations](AUTOMATIONS.md#english).
