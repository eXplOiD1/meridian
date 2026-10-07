---
name: mer-security
description: Sicherheitsregeln von Meridian — Anmeldung, Sitzung, Cookies, CSRF, Origin-Prüfung, Rechte über AccessControl::require(), Rollen Admin/Operator/Beobachter, Kategorie-Scope, IDOR, Listen serverseitig kappen, XSS, SSRF, Uploads und Pfade, Security-Header, CSP, Rate-Limit und Sperre nach Fehlversuchen, 2FA/TOTP, Geheimnisse über SecretBox und _enc-Spalten, SecretMasker für alles was das System verlässt, SensitiveParameter-Attribut, Hauptschlüssel/KeyLoader, Argon2id/PasswordHasher, API-Tokens/ApiToken, Audit-Log, Phasen-Review und Befund-Format. Nutze das bei JEDER Arbeit an src/Security, src/Auth, src/Http, public/, src/Console, src/User, an jedem neuen Endpunkt oder CLI-Befehl, an Anmeldung, Rechten, Geheimnissen, Ausgaben (Antwort, Log, Benachrichtigung, Exception) und bei jedem Sicherheits-Review.
---

# Meridian-Sicherheit

Normativ für alles, was Anmeldung, Rechte, Geheimnisse und Ausgaben betrifft. Die Regeln 1–11 in
`CLAUDE.md` gelten zusätzlich und haben Vorrang.

## 1. Neuer Endpunkt — Pflicht-Reihenfolge

```php
// 1. Anmeldung: Sitzung bzw. API-Token prüfen. Benutzer muss existieren UND is_active = 1.
//    Ohne Anmeldung -> 401, nie Daten.
// 2. Ändernde Methode (POST/PUT/PATCH/DELETE): CSRF-Token + Origin prüfen, sonst 403.
//    GET/HEAD ändern nie etwas.
// 3. Eingaben gegen Allowlist validieren (Typ, Länge, Zeichenklasse). Ablehnen, nie zurechtschneiden.
// 4. Datensatz laden. Kategorie kommt AUS DER DATENBANK, nie aus der Anfrage.
$job = $this->jobs->find($id) ?? throw new NotFound();
// 5. Recht prüfen, BEVOR irgendetwas gelesen, geändert oder zurückgegeben wird.
$this->access->require($grants, Permission::EditHttpJobs, $job->category);
// 6. Aktion ausführen (mehrere Schreibvorgänge in Connection::transaction()).
// 7. Audit-Eintrag (Wer, Was, Ziel — nie Werte von Geheimnissen).
// 8. Antwort: nur freigegebene Felder, keine Geheimnisse, Texte durch SecretMasker::mask().
```

Für jeden Endpunkt Tests pro Rolle: Admin, Operator (uneingeschränkt **und** auf eine Kategorie
beschränkt), Beobachter, ohne Anmeldung. Dazu ein Leak-Test mit Test-Geheimnis.

## 2. Anmeldung und Sitzung

- Sitzungs-ID: `random_bytes(32)`, in der DB nur als Hash gespeichert. Bei Anmeldung neu erzeugen
  (Session-Fixation), bei Abmeldung serverseitig löschen. Leerlauf- **und** absolute Laufzeit.
- Cookie: `HttpOnly`, `Secure`, `SameSite=Strict`, `Path=/`, Name mit `__Host-`-Präfix.
- Jede Anfrage prüft: Benutzer existiert, `is_active = 1`, Sitzung nicht abgelaufen. Rechte bei jeder
  Anfrage frisch laden (`UserRepository::grantsFor()`), nie aus der Sitzung cachen.
- Fehlermeldung immer gleich: „Benutzername oder Passwort falsch.“ Unbekannter Benutzer verifiziert
  gegen einen Dummy-Hash (gleiche Laufzeit). `needsRehash()` nach erfolgreicher Anmeldung auswerten.
- 2FA/TOTP: Secret nur in `totp_secret_enc`, Fenster ±1 Schritt, zuletzt benutzten Schritt speichern
  (kein Replay), Wiederherstellungscodes nur gehasht.
- Ersteinrichtung (erster Admin) nur per CLI (`user:create`). Eine leere Benutzertabelle schaltet nie
  einen Web-Einrichtungsassistenten frei.

## 3. CSRF und Herkunft

