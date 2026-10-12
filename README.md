<!-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
     SPDX-License-Identifier: LicenseRef-Meridian-Proprietary -->
# Meridian

Selbst gehosteter Job-Scheduler für HTTP- und Shell-Jobs, mit Benutzern, Rollen und Rechten pro Kategorie.

## Lizenz

Copyright (c) 2026 Alexander von Pidoll. Alle Rechte vorbehalten.

Meridian ist proprietäre Software. Die [Meridian Proprietary License](LICENSE) erlaubt den unveränderten Betrieb auf eigenen Systemen für persönliche und interne betriebliche Zwecke. Weiterverbreitung, Weiterverkauf, öffentliche abgeleitete Versionen und das Anbieten von Meridian als Dienst für Dritte benötigen eine vorherige schriftliche Erlaubnis. Die vorgesehenen öffentlichen Statusseiten dürfen veröffentlicht werden.

Bei einem öffentlichen GitHub-Repository bleiben die von GitHubs Bedingungen erlaubten Forks innerhalb GitHubs möglich; die Lizenz hebt diese Plattformrechte nicht auf. Fremde Komponenten behalten ihre eigenen Lizenzen, siehe [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

E-Mail-Empfänger lassen sich im SMTP-Kanal, pro Kategorie und pro Job einstellen. Eigene Job-Empfänger ersetzen die Kategorie-Vorgabe; ohne Kategorie-Empfänger gelten die Empfänger des Kanals. Leer bedeutet erben. Die Auswahl gilt für alle gewählten E-Mail-Kanäle. Überwachungen bieten ebenfalls eine Kanalauswahl und übernehmen die Empfänger ihrer Kategorie.

Bei Statusseiten lässt sich die Standardsprache beim Anlegen und Bearbeiten auf Deutsch oder Englisch setzen. Neue Seiten übernehmen zunächst deine UI-Sprache. Besucher können auf der öffentlichen Seite über DE/EN wechseln; dieser Wechsel verändert die gespeicherte Standardsprache nicht. Eigene Titel und Anzeigenamen werden nicht übersetzt.

## Benachrichtigungskanäle

Unter **Einstellungen → Benachrichtigungen** einen Kanal anlegen und anschließend bei den gewünschten Jobs oder Überwachungen auswählen. Die Sprache des Kanals folgt standardmäßig deiner gespeicherten UI-Sprache; Deutsch und Englisch lassen sich auch fest einstellen.

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

Direktes Browser-Push benötigt HTTPS. Ein Admin legt den Kanal an; danach verbindet jeder Nutzer seinen Browser unter **Mein Konto → Meine Browser-Benachrichtigungen** und bestätigt die Browser-Abfrage. Nach dem Anlegen bleibt das Kanalformular direkt beim Verbindungsschritt. Jeder Browser bzw. jedes Gerät benötigt eine eigene Freigabe. Nutzer erhalten nur Meldungen zu Jobs und Überwachungen, die sie aktuell sehen dürfen und für die dieser Browser-Kanal aktiviert ist. Entzogene Rechte werden auch vor dem Versand erneut geprüft. Die persönlichen Testnachrichten gehen nur an Geräte des eigenen Kontos.

Bei abgelehnter Freigabe die Website-Einstellungen über das Symbol neben der Browser-Adresse öffnen, Benachrichtigungen erlauben und erneut **Diesen Browser verbinden** wählen. Eine zuvor blockierte Berechtigungsabfrage darf Meridian nicht automatisch erneut anzeigen. Über **Entfernen** lässt sich ein Gerät später abmelden. Nur eigene Geräte sind sichtbar; bis zu zehn Geräte pro Konto und Kanal sind möglich. Auf Desktop muss Chrome bzw. Firefox weiter laufen, der Meridian-Tab darf geschlossen sein. Betriebssystem-Einstellungen können Benachrichtigungen zusätzlich blockieren. Abgelaufene Abonnements entfernt Meridian beim Versand. Geräte und Schlüssel bleiben bei Updates erhalten.

Zugangsdaten werden verschlüsselt gespeichert und beim Bearbeiten nicht wieder angezeigt. Bei Discord und Slack ist die Webhook-Adresse selbst ein Zugangsschlüssel. Nach der Einrichtung am Kanal **Testnachricht** auslösen und die letzte Zustellung prüfen. E-Mail-Empfänger pro Kategorie bzw. Job betreffen nur SMTP; die anderen Kanäle verwenden ihre jeweils konfigurierten Ziele oder verbundenen Geräte.

## Betrieb mit Docker

Im Job unter „Benachrichtigungen“ lässt sich eine Laufzeitwarnung aktivieren. Meridian vergleicht abgeschlossene erfolgreiche Läufe mit dem Median der letzten 20 erfolgreichen Läufe, sobald mindestens fünf Vergleichswerte vorliegen. 200 Prozent bedeutet doppelte Dauer; einstellbar sind 110 bis 1000 Prozent. Testläufe zählen nicht, und jede Warnung wird einmal pro Lauf eingereiht.

### Installation als Stack oder Docker-Projekt

Für die Installation genügt [compose.yaml](compose.yaml). In einer Docker-Verwaltung mit Compose-Editor, zum Beispiel der UGOS-Docker-App:

1. Die Datei `compose.yaml` öffnen und ihren **gesamten Inhalt** kopieren.
2. Einen neuen Stack bzw. ein neues Docker-Projekt mit dem Namen `meridian` erstellen.
3. Den kopierten Inhalt in den Compose-Editor einfügen. Falls die Verwaltung eine Datei verlangt, den Inhalt als `compose.yaml` speichern und diese auswählen.
4. Im eingefügten Compose-Inhalt unter `web` die Beispiel-Zugangsdaten durch den gewünschten Benutzernamen und ein eigenes Passwort mit mindestens acht Zeichen ersetzen (siehe unten). Den Proxy-Eintrag bei Bedarf anpassen.
5. Den Stack bereitstellen bzw. das Projekt starten. Die Images werden heruntergeladen und die benötigten Volumes automatisch angelegt.
6. `http://<IP-des-Docker-Hosts>:8090` öffnen und mit den eingetragenen Zugangsdaten anmelden.

`compose.build.yaml` ist nur die Alternative zum Selbstbauen; für die normale Stack-Installation wird `compose.yaml` verwendet.

### Installation über die Kommandozeile

Die Datei `compose.yaml` auf dem Docker-Host in einem eigenen Ordner speichern, die Beispiel-Zugangsdaten wie unten beschrieben ersetzen und dort ausführen:

```bash
docker compose up -d
```

Das Image `ghcr.io/exploid1/meridian:latest` folgt dem Branch `main`. Docker-Verwaltungen können neue Images erkennen. Beim ersten Start legt der Container selbst an: den Hauptschlüssel
(Volume `meridian-secrets`, wird nie überschrieben), die Datenbank (Volume `meridian-data`) und den ersten Admin.
Die Compose-Dateien enthalten diese direkt eingetragenen Beispielwerte unter `services.web.environment`:

```yaml
MERIDIAN_ADMIN_USER: "admin"
MERIDIAN_ADMIN_PASSWORD: "BEISPIEL-passwort-bitte-aendern"
MERIDIAN_TRUSTED_PROXIES: "172.19.0.0/16"
```

Vor dem ersten Start den Benutzernamen nach Wunsch und das Beispielpasswort unbedingt durch ein eigenes Passwort mit mindestens acht Zeichen ersetzen. Die Anführungszeichen beibehalten. Dafür ist keine `.env`-Datei und keine zusätzliche Stack-Variable nötig. Erreichbar ist Meridian unter `http://<host>:8090` (Port über `MERIDIAN_PORT`).

`MERIDIAN_TRUSTED_PROXIES` legt fest, von welchen Reverse-Proxys Meridian die weitergeleitete Client-IP übernimmt. Standardmäßig ist das Netz `172.19.0.0/16` eingetragen; dadurch wird dieses Netz nicht automatisch für Docker eingerichtet. Liegt dein Proxy in einem anderen Netz, den Wert durch seine IP oder sein tatsächliches Netz ersetzen. Mehrere Einträge mit Komma trennen. Nur vertrauenswürdige Proxy-Adressen eintragen, möglichst eine einzelne IP. Ohne Reverse-Proxy den Wert auf `""` setzen.

### Aktualisieren

#### Updates über die Admin-Oberfläche

Unter **Einstellungen → Updates** können Administratoren nach Updates suchen und eine Aktualisierung bestätigen. Derselbe Ablauf unterstützt Docker Compose und systemd. Der getrennte Update-Dienst prüft `main` automatisch alle sechs Stunden; die Installation beginnt ausschließlich nach einer Admin-Bestätigung. Bei Docker wird erst ein Update angeboten, wenn die Images für den konkreten Commit veröffentlicht sind. Der Dienst lädt sie vor der Unterbrechung herunter, beendet die Worker, sichert Datenbank und Hauptschlüssel, startet die neue Version und prüft ihre Dienste. Bei einem Installations- oder Startfehler versucht er, die vorherige Version mit ihrer Datenbank wiederherzustellen. Bei Stromausfall oder Abbruch des Update-Dienstes kann eine manuelle Wiederherstellung nötig sein.

**Docker/UGOS/Portainer:** Der Update-Dienst startet mit dem Stack automatisch. Beide Compose-Dateien enthalten ihn ohne optionales Profil. Projektname und Compose-Dateien werden aus den Container-Metadaten ermittelt; Portainer-Pfade werden bei Bedarf auf dessen Host-Daten-Volume abgebildet. Bei fehlenden oder ungültigen Metadaten wird das Update nicht begonnen.

Bestehende Installationen: die aktuelle Compose-Datei übernehmen und den Stack einmal neu bereitstellen. Ein Image-Pull allein fügt den neuen Dienst nicht hinzu. Mit der CLI im Stack-Ordner:

```bash
docker compose up -d
```

Das Image liegt unter `ghcr.io/exploid1/meridian:updater-latest`. Private Images benötigen eine Docker-Zugangskonfiguration für GHCR. Nur der Updater erhält den direkten Docker-Socket und damit Rechte zur Container-Verwaltung. Die vorhandenen Volumes beibehalten. Ein manuell eingetragener Stack-Pfad ist für die mitgelieferte Compose-Datei nicht erforderlich.

**systemd:** `deploy/install.sh` richtet `meridian-updater.service` automatisch ein und aktiviert ihn. Python 3, Git, Node.js 22+ mit npm sowie die bisherigen Voraussetzungen müssen installiert sein. Bei bestehenden Installationen den aktuellen Installer mit den bisherigen Installationsparametern erneut ausführen. Der zusätzliche manuelle Aufruf von `enable-updater.sh` entfällt.

Der root-Dienst `meridian-updater.service` verwendet ausschließlich das feste Meridian-Repository und den Branch `main`. Er baut die neue Oberfläche vor dem Stoppen der Dienste und installiert über den mitgelieferten Installer. Einstellungen, Hauptschlüssel und aktivierte Shell-Jobs bleiben erhalten. Die Oberfläche führt selbst keine Shell-Befehle aus und kann keine eigenen Update-Adressen oder Commits übergeben.

Sicherungen dieses Dienstes liegen bei systemd geschützt unter `/var/lib/meridian-updater/backup-*`, bei Docker im Volume `meridian-updater-data`. Sie enthalten den Hauptschlüssel und müssen vertraulich behandelt werden. Sie werden nicht automatisch gelöscht; ausreichend Speicher vorhalten und ältere Sicherungen erst nach Prüfung entfernen. Die bestehenden Volumes nicht löschen. Nach abgebrochener Installation oder fehlgeschlagener Wiederherstellung bleibt die API im Wartungsmodus. Erst nach manueller Wiederherstellung den Updater stoppen, `/var/lib/meridian/updates/status.json` und eine eventuell wartende `request.json` entfernen und den Updater erneut starten. Die normalen Sicherungs- und Wiederherstellungsschritte stehen in `docs/BETRIEB.md`.

Der Update-Dienst selbst wird durch erneutes Bereitstellen des Docker-Stacks bzw. erneutes Ausführen von `enable-updater.sh` aktualisiert. Strukturänderungen an der Compose-Datei oder neue Systemvoraussetzungen können weiterhin einen manuellen Installationsschritt erfordern.

Die bisherigen manuellen Update-Befehle bleiben verfügbar:

In der Docker-Verwaltung den bestehenden Stack erneut bereitstellen und dabei das erneute Abrufen der Images aktivieren. Die vorhandenen Volumes beibehalten. Über die Kommandozeile:

```bash
docker compose pull && docker compose up -d
```

Zum Selbstbauen aus GitHub dient `compose.build.yaml`: `docker compose -f compose.build.yaml up -d --build`. `MERIDIAN_REPO_REF` wählt dabei einen Branch, Tag oder Commit (Standard `main`).

Benutzer verwalten, Passwort zurücksetzen, Sperren aufheben: siehe [Befehle](#befehle).
`MERIDIAN_ADMIN_USER` und `MERIDIAN_ADMIN_PASSWORD` gelten nur beim allerersten Start, solange es noch keinen Benutzer gibt.
Bei einer bestehenden Installation ändert das Bearbeiten der Compose-Zugangsdaten das Konto nicht. Das Passwort über „Mein Konto“ oder `user:password` ändern. Nach der ersten Einrichtung können die beiden Admin-Einträge aus der Compose-Datei entfernt werden; das Konto bleibt gespeichert.

Das Volume `meridian-secrets` sicher aufbewahren (Sicherung mitnehmen): ohne den Schlüssel sind gespeicherte
Geheimnisse verloren.

### Shell-Jobs im Docker-Betrieb

Shell-Jobs laufen nie im Meridian-Container, sondern per `docker exec` in einem **freigegebenen Container**.
Die `compose.yaml` bringt dafür mit:

- `worker-http` und `worker-shell`: getrennte Worker (`worker:run --type=http|shell`); der `scheduler` plant nur
  (kein Hauptschlüssel, kein Netz). `worker-shell` hat kein Netz und erreicht nur den Docker-Proxy.
- `docker-proxy` (`wollomatic/socket-proxy`): der **einzige** Dienst mit `/var/run/docker.sock` (nur lesend eingebunden).
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

## Oberfläche

Die Weboberfläche (React + Vite) liegt unter `frontend/` und wird vom Server unter
`/app/` ausgeliefert, mit allen Sicherheits-Headern. Das Docker-Image baut sie selbst.

## Betrieb auf Linux mit systemd

Voraussetzungen: ein Linux-Host mit systemd, PHP 8.3 oder neuer für die CLI (mit `pdo_sqlite`, `sodium`, `curl`, `mbstring`, `openssl`, `pcntl` und `posix`; für schnelle Browser-Push-Verschlüsselung außerdem `gmp`), Composer, rsync und FrankenPHP unter `/usr/local/bin/frankenphp`. Zum Bauen der Oberfläche wird Node.js 22 oder neuer mit npm benötigt. Der Host-Agent verwendet `setsid` aus `util-linux`; für Ping-Prüfungen wird `iputils-ping` benötigt.

Unter Debian/Ubuntu die Pakete installieren (die PHP-Version der Distribution muss mindestens 8.3 sein):

```bash
sudo apt update
sudo apt install php-cli php-curl php-mbstring php-gmp php-sqlite3 php-xml \
  composer git curl ca-certificates rsync sudo util-linux iputils-ping nodejs npm
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
