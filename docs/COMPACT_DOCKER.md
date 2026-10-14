# Compact Docker deployment / Kompakter Docker-Betrieb

[English](#english) · [Deutsch](#deutsch) · [README](../README.md)

## English

Use exactly one complete Compose file:

| File | Default containers | Host commands |
| --- | --- | --- |
| `compose.yaml` | 3 | No host bridge |
| `compose.host-root.yaml` | 4 | Explicitly enabled host-root bridge |

The application container combines the web server, scheduler and HTTP workers. The maintenance container combines the updater and restricted Docker socket proxy. Shell jobs keep their own worker without network access. The root variant adds a privileged bridge with the host PID namespace, fixed UID/GID 0 and no network. Password confirmation and category permissions govern job and target configuration; the root variant itself grants host access and must be selected deliberately.

Copy the entire chosen file into the existing NAS Compose editor and redeploy, preserving project name `meridian` and existing volumes. Pull all images, including the maintenance image. A one-time file replacement is necessary: pulling images alone cannot combine old services. On startup, maintenance removes only legacy `scheduler`, `worker-http` and `docker-proxy` containers whose Compose project and service labels match. It never removes data volumes or unrelated job containers. If an identically named container has different labels, migration stops for review.

Both files follow the stable `main` images by default. For beta, set `MERIDIAN_IMAGE=ghcr.io/exploid1/meridian:candidate`, `MERIDIAN_UPDATER_IMAGE=ghcr.io/exploid1/meridian:updater-candidate`, and, for the root variant, `MERIDIAN_HOST_IMAGE=ghcr.io/exploid1/meridian:host-candidate`. Use `MERIDIAN_SANDBOX_IMAGE=ghcr.io/exploid1/meridian-sandbox:candidate` if enabled. Alternatively edit the corresponding image lines in the NAS editor.

On a fresh installation, set `services.web.environment.MERIDIAN_ADMIN_PASSWORD` to your chosen password (at least eight characters). Replace the literal example password directly in the Compose editor; no password variable or .env file is required. Existing accounts and passwords are preserved. Use HTTPS through your reverse proxy for browser access.

The sandbox is optional and adds one container. Enable it with `docker compose --profile sandbox up -d`, or remove its `profiles: [sandbox]` setting in the NAS editor. Use it only when jobs target `meridian-sandbox`; other approved existing containers require no sandbox. An existing sandbox is preserved, so stop it if no longer needed. The updater updates a running sandbox, and the host bridge when present.

The default is two HTTP worker children and one shell worker. Set `MERIDIAN_HTTP_WORKERS` to increase HTTP concurrency when needed. Fewer containers remove duplicate service overhead; workloads still determine actual memory and CPU consumption. Long shell jobs execute sequentially in the supplied stack.

Updates pause background work, back up the database and key, start the new version, check services and resume work. A pause marker survives container recreation; a successful update or rollback clears it. During manual recovery, stop maintenance and restore the database/key together. Clear `/var/lib/meridian/updates/runtime-paused` only after recovery is complete, then restart the application. See [operations](OPERATIONS.md).

## Deutsch

Genau eine vollständige Compose-Datei verwenden:

| Datei | Container im Standard | Host-Befehle |
| --- | --- | --- |
| `compose.yaml` | 3 | Keine Host-Bridge |
| `compose.host-root.yaml` | 4 | Ausdrücklich aktivierte Host-Root-Bridge |

Der Anwendungscontainer vereint Webserver, Scheduler und HTTP-Worker. Der Wartungscontainer vereint Updater und eingeschränkten Docker-Socket-Proxy. Shell-Jobs behalten einen eigenen Worker ohne Netzwerkzugriff. Die Root-Variante ergänzt eine privilegierte Bridge im PID-Namensraum des Hosts, mit UID/GID 0 und ohne Netzwerk. Passwortbestätigung und Kategorie-Rechte schützen die Einrichtung; die Wahl der Root-Datei selbst erteilt den Host-Zugriff.

Die gesamte gewählte Datei im vorhandenen NAS-Compose-Editor ersetzen und neu bereitstellen. Projektname `meridian` und bestehende Volumes beibehalten. Alle Images einschließlich Wartungsimage neu laden. Einmalig ist die Datei zu ersetzen: Images allein können die alten Dienste nicht zusammenlegen. Beim Start entfernt der Wartungsdienst ausschließlich alte `scheduler`-, `worker-http`- und `docker-proxy`-Container mit passenden Projekt- und Dienst-Labels. Daten-Volumes und fremde Job-Container bleiben erhalten. Abweichende Labels bei gleichem Namen stoppen den Umstieg zur Prüfung.

Beide Dateien verwenden standardmäßig stabile Main-Images. Für Beta `MERIDIAN_IMAGE=ghcr.io/exploid1/meridian:candidate`, `MERIDIAN_UPDATER_IMAGE=ghcr.io/exploid1/meridian:updater-candidate` und bei Root `MERIDIAN_HOST_IMAGE=ghcr.io/exploid1/meridian:host-candidate` setzen. Bei aktivierter Sandbox zusätzlich `MERIDIAN_SANDBOX_IMAGE=ghcr.io/exploid1/meridian-sandbox:candidate`. Alternativ die jeweiligen Image-Zeilen im NAS-Editor ändern.

Bei einer Neuinstallation unter `services.web.environment.MERIDIAN_ADMIN_PASSWORD` ein eigenes Passwort mit mindestens acht Zeichen eintragen. Das direkt eingetragene Beispielpasswort im Compose-Editor ersetzen; dafür ist keine Passwortvariable oder .env-Datei nötig. Bestehende Konten und Passwörter bleiben erhalten. Den Browserzugriff über den Reverse Proxy mit HTTPS betreiben.

Die optionale Sandbox ergänzt einen Container: mit `docker compose --profile sandbox up -d` aktivieren oder im NAS-Editor ihre Einstellung `profiles: [sandbox]` entfernen. Sie wird nur für Jobs auf `meridian-sandbox` benötigt. Andere freigegebene Container benötigen sie nicht. Eine vorhandene Sandbox bleibt erhalten und kann bei Nichtgebrauch gestoppt werden. Updates berücksichtigen eine laufende Sandbox und die vorhandene Host-Bridge.

Standard sind zwei HTTP-Worker und ein Shell-Worker. Mehr HTTP-Parallelität lässt sich über `MERIDIAN_HTTP_WORKERS` einstellen. Weniger Container reduzieren doppelte Dienst-Verwaltung; die tatsächlichen CPU- und Speicherwerte hängen weiterhin von den Jobs ab. Lange Shell-Jobs laufen im gelieferten Stack nacheinander.

Updates pausieren Hintergrundarbeit, sichern Datenbank und Schlüssel, prüfen die neue Version und setzen die Arbeit fort. Die Pause übersteht Container-Neuanlage und wird nach erfolgreichem Update oder Rollback gelöscht. Bei manueller Wiederherstellung den Wartungsdienst stoppen und Datenbank sowie Schlüssel gemeinsam zurückspielen. Erst danach `/var/lib/meridian/updates/runtime-paused` entfernen und die Anwendung neu starten. Siehe [Betrieb](BETRIEB.md).
