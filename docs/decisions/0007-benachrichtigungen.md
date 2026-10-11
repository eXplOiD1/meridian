# ADR 0007 – Benachrichtigungen (Phase 6)

Status: angenommen (Alex, 11.10.2026: SMTP einrichtbar, PHPMailer als Standard und damit freigegebene Abhängigkeit).

## Entscheidungen

- **N1 Kanäle:** `email` (SMTP über PHPMailer; Host, Port, Verschlüsselung `none`/`starttls`/`ssl`, Benutzer, Passwort,
  Absender, Empfänger) und `ntfy` (Server, Thema, Priorität, optionales Token). Telegram und Webhook folgen später.
  Ein Kanal heißt eindeutig (ohne Rücksicht auf Groß-/Kleinschreibung).
- **N2 Geheimnisse:** Passwort bzw. Token liegen nur verschlüsselt in `notification_channels.secret_enc` (`SecretBox`), die
  übrige Konfiguration in `config_json`. Antworten tragen nie das Geheimnis, nur `has_secret`. Ersetzen nur als Ganzes.
- **N3 Recht:** Kanäle verwalten und Testnachricht senden braucht `settings.manage` (gefährlich, nur uneingeschränkt): ein
  Kanal ist ein Weg, Daten aus dem System zu schicken. Die Auswahl im Job-Editor (`id`, `name`, `type`) sieht jeder mit Sicht auf Jobs.
- **N4 Je Job:** `notify_on_failure` (nach `notify_failure_after` Fehlschlägen in Folge, 1–100), `notify_on_recovery`,
  `notify_channels` (`all` | `selected` | `none`, Auswahl in `job_channels`). Ein Fehlschlag zählt erst, wenn kein Wiederholungsversuch mehr folgt.
- **N5 Zustellung getrennt:** Ein Zusteller im `worker-http`-Prozess (Netz vorhanden) wertet beendete Läufe aus
  (`job_notify_state`: Cursor, Fehlerserie, „gemeldet“), legt Einträge in `notification_outbox` an und stellt sie zu
  (Wiederholung nach 1, 5, 30 Minuten, dann `failed`). Der Lauf-Abschluss in den Workern bleibt unberührt.
- **N6 Inhalt:** Nachricht nur aus Jobname, Status, Zeit, Hinweis (bereits maskiert gespeichert) und Link; nie die Ausgabe eines Laufs, nie Skript, URL oder Header.
- **N7 Netz:** Das Ziel eines Kanals wird wie bei HTTP-Jobs aufgelöst, jede Adresse geprüft und festgehalten (SMTP: verbunden wird
  zur geprüften IP, TLS prüft den Namen). Private Adressen und Loopback sind für genau diesen Host und Port freigegeben (der Admin hat
  den Kanal bewusst angelegt); Link-local/Metadaten, reservierte Bereiche und die eigene Infrastruktur nie. TLS-Prüfung nie aus.
- **N8 Grenzen:** höchstens 20 Kanäle, 10 Empfänger je E-Mail-Kanal, Zeitlimit 15 s je Zustellung.