- Jede ändernde Anfrage mit Cookie-Sitzung braucht ein an die Sitzung gebundenes Token
  (Header `X-CSRF-Token`), Vergleich mit `hash_equals()`. Prüfung blockiert, sie loggt nicht nur.
- Zusätzlich `Origin` (ersatzweise `Referer`) gegen den eigenen Host: Host gegen Host, beide über
  `parse_url()`, ohne Port-Vergleich. Fehlt beides bei einer ändernden Anfrage → ablehnen.
- Anfragen mit API-Token (`Authorization: Bearer mrd_…`) brauchen kein CSRF-Token, dürfen aber keine
  Cookie-Sitzung mitbenutzen. Ein Endpunkt akzeptiert entweder Cookie+CSRF oder Token, nie eine Mischung.

## 4. Rechte über AccessControl

- **Standard ist verboten.** `AccessControl::require()` vor jedem Lesen oder Ändern, auch bei
  Detail-Ansichten, Verlauf, Live-Log, Testlauf, Export.
- Die Kategorie für die Prüfung ist die des **gespeicherten** Datensatzes. Wird ein Job in eine andere
  Kategorie verschoben, braucht es das Recht in der alten **und** der neuen.
- Rechte ohne Kategoriebezug (`users.manage`) gibt eine kategoriebeschränkte Rolle nie frei.
- „Alle Kategorien“ ist eine **ausdrückliche** Eigenschaft der Zuweisung. Eine Zuweisung, deren
  Kategorien gelöscht wurden oder fehlen, ist leer — nie uneingeschränkt.
- Gefährliche Rechte (`Permission::isDangerous()`: Shell-Jobs, Benutzerverwaltung) nur uneingeschränkt.
- Wer ein Recht vergibt, muss es selbst besitzen. Der letzte aktive Admin kann weder deaktiviert noch
  herabgestuft werden. Rechteänderungen landen im Audit-Log.
- Eine Einstellung, die eine Schutzfunktion lockert (z. B. „interne Netze für HTTP-Jobs erlauben“,
  Sperre abschalten, Aufbewahrung des Audit-Logs), bekommt ein eigenes Recht — nie als Nebenwirkung von
  `jobs.edit_http` o. ä., nie „neu || alt“ geprüft. Neue Rechte werden nie automatisch an bestehende
  Rollen außer Admin verteilt.
- CLI-Befehle laufen als Dienstbenutzer; die Vertrauensgrenze ist der Betriebssystem-Benutzer. Befehle,
  die über die Grenze hinaus wirken (z. B. per API-Token), prüfen wie Endpunkte.

## 5. Kategorie-Scope und IDOR

- Listen werden **serverseitig** auf die erlaubten Kategorien gekappt. Parameter wie `category=`,
  `all=1`, `owner=` sind eine **Anfrage**, die am erlaubten Bereich endet — nie eine Berechtigung.
- Die Sichtbarkeitsregel ist **eine** benannte Funktion (z. B. „sichtbare Kategorien für Recht X“), die
  Liste, Detail, Verlauf, Live-Log, Statistik und Suche gemeinsam benutzen. Keine `if`-Ketten pro Endpunkt.
- Liste und Detail kennen denselben Bereich. Läufe, Logs und Benachrichtigungen erben die Kategorie
  ihres Jobs.
- Ein Datensatz ohne Kategorie ist nur für uneingeschränkte Rollen sichtbar — er fällt heraus, nicht durch.
- Außerhalb des Bereichs: 404 statt 403, damit die Existenz nicht verraten wird.
- Nachweis: Testdaten mit **zwei** Kategorien plus einem Datensatz ohne Kategorie; Rolle beschränkt
  auf Kategorie A sieht weder B noch den zuordnungslosen. Mutation: Prüfung entfernen → Test fällt um.

## 6. XSS und Ausgabe

- API antwortet nur JSON (`JsonResponse`), nie HTML mit Benutzerdaten.
- Oberfläche: React-Escaping, kein `dangerouslySetInnerHTML`, Lauf-Ausgaben als Text (siehe `mer-ui`).
- Links aus Benutzerdaten nur mit Schema `http`/`https`.
- Öffentliche Statusseiten liefern eine feste Allowlist von Feldern, nie den Job-Datensatz.

## 7. SSRF

