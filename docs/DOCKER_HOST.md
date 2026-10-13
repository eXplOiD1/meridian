<!-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
     SPDX-License-Identifier: LicenseRef-Meridian-Proprietary -->
# Docker host execution / Host-Ausführung unter Docker

## English

### One file for the NAS editor: host commands as root

If you explicitly want full host privileges, use the complete [compose.host-root.yaml](../compose.host-root.yaml) instead of the override file. In the **existing `meridian` project**, replace the whole Compose configuration with that file and redeploy. It contains every service, preserves the standard project/volume names, uses beta images and enables host execution as root. No `.env`, UID/GID lookup or second file is needed. Existing accounts and passwords remain unchanged. Preserve any custom ports, proxy settings or volume paths from your original stack.

Select the existing **Host · web** target in its permitted category and test `id; hostname`. **Host · default** works as well; both names reach the same root bridge. The root bridge intentionally gives every job using either approved profile full host permissions. For restricted host users, use the configurable setup below instead.

Meridian can execute shell jobs on its Linux Docker host through an explicitly enabled **host bridge container**. No PHP installation, systemd service or SSH account is needed on the host. The host must provide `/bin/sh`; Bash jobs additionally require `/bin/bash` on the host. This is for native, rootful Linux Docker, including compatible NAS systems. Docker Desktop addresses its Linux VM, not the Windows/macOS desktop. Rootless Docker and user-namespace remapping are not supported.

**Access:** the bridge runs privileged with the host PID namespace and enters the host filesystem and network. It has effective host administration privileges. A job runs as the fixed UID/GID you configure, with supplementary groups removed and privilege elevation through setuid binaries disabled. Choose a dedicated non-root host account with only the required file permissions. The bridge has no Meridian database, key volume, Docker socket or public network port. Only the shell worker receives its private Unix socket. These boundaries do not make a privileged bridge safe for untrusted administrators: approve only intended host jobs and categories.

Host-profile approvals and container-root approvals require the acting administrator's current Meridian password when created or edited. Failed confirmations count toward the login account/IP lockout. Passwords are not stored in targets or audit entries. Existing approved profiles and scheduled jobs continue running; revoking a target does not require a password. Category and shell-job editing permissions remain enforced on each run.

### Enable

1. Put `compose.host.yaml` next to your existing `compose.yaml`. Keep the original project name and data volumes. The override also works with `compose.build.yaml`; it still pulls a separately published bridge image.
2. On the NAS/host, find the intended account's numeric IDs: `id <account>`. Create a dedicated account using the NAS administration if needed.
3. In the project's `.env` file (or the corresponding stack variables), set the actual values:

   ```dotenv
   MERIDIAN_HOST_UID=1001
   MERIDIAN_HOST_GID=1001
   MERIDIAN_HOST_PROFILE=default
   ```

   The numbers are examples. To connect the existing **Host · web** entry instead, set `MERIDIAN_HOST_PROFILE=web`. This is a profile name, not the Meridian web container. For beta images, also set `MERIDIAN_HOST_IMAGE=ghcr.io/exploid1/meridian:host-candidate` and use the matching Meridian beta image. For a reproducible version, use `host-sha-<full-commit>`.
4. Start using both files:

   ```sh
   docker compose -f compose.yaml -f compose.host.yaml pull
   docker compose -f compose.yaml -f compose.host.yaml up -d
   ```

   In a NAS/Portainer editor that accepts only one Compose file, merge the `host-bridge` service, the worker-shell socket environment/mount/dependency, and the socket volume into the existing stack. Do not create a separate project or replace its data volumes. UID/GID must be provided as stack variables if no `.env` is available.
5. In **Settings → Execution targets**, approve **Host profile `default`** (or your configured name) for the intended category. Select that category and host profile in the job editor. The per-job user and working directory remain unset; the bridge provides them.
6. First test with:

   ```sh
   id
   hostname
   pwd
   ```

   The output must show the configured host account, the host hostname and a private temporary directory. Then use the required host commands and absolute paths. Installed programs and filesystem permissions are those of the host, not the bridge image. Scripts receive the job's variables, timezone and run identifiers, not the bridge's environment.

Root execution is rejected by default. If a job genuinely requires host root access, explicitly set `MERIDIAN_HOST_UID=0`, `MERIDIAN_HOST_GID=0`, and `MERIDIAN_HOST_ALLOW_ROOT=1`, then recreate the bridge. This grants root to every job using that profile. Category permissions remain enforced by the worker before each run.

### Operation and removal

Keep both Compose files in subsequent stack commands. The application updater does not replace this optional bridge image automatically. After an application update, update the bridge separately using the same channel:

