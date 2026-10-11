# Funktionsinventar zum Redesign „Material Aurora“

Zweck: Nachweis, dass keine Funktion der bisherigen Oberfläche verloren geht. Spalte „Aurora“: Kennzeichnung, ob Aussehen, Aufbau oder nur Stil geändert wurde. Backend-Verträge sind nicht berührt (einzige Ergänzung: `PUT /api/auth/theme`, ADR 0017).

| Bereich | Funktion | Recht | Aurora |
|---|---|---|---|
| Anmeldung | Benutzername/Passwort, zweiter Faktor (TOTP oder Wiederherstellungscode), Proxy-Fehlerhinweis, HTTP-Warnung | – | Neues Layout; Sprach- und Farbschalter ergänzt |
| Rahmen | Menü nach Rechten, nicht freigegebene Bereiche sichtbar-gesperrt, Abmelden, Sprachwechsel | je Bereich | Vier Gruppen, feste Seitenleiste, Mobil-Schublade, Farbschalter |
| Übersicht | Nächste Läufe (30-s-Aktualisierung), Uhr, „Heute im Takt“, Aufmerksamkeit (Job/Überwachung/langsam), Summen, 2FA-Hinweis | `jobs.view` | Lage-Kopf mit Ring ergänzt (echte Daten) |
| Jobs | Liste, Suche, Filter (Status, Kategorie), Zähler, Anlegen | `jobs.view` / Anlegen-Rechte | Karten statt Tabelle mobil |
| Job anlegen/bearbeiten | HTTP- und Shell-Jobs, Zeitplan-Baukasten + Cron + Sekunden-Intervalle (`@every Ns`), Zeitzone, curl-Import, Header, Authentifizierung, Body, Zeitlimit, Wiederholen, Überlappung, Antwort speichern, Benachrichtigung je Job, Ziel (Docker/Host), Skript, Umgebungsvariablen, Anzeige gespeicherter Werte (Einstellung), Ersetzen von Geheimnissen | `jobs.create`/`jobs.edit`, HTTP/Shell getrennt | Einfach/Experte, gleiche Felder |
| Job-Ansicht | Fakten, Verlauf, Lauf-Detail, Live-Log (Verfolgen, Abbruch), Starten, Aktivieren/Deaktivieren, Löschen mit Bestätigung, Überwachung, TLS-Ablauf | je Aktion | Stil |
| Verlauf | Läufe über alle sichtbaren Jobs, Filter, Detail | `jobs.view` | Karten mobil |
| Statistik | Kennzahlen, Balkendiagramm, Zeiträume | `jobs.view` | Stil |
| Überwachung | Je-Job-Überwachung, Herzschlag-Monitore (Ping-URL), Status | `jobs.view` / Verwalten | Stil |
| Statusseiten | Anlegen, Bearbeiten, Einträge, öffentliche Seite `/status/{slug}` | `statuspages.edit` | Stil |
| Benutzer | Liste, Anlegen mit Einmalpasswort, Rollen/Kategorien zuordnen, deaktivieren/als gelöscht markieren | `users.manage` | Karten mobil |
| Rollen | Katalog, Editor, Rechte-Matrix, eigene Rollen | `roles.manage` | Stil |
| Kategorien | Verwalten, Beobachter (Watchers), Freigaben | `categories.manage` | Stil |
| Audit-Log | Seiten, Aktionen ohne Inhalte | `users.manage` | Karten mobil |
| Einstellungen | Allowlist-Einstellungen (Zeitlimits, Antwortspeicherung, URL-Anzeige, Sitzungsdauer, Sekunden-Intervall, Aufbewahrung, Basis-URL, Enthüllen), interne Ziele, Shell-Ziele, Benachrichtigungskanäle, Sicherung | `settings.manage` u. a. | Kurztext + „Mehr erfahren“ |
| Konto | Passwort ändern, 2FA ein/aus mit QR und Codes, Sitzungen anzeigen/beenden, API-Tokens, Sprache, Farbschema | eigenes Konto | Stil, Farbschema ergänzt |
| Öffentlich | `/ping/{token}`, `/status/{slug}` (ohne Sitzung) | – | unverändert |

Prüfung: `docs/redesign/abnahme.md` (Ansichtsmatrix, Rollen, Ergebnisse, Grenzen).