Jede vom Benutzer bestimmte URL läuft durch die SSRF-Prüfung des HTTP-Runners (Details `mer-runner`):
Schema-Allowlist, DNS vor dem Verbinden auflösen, private/Loopback/Link-local/Metadaten-Adressen sperren,
IP festhalten, jede Weiterleitung neu prüfen. Unauflösbar → ablehnen. TLS-Prüfung nie abschaltbar.

## 8. Uploads und Pfade

- Benutzereingaben werden nie zu Dateipfaden. Wo eine Datei gewählt wird (Sicherung, Import): nur ein
  **Name** aus einem festen Verzeichnis, `basename()` + `realpath()` + Präfixprüfung, Allowlist der Endung.
- Größenlimit vor dem Lesen; Inhalt prüfen (z. B. JSON parsen), nie der Endung vertrauen.
- Container-Namen, Kategorie-Namen und ähnliche Bezeichner gegen Allowlist bzw. `^[A-Za-z0-9_.-]+$`.

## 9. Header und Fehler

- `SecurityHeaders::apply()` auf **jede** Antwort — auch auf statische Dateien der Oberfläche
  (Webserver-Konfiguration). CSP nicht aufweichen. Hinter TLS zusätzlich `Strict-Transport-Security`.
- API-Antworten mit Benutzerdaten: `Cache-Control: no-store`.
- Im Betrieb keine Stacktraces, keine Pfade, keine SQL-Fehler an den Client. `display_errors=Off` gilt
  auch vor dem Kernel (Bootstrap-Fehler).
- Fehlermeldungen sagen, was falsch ist und wie man es behebt — ohne Geheimnisse, ohne Eingabewerte,
  die Geheimnisse sein könnten.

## 10. Rate-Limit und Sperre

- Anmeldung: eskalierende Sperre pro Benutzername **und** pro Client-IP. Unbekannte Benutzernamen
  verraten sich nicht (gleiche Antwort, gleiche Laufzeit), werden aber über die IP gebremst.
- Client-IP nur über konfigurierte vertrauenswürdige Proxys, nie blind aus `X-Forwarded-For`.
- Entsperren nur durch `users.manage`, mit CSRF und Audit-Eintrag.
- Teure Endpunkte (Testlauf, Zeitplan-Vorschau, Live-Log) mit Obergrenzen (Anzahl, Dauer, Verbindungen).

## 11. Geheimnisse

- Speichern nur über `SecretBox` in Spalten mit Suffix `_enc`. `config_json` und andere Klartextspalten
  enthalten nie Geheimnisse.
- **Keine Geheimnisse in Antworten — auch nicht maskiert, auch nicht als Länge oder Anfang.** Die API
  liefert höchstens ein Flag (`has_secret: true`). Formulare zeigen „gesetzt“ und „Ersetzen“, nie den Wert.
- Entschlüsseln nur dort, wo der Wert gebraucht wird (Runner), und jeden entschlüsselten Wert sofort
  im `SecretMasker` registrieren (`remember()`).
- Alles, was das System verlässt (Antwort, Log, Verlauf, Live-Log, Benachrichtigung, Exception),
  läuft durch `SecretMasker::mask()` — **vor** JSON-Kodierung und **vor** dem Kürzen.
- Parameter mit Geheimnissen: `#[\SensitiveParameter]`, auch Arrays, die Geheimnisse enthalten können
  (z. B. die Umgebung). Klassen mit Geheimnissen: `__debugInfo()` ohne Wert, nicht serialisierbar.
- **Kein Geheimnis als Literal im Quelltext** — auch nicht als Standardwert, auch nicht base64. Testwerte
  offensichtlich unecht (`TEST-SECRET-do-not-use-123`). Keine `.env`, keine Schlüsseldatei im Repository.
- Hauptschlüssel: nur über `KeyLoader`, bevorzugt als Datei (Docker-Secret, systemd `LoadCredential`).
  Lesen erzeugt nie einen Schlüssel; `key:generate` überschreibt nie. Der Schlüssel erscheint in keiner
  Ausgabe — Vergleiche nur über einen Fingerabdruck.
- Kindprozesse erben keine Geheimnisse: `proc_open` immer mit expliziter Umgebung (siehe `mer-runner`).

## 12. Passwörter

- Nur `PasswordHasher` (Argon2id). Fehlt Argon2id, wird abgebrochen — kein stiller Rückfall.
- Mindestens 8 Zeichen (von Alex so festgelegt), Obergrenze gegen Rechenlast-Missbrauch. Kein Passwort als CLI-Argument.

