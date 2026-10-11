# Betrieb: Sicherung, Wiederherstellung, Aktualisieren

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
von den Sicherungen auf (Passwortmanager, Tresor) und lege ihn sofort nach der Installation einmal ab.

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
  docker run --rm -v meridian-data:/d:ro -v "$PWD/meridian-backups":/out alpine sh -c 'cp /d/backups/*.sqlite /out/'
  # systemd
  rsync -a /var/lib/meridian/backups/ nas:/pfad/meridian-backups/
  ```

## Wiederherstellung

1. **Meridian anhalten** (`docker compose stop web scheduler worker-http worker-shell` bzw.
   `systemctl stop meridian-web meridian-scheduler`). Der Befehl lehnt ab, solange der Planer läuft.
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
systemctl stop meridian-web meridian-scheduler
sudo -u meridian env MERIDIAN_DATA_DIR=/var/lib/meridian php /opt/meridian/bin/meridian backup:create
./deploy/install.sh        # ersetzt den Programmcode, behält Schlüssel und Daten, spielt Migrationen ein, startet die Dienste
```

**Zurück zur alten Version:** Migrationen laufen nur vorwärts. Wer zurück will, spielt die Sicherung von vor dem Update ein
(Abschnitt Wiederherstellung) und startet die alte Version.

## Shell-Jobs auf dem Host (systemd)

Der Installer richtet Web, Planer und `meridian-worker-http` ein (ohne Worker läuft kein Job). Shell-Jobs **auf dem Host** sind aus. Einschalten:

```bash
MERIDIAN_ENABLE_SHELL=1 sudo ./deploy/install.sh
```

Das legt den Benutzer `meridian-run` an (ohne Zugriff auf Schlüssel und Datenbank), aktiviert `meridian-shell.socket` und `meridian-worker-shell` und trägt `MERIDIAN_SHELL_HOST_SOCKETS` ein. Danach in Meridian unter Einstellungen → Ausführungsorte `host default` freigeben. Jeder Lauf bekommt einen eigenen Prozess als `meridian-run` mit fester Umgebung; Details und Grenzen: `docs/decisions/0014-host-agent.md`. **Noch nicht auf einem echten systemd abgenommen: erst auf einem Testserver prüfen.**

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
