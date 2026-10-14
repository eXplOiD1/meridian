# Docker host execution / Host-Ausführung aus Docker

[English](#english) · [Deutsch](#deutsch) · [README](../README.md)

## English

Use the complete [compose.host-root.yaml](../compose.host-root.yaml) instead of the normal [compose.yaml](../compose.yaml). No override file, manual NAS user or SSH setup is needed. Preserve the project name and existing volumes when replacing the NAS stack configuration. See [compact deployment](COMPACT_DOCKER.md#english).

This variant explicitly starts a privileged host bridge with `pid: host`, no network, UID/GID 0 and `MERIDIAN_HOST_ALLOW_ROOT=1`. Host commands run in the host namespaces through `nsenter`, with their own temporary working directory and a controlled environment. A private Unix socket connects the isolated shell worker to the bridge. Job cancellation or disconnect terminates the job's process group. The bridge has no database, master-key or Docker-socket mount.

In **Settings → Execution targets**, approve a Host profile named `web` (or `default`), preferably for a specific category. Confirm your current Meridian password when approving or changing a host target. Then choose that category, Host target and a shell script in the job editor. Test first with `id -u; id -g`: the root variant returns `0` and `0`. Scheduled jobs run without further password prompts, subject to current permissions and target approval.

Host-root access can change or delete anything on the NAS. Account permissions and password confirmation restrict configuration, but cannot make arbitrary approved root scripts harmless. Use the normal Compose file when host access is unnecessary. To use a fixed non-root NAS user instead, edit the bridge's numeric `MERIDIAN_HOST_UID` and `MERIDIAN_HOST_GID` and set `MERIDIAN_HOST_ALLOW_ROOT=0` in the same complete file.

The normal application and maintenance containers cannot execute Host jobs. Selecting a Host profile alone does not grant access: the bridge must be deployed. Updates include an existing bridge and wait for its health before starting the shell worker. To update manually, pull/redeploy the entire selected file; do not update the application image alone. Troubleshoot with `docker compose -f compose.host-root.yaml logs host-bridge` and check its health, host PID namespace, privileged mode and the worker's `MERIDIAN_SHELL_HOST_SOCKETS` mapping. A disabled or unavailable bridge fails Host jobs explicitly.

## Deutsch

Die vollständige [compose.host-root.yaml](../compose.host-root.yaml) anstelle der normalen [compose.yaml](../compose.yaml) verwenden. Keine Erweiterungsdatei, manuelle NAS-Benutzer-Einrichtung oder SSH-Konfiguration nötig. Beim Ersetzen der NAS-Stack-Konfiguration Projektname und bestehende Volumes beibehalten. Siehe [kompakter Betrieb](COMPACT_DOCKER.md#deutsch).

Diese Variante startet ausdrücklich eine privilegierte Host-Bridge mit `pid: host`, ohne Netzwerk, mit UID/GID 0 und `MERIDIAN_HOST_ALLOW_ROOT=1`. Host-Befehle laufen über `nsenter` in den Host-Namensräumen, mit eigenem temporären Arbeitsverzeichnis und kontrollierter Umgebung. Ein privater Unix-Socket verbindet den isolierten Shell-Worker mit der Bridge. Abbruch oder Verbindungsverlust beendet die Prozessgruppe des Jobs. Die Bridge erhält weder Datenbank noch Hauptschlüssel oder Docker-Socket.

Unter **Einstellungen → Ausführungsorte** ein Host-Profil namens `web` (oder `default`) freigeben, möglichst für eine bestimmte Kategorie. Einrichtung oder Änderung eines Host-Ziels mit dem aktuellen Meridian-Passwort bestätigen. Im Job-Editor diese Kategorie, das Host-Ziel und ein Shell-Skript wählen. Zunächst `id -u; id -g` testen: Die Root-Variante liefert `0` und `0`. Zeitpläne laufen danach ohne weitere Passwortabfrage, unter Beachtung aktueller Rechte und Freigaben.

Host-Root kann auf dem NAS alles ändern oder löschen. Kontorechte und Passwortbestätigung begrenzen die Einrichtung, machen aber beliebige freigegebene Root-Skripte nicht ungefährlich. Ohne Host-Bedarf die normale Datei verwenden. Für einen festen Benutzer ohne Root stattdessen in derselben vollständigen Datei numerische `MERIDIAN_HOST_UID` und `MERIDIAN_HOST_GID` einstellen und `MERIDIAN_HOST_ALLOW_ROOT=0` setzen.

Anwendungs- und Wartungscontainer führen keine Host-Jobs aus. Ein Host-Profil allein reicht nicht: Die Bridge muss bereitgestellt sein. Updates berücksichtigen eine vorhandene Bridge und warten auf deren Bereitschaft, bevor der Shell-Worker startet. Manuell die gesamte gewählte Datei mit neuen Images bereitstellen. Bei Fehlern `docker compose -f compose.host-root.yaml logs host-bridge`, Healthcheck, Host-PID-Namensraum, privilegierten Modus und die Socket-Zuordnung `MERIDIAN_SHELL_HOST_SOCKETS` des Workers prüfen. Eine deaktivierte oder unerreichbare Bridge lässt Host-Jobs ausdrücklich fehlschlagen.