```sh
docker compose -f compose.yaml -f compose.host.yaml pull host-bridge
docker compose -f compose.yaml -f compose.host.yaml up -d --no-deps --force-recreate host-bridge
```

Run this when no host job is active: recreating/stopping the bridge terminates its runs. Cancellation, timeouts and worker disconnects terminate the job's process group. Do not detach processes into independent sessions or use the bridge for persistent daemons. Host jobs retain the bridge's resource limits and do not join the host init process's cgroup.

Check `docker compose -f compose.yaml -f compose.host.yaml logs host-bridge` if it fails to start. Verify UID/GID, explicit root approval where applicable, `pid: host`, privileged mode, the profile name and the worker's `MERIDIAN_SHELL_HOST_SOCKETS`. A profile approval alone does not start the bridge. `Test` runs remain subject to the normal category permissions.

To disable access, disable affected jobs, stop/remove `host-bridge`, remove the override and recreate `worker-shell` with the base file. Remove the host-profile approvals if no longer needed. Keep Meridian data and key volumes.

## Deutsch

### Eine Datei für den NAS-Editor: Host-Befehle als Root

Wenn ausdrücklich volle Host-Rechte gewünscht sind, die vollständige [compose.host-root.yaml](../compose.host-root.yaml) statt der Erweiterungsdatei verwenden. Im **bestehenden Projekt `meridian`** die gesamte Compose-Konfiguration durch diese Datei ersetzen und neu bereitstellen. Alle Dienste sind enthalten, die üblichen Projekt- und Volume-Namen bleiben erhalten, Beta-Images werden verwendet und Host-Ausführung als Root ist aktiviert. Keine `.env`, keine Suche nach UID/GID und keine zweite Datei nötig. Vorhandene Konten und Passwörter bleiben bestehen. Individuelle Ports, Proxy-Einstellungen oder Volume-Pfade aus dem bisherigen Stack übernehmen.

Den vorhandenen Ort **Host · web** in seiner freigegebenen Kategorie auswählen und mit `id; hostname` testen. **Host · default** funktioniert ebenfalls; beide Namen erreichen dieselbe Root-Bridge. Jeder Job mit einem dieser freigegebenen Profile erhält dabei ausdrücklich volle Host-Rechte. Für eingeschränkte Benutzer die nachfolgende konfigurierbare Einrichtung verwenden.

Meridian kann Shell-Jobs über einen ausdrücklich aktivierten **Host-Bridge-Container** auf dem Linux-Docker-Host ausführen. Auf dem Host sind weder PHP noch ein systemd-Dienst oder SSH-Zugang nötig. Der Host benötigt `/bin/sh`, für Bash-Jobs zusätzlich `/bin/bash`. Unterstützt wird natives Linux-Docker mit Root-Daemon, einschließlich kompatibler NAS-Systeme. Bei Docker Desktop ist das Ziel die Linux-VM, nicht Windows/macOS. Rootless Docker und Benutzer-Namespace-Remapping werden nicht unterstützt.

**Zugriff:** Die Bridge läuft privilegiert im PID-Namespace des Hosts und wechselt in dessen Dateisystem und Netzwerk. Sie hat damit weitreichende Host-Rechte. Jobs laufen mit der fest eingestellten UID/GID; zusätzliche Gruppen werden entfernt, eine Rechteerhöhung über setuid-Programme ist gesperrt. Verwende möglichst einen eigenen Host-Benutzer mit den benötigten Dateirechten. Die Bridge bekommt weder Meridian-Datenbank noch Schlüssel-Volume, Docker-Socket oder öffentlichen Netzwerk-Port. Nur der Shell-Worker erhält ihren Unix-Socket. Ein privilegierter Container setzt trotzdem vertrauenswürdige Administratoren voraus: Gib nur dafür vorgesehene Host-Jobs und Kategorien frei.

Host-Freigaben und Container-Root-Freigaben verlangen beim Anlegen oder Bearbeiten das aktuelle Meridian-Passwort des handelnden Administrators. Fehlversuche zählen zur Konto-/IP-Sperre der Anmeldung. Passwörter werden weder am Ausführungsort noch im Audit gespeichert. Vorhandene Freigaben und geplante Jobs laufen weiter; Entfernen einer Freigabe verlangt kein Passwort. Kategorie- und Shell-Job-Rechte werden bei jedem Lauf weiterhin geprüft.

### Aktivieren

