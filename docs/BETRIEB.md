<!-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
     SPDX-License-Identifier: LicenseRef-Meridian-Proprietary -->
# Betrieb: Sicherung, Wiederherstellung, Aktualisieren

[English](OPERATIONS.md) · [Deutsch](BETRIEB.md) · [README](../README.md#deutsch)

Optionale Host-Befehle aus Docker: [Einrichtung, Rechte und Bridge-Updates](DOCKER_HOST.md#deutsch).

Gilt für beide Betriebsarten. Befehle laufen mit `docker compose exec web php bin/meridian …` (Docker) bzw.
`sudo -u meridian env MERIDIAN_DATA_DIR=/var/lib/meridian php /opt/meridian/bin/meridian …` (systemd).

## Was wo liegt

| Was | Docker | systemd |
|---|---|---|
| Datenbank (Jobs, Benutzer, Verlauf, verschlüsselte Geheimnisse) | Volume `meridian-data` (`/var/lib/meridian`) | `/var/lib/meridian/meridian.sqlite` |
| **Hauptschlüssel** | Volume `meridian-secrets` (`/etc/meridian/master.key`) | `/etc/meridian/master.key` |
| Sicherungen | `…/backups` im Volume `meridian-data` | `/var/lib/meridian/backups` |

**Ohne Hauptschlüssel sind alle gespeicherten Geheimnisse (URLs mit Parametern, Header, Skripte, Passwörter der
Benachrichtigungskanäle, 2FA) unlesbar.** Der Schlüssel ist bewusst **nicht** in den Sicherungen. Bewahre ihn getrennt
von den Sicherungen auf (Passwortmanager, Tresor) und lege ihn sofort nach der Installation einmal ab. Das gilt für Sicherungen mit `backup:create`; die geschützten Sicherungen des Update-Dienstes enthalten zusätzlich den Hauptschlüssel.

## Sicherung

```bash
php bin/meridian backup:create            # behält die neuesten 14
php bin/meridian backup:create --keep=30
php bin/meridian backup:list
```

- Läuft bei laufendem Betrieb. Gesichert wird nie durch Kopieren der laufenden Datei, sondern mit `VACUUM INTO`; die
  Sicherung wird geprüft (`quick_check`), bevor sie ihren Namen bekommt (`meridian-JJJJMMTT-HHMMSS.sqlite`, Rechte `0600`).
  Vorher wird der freie Platz geprüft; scheitert etwas, gibt es eine Fehlermeldung und keine Sicherung.
- **Automatisch:** Der Planer legt täglich eine Sicherung an und behält die neuesten sieben. Einstellbar mit
  `MERIDIAN_BACKUP_KEEP` (1 bis 365; `0` schaltet es aus). Ein Fehlschlag steht als `backup.failed` im Audit-Log
  (Stunden-Takt bis zum nächsten Versuch).
- **Sicherungen liegen im selben Volume wie die Datenbank.** Das schützt vor Fehlbedienung, nicht vor dem Verlust des
  Datenträgers. Kopiere sie regelmäßig weg:

  ```bash
  # Docker: Sicherungen in ein lokales Verzeichnis kopieren
  mkdir -p meridian-backups
  docker cp meridian-web:/var/lib/meridian/backups/. ./meridian-backups/
  # systemd
  rsync -a /var/lib/meridian/backups/ nas:/pfad/meridian-backups/
  ```

## Wiederherstellung

1. **Meridian anhalten** (`docker compose stop web worker-shell` bzw.
   `systemctl stop meridian-web meridian-scheduler meridian-worker-http meridian-worker-shell`). Den Update-Dienst während manueller Wiederherstellung ebenfalls stoppen (`docker compose stop updater` bzw. `systemctl stop meridian-updater`). Bei systemd nur installierte Dienste stoppen. Der Befehl lehnt ab, solange der Planer läuft.
2. Den **richtigen Hauptschlüssel** bereitstellen (`MERIDIAN_KEY_FILE`). Eine Sicherung gehört zu dem Schlüssel, mit dem
   sie erstellt wurde; Meridian prüft das **vor dem ersten Schreiben** und ändert bei Abweichung nichts.
3. Bei Docker einen einmaligen Container mit denselben Volumes starten:

   ```bash
   docker compose run --rm --no-deps web php bin/meridian backup:list
   docker compose run --rm --no-deps web php bin/meridian backup:restore meridian-20261011-020000.sqlite --yes
   ```

   Bei systemd derselbe Befehl als Benutzer `meridian`. Es zählt nur ein **Dateiname** aus dem Sicherungsverzeichnis, nie ein Pfad.
4. Der bisherige Stand bleibt als `meridian-…-vor-wiederherstellung.sqlite` im Sicherungsverzeichnis (wird nie automatisch
   gelöscht). Danach `php bin/meridian migrate` (nötig, wenn die Sicherung von einer älteren Version stammt) und Meridian
   wieder starten. Die Wiederherstellung steht im Audit-Log (`backup.restored`).

Eine Sicherung auf einen **neuen Rechner** zurückspielen: Meridian installieren, **zuerst die alte Schlüsseldatei an den
Schlüsselort legen**, Sicherung ins Verzeichnis `backups` kopieren, dann wie oben wiederherstellen. Ohne die Schlüsseldatei
bricht der Befehl ab, sobald die Sicherung verschlüsselte Werte enthält.

## Aktualisieren

Vorher immer: `backup:create`, und prüfen, dass die Schlüsseldatei getrennt gesichert ist.

**Docker**

```bash
docker compose pull && docker compose up -d
```

Die Volumes bleiben bestehen, Migrationen laufen beim Start automatisch. Auf dem NAS ohne `git`: die aktuelle `compose.yaml`
komplett neu einfügen. Danach die Anmeldung und einen Testlauf prüfen.

**systemd** (Quelltext der neuen Version holen, dann als root):

```bash
systemctl stop meridian-web meridian-scheduler meridian-worker-http
# Bei aktivierten Shell-Jobs zusätzlich:
systemctl stop meridian-worker-shell
sudo -u meridian env MERIDIAN_DATA_DIR=/var/lib/meridian php /opt/meridian/bin/meridian backup:create
cd frontend && npm ci && npm run build && cd ..
sudo bash deploy/install.sh
```

### Privates Repository (GitHub oder GitLab)

Der Update-Dienst fragt den Stand und die Images ohne Anmeldung ab. Ist das Repository privat, braucht er einen Lesezugang.

**In der Oberfläche:** Einstellungen → Updates → „Update-Quelle“ (nur Benutzer mit dem Recht „Einstellungen“, standardmäßig Admin).
Dort stehen Quelle (GitHub oder GitLab), Repository, bei selbst gehostetem GitLab die Adresse, die Image-Registry, der Registry-Benutzername und
das Zugriffstoken. Gespeichert wird nur nach Bestätigung mit deinem Passwort und mit Eintrag im Audit-Log. Das Token ist nur schreibend:
Es wird an den Update-Dienst übergeben, dort im privaten Volume abgelegt und nie wieder angezeigt (nur „ein Token ist gespeichert“).
Mit dem Benutzernamen meldet sich der Dienst bei der Registry an (dasselbe Token als Passwort), deshalb braucht das Token Lesezugriff auf
Repository **und** Pakete (GitHub: klassisches Token mit `repo` und `read:packages`; GitLab: `read_api` und `read_registry`). Sind nur das Repository
privat und die Images öffentlich, genügt ein Token nur für das Repository und kein Benutzername. Die gespeicherte Quelle hat Vorrang vor den
Variablen unten. Das Formular gibt es erst, wenn der Update-Dienst die aktuelle Version läuft (er meldet dann seine Quelle).

**Ohne Oberfläche** (Umgebung des Update-Containers, Standardwerte):

| Variable | Bedeutung |
|---|---|
| `MERIDIAN_UPDATE_SOURCE` | `github` (Standard) oder `gitlab` |
| `MERIDIAN_UPDATE_REPO` | `besitzer/name`, bei GitLab auch mit Untergruppen `gruppe/untergruppe/name` |
| `MERIDIAN_UPDATE_GITLAB_URL` | nur selbst gehostetes GitLab, `https://…`, ohne Pfad (Standard `https://gitlab.com`) |
| `MERIDIAN_UPDATE_REGISTRY` | Präfix der Images, z. B. `ghcr.io/besitzer` oder `registry.gitlab.com/gruppe/projekt`; bei selbst gehostetem GitLab Pflicht |

Die Images heißen `<Präfix>/meridian:sha-<Stand>` und `<Präfix>/meridian-sandbox:sha-<Stand>`; deine Pipeline muss sie dort veröffentlichen.

**Zugang einmalig im Update-Container einrichten** (Terminal des Containers `meridian-updater`). Beides liegt im privaten
Volume des Dienstes und nie in der Umgebung oder im Image:

```sh
# 1. Token zum Lesen des Repositorys (nur eine Zeile)
umask 077
printf '%s\n' 'HIER_TOKEN' > /var/lib/meridian-updater/repo-token
# 2. Anmeldung an der Image-Registry (Token mit Leserecht auf Pakete)
mkdir -p /var/lib/meridian-updater/docker
DOCKER_CONFIG=/var/lib/meridian-updater/docker docker login ghcr.io -u BENUTZER
```

- **GitHub:** für `repo-token` ein fein abgestuftes Token nur auf dieses Repository mit „Contents: Read-only“. Für die Registry ein
  klassisches Token mit `read:packages` (GitHub kennt für `ghcr.io` keine fein abgestuften Tokens).
- **GitLab:** für `repo-token` ein Projekt-Zugriffstoken (Rolle Guest/Reporter, Bereich `read_api`). Für die Registry ein
  Deploy-Token mit `read_registry`; Anmeldung an `registry.gitlab.com` (oder deiner Registry).
- Der Docker-Host selbst muss bei derselben Registry angemeldet sein, damit `docker compose pull` und der Start des
  Update-Containers die privaten Images laden können.
- Meldet die Oberfläche „Das Repository verweigert den Zugriff“, fehlt `repo-token` oder er ist abgelaufen oder zu eng. Die
  Protokollzeile `operation failed during checking HTTPError 404` im Container nennt den Statuscode.
- Das Token wird nur an die eine Adresse der Prüfung gesendet, folgt keiner Weiterleitung und steht nie in Status, Protokoll oder Antwort.

### GitLab als Quelle einrichten

1. **Quellcode nach GitLab bringen.** Deploy-Gate veröffentlicht nach GitHub. Für GitLab spiegelst du das Repository, entweder in
   GitLab als Pull-Spiegel von GitHub (in GitLab Premium oder bei selbst gehostetem GitLab verfügbar) oder du pushst zusätzlich dorthin.
   Der Update-Dienst vergleicht die Commit-Kennung; sie bleibt gleich, solange der Spiegel dieselben Commits übernimmt.
2. **Images bauen.** `.gitlab-ci.yml` im Repository baut bei jedem Push auf `main` und `beta` dieselben vier Images wie die
   GitHub-Pipeline in die Registry des Projekts (`$CI_REGISTRY_IMAGE/meridian`, `…/meridian-sandbox`, jeweils mit `sha-<Commit>`;
   `updater-` und `host-` als Tag-Präfix an `meridian`). Die Container Registry muss im Projekt aktiv sein, und die Pipeline braucht
   einen Runner mit Docker-in-Docker (privilegiert).
3. **Compose auf die GitLab-Images stellen** mit einer einzigen Variable (Datei `.env` neben der `compose.yaml`):
   ```
   MERIDIAN_REGISTRY=registry.gitlab.com/gruppe/projekt
   ```
   Sie gilt für alle Images (`meridian`, `meridian-sandbox`, Updater, Host). Die einzelnen Variablen `MERIDIAN_IMAGE`,
   `MERIDIAN_SANDBOX_IMAGE`, `MERIDIAN_UPDATER_IMAGE` und `MERIDIAN_HOST_IMAGE` haben weiterhin Vorrang, z. B. für `candidate`.
4. **Update-Quelle** in der Oberfläche auf GitLab stellen: Repository `gruppe/projekt`, bei selbst gehostetem GitLab die Adresse und die
   Registry (dieselbe wie `MERIDIAN_REGISTRY`). Trage sie auch in `MERIDIAN_REGISTRY` ein; sonst startet ein erneutes Bereitstellen der
   Compose-Datei wieder mit den alten Images.
5. **Zugang bei privatem Projekt:** Die Registry eines privaten GitLab-Projekts ist immer privat (anders als bei GitHub lässt sich ein
   Paket nicht einzeln öffentlich stellen). Lege ein Projekt-Zugriffstoken (`read_api`) und ein Deploy-Token (`read_registry`) an und melde
   den Docker-Host einmal an (`docker login registry.gitlab.com`). Ist das Projekt öffentlich, braucht nichts davon ein Token.

**Gilt auch für GitHub:** Auf `beta` veröffentlicht die Pipeline nur `candidate` und `sha-<Commit>`; die Tags `latest` und
`updater-latest` gibt es nur auf `main`. Willst du auf dem Beta-Kanal den neuen Update-Dienst vor einem Main-Stand einsetzen, nimm
`MERIDIAN_UPDATER_IMAGE=ghcr.io/exploid1/meridian:updater-candidate`.

**GitHub-Images ohne Anmeldung:** Pakete auf `ghcr.io` haben eine eigene Sichtbarkeit, unabhängig vom Repository. Sind sie öffentlich, laden
Host und Update-Dienst sie ohne Zugangsdaten, auch wenn das Repository privat ist. Nur die Abfrage der aktuellen Version braucht dann noch das Token.

**Zurück zur alten Version:** Migrationen laufen nur vorwärts. Wer zurück will, spielt die Sicherung von vor dem Update ein
(Abschnitt Wiederherstellung) und startet die alte Version.

## Shell-Jobs auf dem Host (systemd)

Für einen isolierten Entwicklungscontainer kann der Agent mit `MERIDIAN_ENV=dev php bin/meridian-shell-agent --development-container` gestartet werden. Nur diese Kombination hebt die Container-Prüfung auf. Der Agent muss weiterhin als eigener Benutzer ohne root-Rechte und ohne Zugriff auf Meridians Schlüssel oder Datenbank laufen. Die normalen systemd-Units und die Release-Compose-Datei verwenden diesen Schalter nicht. Agent-Tests im Container ersetzen keine Abnahme der systemd-Socket-Units.

Der Installer richtet Web, Planer und `meridian-worker-http` ein (ohne Worker läuft kein Job). Shell-Jobs **auf dem Host** sind aus. Einschalten:

```bash
sudo env MERIDIAN_ENABLE_SHELL=1 bash deploy/install.sh
```

Das legt den Benutzer `meridian-run` an (ohne Zugriff auf Schlüssel und Datenbank), aktiviert `meridian-shell.socket` und `meridian-worker-shell` und trägt `MERIDIAN_SHELL_HOST_SOCKETS` ein. Danach in Meridian unter Einstellungen → Ausführungsorte `host default` freigeben. Jeder Lauf bekommt einen eigenen Prozess als `meridian-run` mit fester Umgebung; Installation und Freigaben: [README](../README.md#betrieb-auf-linux-mit-systemd).

## Shell-Jobs auf einem Server per SSH

Ein Ausführungsort der Art **SSH-Server** führt das Skript auf einem anderen Rechner aus: Meridian startet lokal nur den `ssh`-Client und schickt das Skript samt Umgebungsvariablen über die Verbindung an `sh -s` bzw. `bash -s`.

**Einrichten**

1. Auf dem Zielserver einen eigenen Benutzer mit möglichst wenigen Rechten anlegen (nicht root).
2. Den **Server-Schlüssel** (Host-Key des Zielservers) holen. Das ist der Schlüssel des Servers, **nicht** der Anmelde-Schlüssel von Meridian; die beiden sind verschieden und werden nie ineinander eingetragen. Am einfachsten im Formular **„Server-Schlüssel abrufen“**: Meridian holt ihn von der Adresse (der Abruf kommt vom Meridian-Rechner aus, wie später die Verbindung) und zeigt den Fingerabdruck. Den Fingerabdruck vergleichst du auf dem Server mit `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`, erst bei Übereinstimmung speichern (ein Abruf über das Netz ist nur so sicher wie das Netz). Von Hand geht es mit `cat /etc/ssh/ssh_host_ed25519_key.pub` auf dem Server. Eine Zeile mit Kommentar am Ende (`root@server`) ist erlaubt; gespeichert wird nur Typ und Schlüssel.
3. In Meridian unter Einstellungen → Ausführungsorte „SSH-Server“ anlegen: Server, Port, Benutzer, Server-Schlüssel. Beim **Anmelde-Schlüssel von Meridian** ist **„Schlüsselpaar von Meridian erzeugen lassen“** voreingestellt: Meridian erzeugt ein ed25519-Paar ohne Passphrase, speichert den privaten Teil verschlüsselt und zeigt ihn **nie** an. Nach dem Speichern öffnet sich ein eigenes Fenster „Schlüssel auf dem Server eintragen“ mit dem öffentlichen Teil und einem fertigen Befehl, den du auf dem Server als der Zielbenutzer ausführst (er trägt den Schlüssel in `~/.ssh/authorized_keys` ein). Wer einen eigenen Schlüssel mitbringt, wählt „Eigenen privaten Schlüssel einfügen“ (OpenSSH oder PEM, ohne Passphrase). Beim Bearbeiten lässt „Gespeicherten Schlüssel behalten“ den Schlüssel stehen; der öffentliche Teil bleibt dort abrufbar. „Neues Schlüsselpaar erzeugen“ ersetzt den alten, dann muss der neue öffentliche Teil auf den Server, sonst schlägt die Anmeldung fehl.
4. SSH ist ab Werk aus, weil der Shell-Worker ohne Netz läuft:
   - **Docker:** in `.env` `MERIDIAN_SSH_ENABLE=1` und `MERIDIAN_SHELL_NETWORK=bridge` setzen, dann `docker compose up -d`.
   - **systemd (ohne Docker):** Dort gibt es keine Compose-Variablen. Drei Schritte, jeweils auf dem Meridian-Rechner:
     1. Den SSH-Client installieren, falls er fehlt (`apt install openssh-client`); `setsid` gehört zu `util-linux` und ist meist vorhanden. Ohne `ssh` bricht der Lauf mit „SSH-Ausführung nicht eingerichtet: Auf diesem Rechner fehlt der SSH-Client …“ ab.
     2. In `/etc/meridian/meridian.env` die Zeile `MERIDIAN_SSH_ENABLE=1` ergänzen.
     3. Die Netzsperre der Unit aufheben (`sudo systemctl edit meridian-worker-shell`), denn die Unit erlaubt ab Werk nur lokale Sockets:
        ```
        [Service]
        IPAddressDeny=
        RestrictAddressFamilies=
        RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
        ```
        Danach `sudo systemctl restart meridian-worker-shell`. Die Zeile `IPAddressDeny=` muss leer stehen, sonst bleibt die Sperre bestehen.
   - Ohne Schritt 2 und 3 bleibt der Ort angelegt, aber jeder Lauf schlägt mit einer festen Meldung fehl; nichts verbindet sich heimlich.

**Was Meridian absichert**

- Der Server-Schlüssel ist festgehalten (`StrictHostKeyChecking=yes`, eigene `known_hosts`, keine Benutzerkonfiguration). Ändert sich der Server-Schlüssel, schlägt der Lauf fehl, bis ein Administrator den neuen einträgt.
- Nur Schlüssel-Anmeldung, kein Passwort, kein Agent, keine Weiterleitungen, kein Terminal.
- Der private Schlüssel liegt nur für die Dauer des Laufs in einem privaten Verzeichnis (0700, Datei 0600) im temporären Verzeichnis des Workers (Docker: tmpfs) und wird danach gelöscht. Er steht nie in Umgebung, Argumenten, Logs oder Antworten.
- Benutzer und Arbeitsverzeichnis sind beim SSH-Ort fest; ein Job kann sie nicht überschreiben.

**Grenzen**

- Wird ein Lauf abgebrochen oder überschreitet er das Zeitlimit, trennt Meridian die Verbindung. Ein schon gestarteter Befehl kann auf dem Server weiterlaufen; der Lauf weist darauf hin.
- Exit-Code 255 bedeutet meist, dass die Verbindung selbst scheiterte (Erreichbarkeit, Server-Schlüssel, Benutzer, Schlüssel).
- Der Shell-Worker darf mit `MERIDIAN_SHELL_NETWORK=bridge` ausgehend jedes Ziel erreichen. Lege SSH-Orte deshalb nur für Server an, für die das gewollt ist, und begrenze sie über Kategorien.

## Prüfliste nach dem Einrichten

- [ ] Schlüsseldatei getrennt abgelegt (nicht neben den Sicherungen)
- [ ] `backup:create` einmal von Hand ausgeführt, `backup:list` zeigt sie
- [ ] Sicherungen werden regelmäßig vom Server weg kopiert
- [ ] Wiederherstellung einmal auf einer Testinstanz geübt

## Aktive Überwachung (ADR 0018)

Meridian prüft Web-Adressen, TCP-Ports und Hosts (Ping) selbst. Die Prüfung läuft im Dienst `worker-http` (er braucht Netz); ohne ihn passiert nichts.

- **Ping** braucht das Programm `ping` (im Image: `iputils`) und das Recht, ICMP zu senden. Im mitgelieferten `compose.yaml` setzt `worker-http` dafür `net.ipv4.ping_group_range` (keine Capability nötig). Bei systemd-Installationen: `ping` installieren und `sysctl net.ipv4.ping_group_range="0 2147483647"` setzen. Fehlt das, steht in der Überwachung „Ping ist in dieser Installation nicht verfügbar“.
- **Ziele im eigenen Netz** (192.168.x.x, Docker-Namen) sind wie bei HTTP-Jobs gesperrt, bis unter Einstellungen → Interne Ziele eine Freigabe (am besten je Kategorie und Port) besteht.
- Die Meldung nach `n` Fehlversuchen nutzt dieselben Kanäle wie Jobs (Einstellungen → Benachrichtigungen).


[Kompakter Docker-Betrieb und Umstieg](COMPACT_DOCKER.md#deutsch). [Automationen](AUTOMATIONS.md#deutsch).
