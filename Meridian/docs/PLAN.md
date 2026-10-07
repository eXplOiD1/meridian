# Meridian – Plan und Aufwand

Stand 06.10.2026. Ausführliche Fassung mit Architekturbild: Dokument „Meridian – Plan und Aufwand“ in Alex' claude.ai-Konto.

## Kurzfazit

- Version 1.0: **62 bis 85 Personentage**, MVP: **rund 33 Personentage**.
- Der Aufwand steckt in den Rändern: Rechte pro Benutzer und Kategorie, verpasste Läufe, Live-Logs, Benachrichtigungen ohne Spam, eine gute Oberfläche.
- Empfehlung: MVP bauen, eine Woche parallel zu Cronicle und cron-job.org laufen lassen, dann umstellen.

## Funktionsumfang

| Bereich | Herkunft | Im MVP |
|---|---|---|
| Benutzer, Rollen, Rechte pro Kategorie | Cronicle | ja |
| Shell-Jobs im Container oder über Docker auf dem Host | Cronicle | ja |
| Live-Log während des Laufs, Lauf abbrechen | Cronicle | ja |
| HTTP-Jobs mit Methode, Header, Body, Zeitlimit | cron-job.org | ja |
| Antwort im Verlauf speichern, Testlauf | cron-job.org | ja |
| Zeitplan per Klick oder Cron-Ausdruck, Vorschau der nächsten Läufe | beide | ja |
| Benachrichtigung bei Fehler und Wiederherstellung | beide | ja (E-Mail, ntfy) |
| Verschlüsselte Geheimnisse, überall maskiert | neu | ja |
| Wiederholen bei Fehler, Überlappung steuern | beide | ja |
| Öffentliche Statusseiten | cron-job.org | nein |
| Statistiken über Wochen und Monate | cron-job.org | nein |
| Herzschlag-Überwachung | neu | nein |
| curl-Import, API mit Tokens | beide | nein |
| Mehrere Worker auf verschiedenen Rechnern | Cronicle | später |

## Architektur

Ein Container (oder zwei systemd-Dienste): FrankenPHP liefert Oberfläche und API, ein dauerhafter PHP-Prozess plant Läufe, zwei Runner führen sie aus (HTTP, Shell). Shell-Befehle erreichen Docker nur über docker-socket-proxy. Daten in SQLite.

Auslieferung: Linux-Paket mit systemd und Docker-Image. Kein Webspace, weil dort kein Dauerprozess läuft.

## Phasen

| Phase | MVP (PT) | Ganz (PT) |
|---|---|---|
| 1 Fundament inkl. PHPStan max, Psalm, Sicherheits-CI | 6 | 9–12 |
| 2 Scheduler | 6 | 8–12 |
| 3 HTTP-Jobs | 3 | 5–7 |
| 4 Shell-Jobs mit PHP-Worker-Prozessen | 6 | 9–12 |
| 5 Oberfläche | 8 | 12–16 |
| 6 Benachrichtigungen und Statistik | 2 | 6–8 |
| 7 Statusseiten | – | 3–4 |
| 8 API | – | 3–4 |
| 9 Härtung, Linux-Installer, Backup, Doku | 2 | 7–10 |
| **Summe** | **33** | **62–85** |

## Risiken

- Docker-Socket ist Root auf dem NAS: Shell-Jobs nur für Admins, Zugriff nur über docker-socket-proxy.
- Sommerzeit und verpasste Läufe: geprüfte Bibliothek, pro Job festlegen, ob nachgeholt wird.
- Minütliche HTTP-Jobs erzeugen über 500.000 Läufe pro Job und Jahr: verdichten und nach 30 Tagen löschen.
- Geheimnisse in URLs: verschlüsselt speichern, überall maskieren.
- Umfang wächst: Statusseiten, API und Statistik erst nach vier Wochen stabilem Betrieb.
