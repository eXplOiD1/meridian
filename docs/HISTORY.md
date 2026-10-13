<!-- Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
     SPDX-License-Identifier: LicenseRef-Meridian-Proprietary -->
# History, monitoring and public status

[English](#english) · [Deutsch](#deutsch) · [README](../README.md)

<a id="english"></a>
## English

**History** combines job runs with monitor checks, incoming heartbeats, state changes and recorded alerts. Choose today, yesterday, this calendar week, this calendar month or a custom range. The start is inclusive and the end is exclusive. Input dates use the browser's local timezone; the server stores UTC. Job status filtering remains available. Each list loads 50 entries at a time; **Load more** retains its filters.

The daily timeline has one tick per hour and accommodates daylight saving changes. Select another day to view earlier results. Click an observed slot to filter the history to its job or monitor and its five-minute window. A single job run opens its details automatically; multiple events remain listed for selection. Currently disabled jobs have a **Disabled** badge. Monitor rows are labelled separately.

Monitor observations are recorded from this version onward and retained for 90 days. Already retained monitor alerts are imported as alerts; missing historical checks are not invented. For state changes and alerts, delivery counters show pending, accepted and failed notifications. Accepted means that the notification service accepted the request, not that the device displayed it. The counters survive routine outbox pruning. Zero queued messages can mean that notifications were disabled, no active channel was selected, or the next delivery evaluation has not yet happened.

Web checks follow up to five redirects, checking and pinning each destination against the same target policy. The default maximum response time is **5 seconds**, configurable per monitor from 0.1 to 10 seconds. The limit covers the complete redirect chain. The configured number of consecutive failed checks determines when the monitor declares an outage and queues its alarm. A five-minute interval with three required failures can therefore detect an outage only after several checks. **Test** performs one check without changing the live monitor state or sending an alert.

A web check validates the exact URL and its expected HTTP status. It does not run JavaScript, load all browser resources, or prove that an application's business functions work. Use the exact health endpoint or critical URL instead of only the host's home page when those differ.

Public status pages show 90 daily bars using **UTC day boundaries**. Green means successful observations, amber mixed results, red failures, and grey no retained observations. Click a day to see up to 100 recorded events for that published item. Internal names, targets, output, notification channels and internal IDs are excluded. The bars represent recorded results, not a calculated time-based uptime percentage. Job details follow the configured run retention; days without retained observations remain grey.

<a id="deutsch"></a>
## Deutsch

Der **Verlauf** zeigt Job-Läufe sowie Prüfungen, Lebenszeichen, Zustandswechsel und gespeicherte Alarme der Überwachungen. Wählbar sind heute, gestern, diese Kalenderwoche, dieser Kalendermonat und ein eigener Zeitraum. Der Beginn zählt mit, das Ende nicht. Eingaben verwenden die lokale Browser-Zeitzone; der Server speichert UTC. Job-Läufe lassen sich zusätzlich nach Status filtern. Jede Liste lädt 50 Einträge; **Mehr laden** behält die Filter bei.

Der Tagesverlauf markiert jede Stunde und berücksichtigt die Zeitumstellung. Über die Tagesauswahl lassen sich frühere Ergebnisse ansehen. Ein angeklickter Abschnitt filtert den Verlauf auf den Job oder die Überwachung und das betreffende Fünf-Minuten-Fenster. Ein einzelner Job-Lauf öffnet seine Details automatisch; mehrere Ereignisse bleiben zur Auswahl sichtbar. Aktuell deaktivierte Jobs tragen die Kennzeichnung **Deaktiviert**, Überwachungen eine eigene Kennzeichnung.

Überwachungsereignisse werden ab dieser Version für 90 Tage gespeichert. Noch vorhandene frühere Alarme werden als Alarme übernommen; fehlende Prüfungen werden nicht rekonstruiert. Zustandswechsel und Alarme zeigen Zähler für wartende, angenommene und fehlgeschlagene Benachrichtigungen. Angenommen bestätigt die Annahme durch den Dienst, nicht die Anzeige auf dem Gerät. Diese Zähler bleiben auch nach dem Aufräumen der Versandwarteschlange erhalten. Keine eingereihten Nachrichten können bedeuten, dass Benachrichtigungen deaktiviert waren, kein aktiver Kanal gewählt war oder die nächste Auswertung noch aussteht.

Web-Prüfungen verfolgen höchstens fünf Weiterleitungen. Jedes Ziel wird nach derselben Zielrichtlinie geprüft und auf die geprüften Adressen festgelegt. Standardmäßig gilt eine maximale Antwortzeit von **5 Sekunden**, pro Überwachung einstellbar von 0,1 bis 10 Sekunden. Die Grenze gilt für die gesamte Weiterleitungskette. Erst die konfigurierte Zahl aufeinanderfolgender Fehlversuche löst den Ausfallzustand und dessen Alarm aus. Bei fünf Minuten Intervall und drei Fehlversuchen kann ein Ausfall deshalb erst nach mehreren Prüfungen erkannt werden. **Testen** führt eine Einzelprüfung aus, ohne den laufenden Zustand zu verändern oder einen Alarm zu senden.

Die Web-Prüfung bewertet die genaue URL und deren erwarteten HTTP-Status. Sie führt kein JavaScript aus, lädt nicht alle Browser-Inhalte und bestätigt keine Geschäftsabläufe der Anwendung. Bei unterschiedlichen Funktionen deren genaue URL oder einen passenden Health-Endpunkt verwenden, statt nur die Startseite des Hosts zu prüfen.

Öffentliche Statusseiten zeigen 90 Tagesbalken mit **UTC-Tagesgrenzen**. Grün bedeutet erfolgreiche Beobachtungen, Orange gemischte Ergebnisse, Rot Fehler und Grau keine gespeicherten Beobachtungen. Ein Klick zeigt bis zu 100 Ereignisse dieses veröffentlichten Eintrags. Interne Namen, Ziele, Ausgaben, Benachrichtigungskanäle und interne IDs werden nicht veröffentlicht. Die Balken zeigen beobachtete Ergebnisse und keinen berechneten zeitbasierten Uptime-Prozentsatz. Für Job-Details gilt die konfigurierte Aufbewahrung; Tage ohne gespeicherte Beobachtungen bleiben grau.
