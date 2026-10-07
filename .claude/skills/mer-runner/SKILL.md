---
name: mer-runner
description: Ausführung von Läufen in Meridian — Shell-Runner mit proc_open und Argument-Array, explizite Umgebung ohne Geheimnisse, Zeitlimit, Abbruch, Prozessgruppe, Docker nur über docker-socket-proxy, Container-Allowlist, HTTP-Runner mit Methode/Header/Body/Zeitlimit, SSRF-Schutz (DNS vor Verbindung, private Netze, Metadaten, IP festhalten, Weiterleitungen neu prüfen), TLS-Prüfung, Antwortgröße, Payload entschlüsseln und im SecretMasker registrieren, Laufausgabe und Live-Log (SSE) maskieren, Kürzen nach dem Maskieren, Benachrichtigungen. Nutze das bei jeder Arbeit an src/Runner, Shell- oder HTTP-Jobs, Docker-Anbindung, Live-Log, Testlauf, Benachrichtigungsversand und bei allem, was proc_open, curl oder externe Aufrufe betrifft.
---

# Meridian-Runner

Normativ für Shell- und HTTP-Läufe. Regeln 3, 4, 5 und 7 in `CLAUDE.md` gelten zusätzlich.

## 1. Shell-Runner — Pflicht-Pattern

```php
$process = proc_open(
    ['/bin/sh', '-s', '--'],            // Argument-Array, nie ein Shell-String
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $workDir,                            // festes, privates Arbeitsverzeichnis
    $env,                                // explizite Allowlist, NIE null (null = Umgebung erben)
);
fwrite($pipes[0], $script);              // Skript über stdin, nicht als Argument (Prozessliste)
fclose($pipes[0]);
```

- Umgebung: nur `PATH`, `LANG`, `TZ`, `HOME` und die vom Job freigegebenen Variablen. Nie
  `MERIDIAN_KEY`, `MERIDIAN_KEY_FILE`, Datenbankpfad oder Sitzungsdaten.
- Kein `exec`, `shell_exec`, `system`, `passthru`, `popen`, Backticks, `pcntl_exec`.
- Jedes Benutzer-Argument ist ein eigenes Array-Element, gegen Allowlist geprüft.
- Zeitlimit: erst `SIGTERM` an die Prozessgruppe, nach Frist `SIGKILL`. Abbruch durch Benutzer genauso.
- Ausgabe-Obergrenze in Bytes; bei Überschreitung abbrechen bzw. abschneiden und vermerken.
- Lokale Shell-Jobs laufen nie mit Zugriff auf den Hauptschlüssel oder die Datenbankdatei
  (eigener Benutzer/Container oder Ausführung im Zielcontainer).

## 2. Docker nur über den Socket-Proxy

- Meridian bindet `/var/run/docker.sock` nie ein. Zugriff nur per HTTP auf den Proxy in einem
  **internen** Netz, das nur der Runner erreicht (nicht die Weboberfläche, nicht der Host-Port).
- Proxy-Rechte minimal. Container anlegen, `privileged`, Host-Mounts, Images ziehen sind nie erlaubt;
  nur Exec in bestehende Container.
- Ziel-Container nur aus einer vom Admin gepflegten Allowlist; Name gegen `^[A-Za-z0-9_.-]+$`.
- Exec-Befehl als Array (`Cmd: ["sh", "-s"]`), Skript über stdin. Proxy-Image auf Version festlegen.
- Shell-Jobs anlegen/ändern erfordert `jobs.edit_shell` (gefährlich, nur uneingeschränkt).

## 3. HTTP-Runner und SSRF

- Schema nur `http`/`https`; `CURLOPT_PROTOCOLS` und `CURLOPT_REDIR_PROTOCOLS` entsprechend begrenzen.
- Host vor dem Verbinden auflösen; jede Adresse prüfen und ablehnen bei: Loopback, RFC 1918, CGNAT
  `100.64/10`, Link-local `169.254/16` und `fe80::/10`, ULA `fc00::/7`, Multicast, reserviert,
  IPv4-gemappte IPv6, Metadaten (`169.254.169.254`, `metadata.google.internal`). Unauflösbar → ablehnen.
- Geprüfte IP festhalten (`CURLOPT_RESOLVE`), damit kein DNS-Rebinding greift.
- Keine automatischen Weiterleitungen (`CURLOPT_FOLLOWLOCATION` aus); jede Weiterleitung manuell neu
  prüfen, Obergrenze für die Anzahl.
- TLS: `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`. Kein Schalter zum Abschalten.
- Zeitlimits für Verbindung und Gesamtdauer, Obergrenze für die Antwortgröße.
- Interne Ziele erlauben ist eine Einstellung mit eigenem Admin-Recht (siehe `mer-security` §4).

## 4. Geheimnisse im Lauf

- Payload (`payload_enc`) wird nur im Runner entschlüsselt, unmittelbar vor der Ausführung.
- Vor der ersten Ausgabe jeden geheimen Bestandteil im `SecretMasker` registrieren: ganze URL,
  jeder Query-Wert, jeder Header-Wert, Body, Benutzer und Passwort aus der URL, Skript-Variablen.
- Exceptions und curl-Fehlermeldungen enthalten die URL → maskieren, nie den Payload in eine
  Exception-Meldung schreiben.

## 5. Ausgabe maskieren

- Reihenfolge immer: **maskieren → kürzen → speichern/senden.** Kürzen vor dem Maskieren kann ein
  halbes Geheimnis freilegen.
- Gestreamte Ausgabe (Live-Log, SSE): Ein Geheimnis kann über eine Blockgrenze reichen. Puffer mit
  Überhang (Länge des längsten bekannten Geheimnisses − 1) zurückhalten, erst den maskierten sicheren
  Teil senden.
- Live-Log-Verbindung prüft beim Öffnen `jobs.view` für die Kategorie des Jobs; Verbindungen begrenzen.
- Benachrichtigungen (E-Mail, ntfy, Webhook) nutzen denselben Masker und enthalten nur Job-Name, Status,
  Zeit, Link — Ausgabe höchstens maskiert und gekürzt. Versand-URLs selbst sind Geheimnisse (`_enc`).

## Verboten

- ❌ `proc_open` mit String-Befehl oder `env = null`
- ❌ Skript oder Geheimnis als Kommandozeilen-Argument
- ❌ Docker-Socket direkt; Proxy mit Container-Anlage oder ohne internes Netz
- ❌ `CURLOPT_FOLLOWLOCATION` ohne Neuprüfung; TLS-Prüfung aus
- ❌ Ausgabe speichern, senden oder kürzen vor dem Maskieren
- ❌ Payload in Exception-Meldung, Log oder Statistik

## Checkliste

| Prüfpunkt | ✓ |
|---|---|
| `proc_open` mit Array, expliziter Umgebung ohne Schlüssel, Skript über stdin? | |
| Zeitlimit + Abbruch mit SIGTERM/SIGKILL an die Prozessgruppe, Ausgabe-Obergrenze? | |
| Docker nur über Proxy im internen Netz, Container aus Allowlist? | |
| SSRF: Schema, DNS-Prüfung aller Adressen, IP festgehalten, Weiterleitungen neu geprüft? | |
| TLS-Prüfung an, Zeitlimits und Größenlimit gesetzt? | |
| Alle Payload-Teile im Masker registriert, Reihenfolge maskieren → kürzen? | |
| Streaming mit Überhang-Puffer? | |
| Leak-Test: Test-Geheimnis in URL, Header, Body, Skript-Ausgabe erscheint nirgends? | |