## 13. API-Tokens

- Nur `ApiToken::issue()`. Klartext genau einmal an den Benutzer, gespeichert nur `token_hash`.
- Token handelt mit den Rechten seines Benutzers (inaktiver Benutzer → Token wirkungslos), optional
  enger; Ablaufdatum, `last_used_at`, Widerruf. Tokens nie loggen, nie in URLs.

## 14. Audit-Log

- Pflicht-Ereignisse: Anmeldung (Erfolg, Fehlschlag, Sperre), Abmeldung, 2FA an/aus, Rechte- und
  Rollenänderungen, Benutzer anlegen/deaktivieren, Job anlegen/ändern/löschen, Geheimnis geändert
  (nur dass, nie was), Token angelegt/widerrufen, manueller Lauf, Einstellungen, die Schutz lockern.
- Nur anhängen; Änderungen und Löschen nur über eine eigene Aufbewahrungsregel. `target` maskiert.

## 15. Review (Phasenende, auf Anfrage)

Ablauf: `git diff` der Phase lesen → jede Ausgabestelle → jeden Endpunkt/CLI-Befehl → jeden Weg zu SQL,
Shell, HTML → `composer taint` → `composer audit` → Befunde selbst verifizieren.

Format: `SCHWERE | Datei:Zeile | Kategorie | Beschreibung | Fix`. Nachprüfung:
`BEHOBEN / TEILWEISE / OFFEN / FEHLALARM`. Ein Fund ist eine Stichprobe: repo-weit nach allen
Vorkommen desselben Musters suchen. Ein abweichender Check zählt nicht als Abdeckung.

Bewusst akzeptierte Stelle (Fehlalarm, gewolltes Verhalten) nur mit Zustimmung von Alex, markiert am
Fundort: `// SECURITY-REVIEWED <Datum> (<Verdikt>): <Begründung>`. Der Marker unterdrückt die
Musterprüfung des Hooks — nicht aber `strict_types` und `@phpstan-ignore` (Regel 1).

## Verboten

- ❌ Endpunkt oder CLI-Befehl ohne `AccessControl::require()` vor dem ersten Lesen
- ❌ Kategorie aus der Anfrage statt aus dem gespeicherten Datensatz prüfen
- ❌ Leere Kategorieliste als „alle Kategorien“ deuten
- ❌ Listen-Scope nur in der Oberfläche begrenzen
- ❌ Geheimnis in Antwort, Log, Exception, URL oder Query-String — auch maskiert
- ❌ Ausgabe ohne `SecretMasker::mask()`; kürzen vor dem Maskieren
- ❌ Geheimnis als Literal im Code, Schlüssel als Umgebungsvariable in Compose-Datei oder Image
- ❌ CSRF-Prüfung, die nur loggt; GET, der etwas ändert
- ❌ `password_hash()` außerhalb von `PasswordHasher`, Token im Klartext speichern
- ❌ Stacktrace oder `display_errors=On` im Betrieb
- ❌ Neues Recht stillschweigend an Operator/Beobachter verteilen

## Checkliste

| Prüfpunkt | ✓ |
|---|---|
| Anmeldung geprüft (existiert, aktiv, Sitzung gültig)? | |
| Ändernde Anfrage: CSRF-Token + Origin blockierend geprüft? | |
| `AccessControl::require()` vor jedem Lesen/Ändern, Kategorie aus der DB? | |
| Liste serverseitig gekappt, gleiche Sichtbarkeitsfunktion wie Detail, mit zwei Kategorien getestet? | |
| Eingaben gegen Allowlist validiert, Ablehnen statt Zurechtschneiden? | |
| Geheimnisse nur `_enc`, nie in der Antwort (nur `has_*`-Flag)? | |
| Jede Ausgabestelle maskiert und mit Test-Geheimnis getestet? | |
| `#[\SensitiveParameter]` an allen Parametern mit Geheimnissen? | |
| Tests pro Rolle (Admin, Operator ±Kategorie, Beobachter, ohne Anmeldung)? | |
| Audit-Eintrag für sicherheitsrelevante Aktion? | |
| `composer check` grün (PHPStan max ohne neue Funde, Psalm, Taint, Tests, audit)? | |