1. `compose.host.yaml` neben der vorhandenen `compose.yaml` speichern. Projektname und Daten-Volumes beibehalten. Die Erweiterung funktioniert auch mit `compose.build.yaml`; das Bridge-Image wird dabei separat geladen.
2. Auf dem NAS/Host die IDs des vorgesehenen Benutzers ermitteln: `id <benutzer>`. Bei Bedarf einen eigenen Benutzer über die NAS-Verwaltung anlegen.
3. Tatsächliche Werte in der `.env` des Projekts bzw. den Stack-Variablen eintragen:

   ```dotenv
   MERIDIAN_HOST_UID=1001
   MERIDIAN_HOST_GID=1001
   MERIDIAN_HOST_PROFILE=default
   ```

   Die Nummern sind Beispiele. Für den vorhandenen Eintrag **Host · web** stattdessen `MERIDIAN_HOST_PROFILE=web` setzen. Das ist ein Profilname und bezeichnet nicht den Meridian-Web-Container. Für Beta zusätzlich `MERIDIAN_HOST_IMAGE=ghcr.io/exploid1/meridian:host-candidate` und das passende Meridian-Beta-Image verwenden. Eine bestimmte Version lässt sich mit `host-sha-<vollständiger-Commit>` festlegen.
4. Mit beiden Dateien starten:

   ```sh
   docker compose -f compose.yaml -f compose.host.yaml pull
   docker compose -f compose.yaml -f compose.host.yaml up -d
   ```

   Akzeptiert der NAS-/Portainer-Editor nur eine Datei, den Dienst `host-bridge`, Socket-Umgebung/Mount/Abhängigkeit von `worker-shell` und das Socket-Volume in den bestehenden Stack übernehmen. Kein neues Projekt anlegen und Daten-Volumes nicht ersetzen. Ohne `.env` UID/GID als Stack-Variablen setzen.
5. Unter **Einstellungen → Ausführungsorte** das **Host-Profil `default`** bzw. den konfigurierten Namen für die gewünschte Kategorie freigeben. Diese Kategorie und das Profil im Job wählen. Benutzer und Arbeitsverzeichnis bleiben im Job leer; die Bridge legt sie fest.
6. Zunächst folgenden Test ausführen:

   ```sh
   id
   hostname
   pwd
   ```

   Erscheinen müssen der konfigurierte Host-Benutzer, der Hostname und ein privates temporäres Verzeichnis. Danach die benötigten Host-Befehle und absoluten Pfade verwenden. Programme und Dateirechte stammen vom Host. Skripte erhalten Job-Variablen, Zeitzone und Laufkennungen, nicht die Umgebung der Bridge.

Root-Ausführung wird standardmäßig verweigert. Falls benötigt, ausdrücklich `MERIDIAN_HOST_UID=0`, `MERIDIAN_HOST_GID=0` und `MERIDIAN_HOST_ALLOW_ROOT=1` setzen und die Bridge neu erstellen. Damit laufen sämtliche Jobs dieses Profils als Host-root. Die Kategorie-Freigabe prüft der Worker weiterhin vor jedem Lauf.

### Betrieb und Entfernen

Bei späteren Stack-Befehlen beide Compose-Dateien verwenden. Der Anwendungs-Updater ersetzt dieses optionale Bridge-Image nicht automatisch. Nach einem Anwendungsupdate die Bridge separat im gleichen Kanal aktualisieren:

```sh
docker compose -f compose.yaml -f compose.host.yaml pull host-bridge
docker compose -f compose.yaml -f compose.host.yaml up -d --no-deps --force-recreate host-bridge
```

Dabei darf kein Host-Job laufen: Stoppen/Neuerstellen beendet aktive Läufe. Abbruch, Zeitlimit und Verbindungsabbruch des Workers beenden die Prozessgruppe des Jobs. Prozesse nicht in eigene Sitzungen abkoppeln; die Bridge ist nicht für dauerhafte Hintergrunddienste vorgesehen. Host-Jobs behalten die Ressourcenbegrenzung der Bridge und wechseln nicht in die cgroup des Host-init-Prozesses.

Bei Startfehlern `docker compose -f compose.yaml -f compose.host.yaml logs host-bridge` prüfen. UID/GID, gegebenenfalls ausdrückliche Root-Freigabe, `pid: host`, privilegierten Modus, Profilname und `MERIDIAN_SHELL_HOST_SOCKETS` des Workers kontrollieren. Ein freigegebenes Profil startet die Bridge nicht automatisch. Auch Testläufe prüfen die Kategorie-Freigabe.

Zum Abschalten betroffene Jobs deaktivieren, `host-bridge` stoppen/entfernen, die Erweiterung entfernen und `worker-shell` mit der Basisdatei neu erstellen. Nicht mehr benötigte Host-Freigaben entfernen. Meridian-Daten und Schlüssel-Volumes behalten.
