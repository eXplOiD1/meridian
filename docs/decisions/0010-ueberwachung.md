# ADR 0010: Überwachung je Job

Status: umgesetzt (Phase 6, Fortsetzung)

## Entscheidungen

1. **Automatisch deaktivieren** (`jobs.auto_disable_after`, 1–1000, Standard aus). Der Zusteller (`Notifier`, Prozess `worker-http`) zählt endgültige Fehlschläge in Folge (ein Lauf mit noch folgendem Wiederholungsversuch zählt nicht) und setzt den Job danach auf `is_enabled = 0`, `next_run_at = NULL`.
   - Nur wenn der Besitzer es am Job eingestellt hat; ein vom Besitzer deaktivierter Job bleibt unberührt.
   - Nur ein **frischer** Fehlschlag (≤ 30 min) löst es aus: wer die Einstellung nachträglich einschaltet, deaktiviert den Job nie wegen alter Läufe.
   - Audit `job.auto_disabled` ohne Benutzer (`user_id` leer), Ziel nur Job-Nummer und Anzahl. Die Serie beginnt danach bei 0.
   - Meldung `disabled` nur mit `notify_on_disable`.
2. **TLS-Ablauf-Warnung** (`jobs.tls_warn_days`, 1–90, nur HTTP-Jobs). `CurlTransport` liest mit `CURLOPT_CERTINFO` das Ablaufdatum des Serverzertifikats des **ersten** Hops (nie eines Weiterleitungsziels); `HttpRunner` meldet es über `TlsExpirySink` (wirft nie) nach `job_tls`. Der Zusteller warnt **einmal je Ablaufdatum** (`notified_for`); ein erneuertes Zertifikat setzt die Warnung zurück. Die TLS-Prüfung bleibt immer an; ein Lauf, dessen Handshake scheitert, hat ohnehin eine feste TLS-Notiz.
3. **„Braucht länger als üblich“** in der Übersicht („Braucht Aufmerksamkeit“): ein laufender Lauf, der länger als das Doppelte des Mittels der letzten 10 erfolgreichen Läufe **und** mindestens 30 s länger läuft; erst ab 5 Messwerten; Testläufe zählen nicht; dieselbe Kategorie-Kappung wie die übrige Übersicht. Keine Benachrichtigung.
4. **Nachrichten** (`disabled`, `tls_expiry`) tragen nur Jobname, Zeit und feste Texte, nie Ausgabe, URL oder Header; Kanalwahl wie bei Fehlschlägen.
5. **HTTP-Authentifizierung am Job** ist reine Eingabehilfe im Editor: Basic/Bearer erzeugen den Header `Authorization`, der wie jeder Header verschlüsselt gespeichert und nie angezeigt wird. Kein neues Feld, keine neue Geheimnisstelle.
6. **curl-Import** liest im Browser (kein Server-Aufruf): URL, Methode, Header, Body; `--insecure` wird verworfen.
