# Automations / Automationen

[English](#english) · [Deutsch](#deutsch) · [README](../README.md)

## English

Open **Automations → Create automation**. Rules belong to their creator and run with that account's current permissions. Administrators receive `automations.manage`; custom roles can receive it globally. Jobs and monitors still require category access, and job actions require permission to run the target job.

1. Choose a trigger: job success, failure, timeout, cancellation or recovery; monitor outage or recovery; or a five-field cron schedule with a time zone.
2. Optionally add conditions: job status, consecutive failures, monitor state, a time window or weekdays. Combine all conditions (AND) or any condition (OR). No conditions means the trigger is sufficient.
3. Choose one to five actions: start an existing job or send a custom message through enabled notification channels. Browser messages go to the rule owner's subscribed devices.
4. Choose a minimum interval between executions (default 60 seconds) and confirm your current password to enable the rule or change an enabled rule.

Example: when the website monitor reports an outage, and it is a weekday between 08:00 and 18:00, start an approved recovery job and send a notification. Example schedule: `0 8 * * 1-5` runs at 08:00 on weekdays in the chosen time zone. Overnight time windows are supported; identical start/end times mean the whole day.

**Check conditions** previews current conditions without running actions. The log distinguishes skipped/failed evaluations from queued actions and links job actions to their job. Queued does not mean the job succeeded or a device displayed a notification. Job runs identify their trigger as Automation; delivery results remain in notification delivery history.

Events are durable and consumed with their action writes in one transaction. An event is handled at most once per rule. A failure to queue any action rolls back that rule's earlier action writes. Rules do not replay events from before their creation. Test runs do not trigger rules. Missed scheduled times are evaluated once on recovery, with current conditions, then the next future time is selected.

Permissions are checked at evaluation, before queued job execution and before notification delivery. Disabling/deleting a rule blocks its queued work; it does not interrupt already running jobs. The owner becoming inactive prevents execution. Automation chains cannot repeat a rule and stop after eight rules; the minimum interval further limits repeated actions. A creator can queue at most 360 automation job actions per hour, separately from the manual/test limit. At most 200 rules, ten conditions and five actions per rule are supported. Logs and processed events are retained for 90 days; the interface shows the ten latest log entries per rule.

Automations use existing HTTP workers, job queues and notification delivery. No additional container is required.

## Deutsch

**Automationen → Automation anlegen** öffnen. Regeln gehören ihrem Ersteller und laufen mit dessen aktuellen Rechten. Administratoren erhalten `automations.manage`; eigene Rollen können es global erhalten. Für Jobs und Überwachungen gelten weiterhin Kategorie-Rechte; Job-Aktionen benötigen zusätzlich das Recht, den Ziel-Job zu starten.

1. Auslöser wählen: Job erfolgreich, fehlgeschlagen, Zeitlimit überschritten, abgebrochen oder wieder erfolgreich; Überwachung ausgefallen oder wieder erreichbar; alternativ einen Zeitplan mit fünf Cron-Feldern und Zeitzone.
2. Optional Bedingungen ergänzen: Job-Zustand, Fehler in Folge, Überwachungszustand, Uhrzeitfenster oder Wochentage. Alle Bedingungen (UND) oder mindestens eine (ODER) verlangen. Ohne Bedingungen genügt der Auslöser.
3. Eine bis fünf Aktionen wählen: vorhandenen Job starten oder eine eigene Nachricht über aktive Kanäle senden. Browser-Nachrichten erreichen die verbundenen Geräte des Regel-Erstellers.
4. Mindestabstand wählen (Standard 60 Sekunden). Zum Aktivieren oder Ändern einer aktiven Regel das eigene aktuelle Passwort bestätigen.

Beispiel: Meldet die Web-Überwachung einen Ausfall und ist es werktags zwischen 08:00 und 18:00, einen freigegebenen Wiederherstellungs-Job starten und eine Nachricht senden. `0 8 * * 1-5` läuft werktags um 08:00 in der gewählten Zeitzone. Uhrzeitfenster über Mitternacht funktionieren; gleicher Beginn und Ende gelten für den ganzen Tag.

**Bedingungen prüfen** führt keine Aktionen aus. Das Protokoll unterscheidet übersprungene oder fehlgeschlagene Prüfungen von eingereihten Aktionen und verlinkt Job-Aktionen zum Job. Eingereiht bedeutet noch keinen erfolgreichen Lauf und keine Anzeige auf dem Handy. Job-Läufe zeigen Automation als Auslöser; Zustellergebnisse stehen im Benachrichtigungsverlauf.

Ereignisverarbeitung und Aktions-Einreihung erfolgen gemeinsam in einer Datenbank-Transaktion. Eine Regel verarbeitet jedes Ereignis höchstens einmal. Kann eine Aktion nicht eingereiht werden, werden die zuvor eingereihten Aktionen dieser Regel zurückgenommen. Ereignisse vor der Regelerstellung werden nicht nachgespielt; Testläufe lösen keine Regeln aus. Nach einer Unterbrechung wird ein verpasster Zeitplan einmal mit den aktuellen Bedingungen geprüft und danach der nächste zukünftige Termin gewählt.

Rechte werden bei der Prüfung, vor Job-Start und vor Nachrichtenzustellung erneut geprüft. Deaktivieren oder Löschen verhindert noch wartende Aktionen, unterbricht aber keine bereits laufenden Jobs. Inaktive Ersteller können keine Aktionen ausführen. Ketten wiederholen keine Regel und enden spätestens nach acht Regeln; der Mindestabstand begrenzt zusätzliche Wiederholungen. Pro Ersteller sind 360 Automations-Job-Aktionen pro Stunde möglich, getrennt vom Limit für manuelle und Testläufe. Unterstützt werden 200 Regeln, jeweils zehn Bedingungen und fünf Aktionen. Protokolle und verarbeitete Ereignisse bleiben 90 Tage erhalten; die Oberfläche zeigt die letzten zehn Protokolleinträge je Regel.

Automationen verwenden vorhandene HTTP-Worker, Job-Warteschlangen und Benachrichtigungszustellung. Sie benötigen keinen zusätzlichen Container.
