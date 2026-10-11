# Oberflächenprüfung

Stand: 11.10.2026. Geprüft wurde der lokale Produktionsbuild mit simulierten API-Antworten im Browser; dies ersetzt keine Abnahme der laufenden Installation.

- Einstellungen: vorhandene interne Ziele bearbeiten, Bearbeiten/Entfernen nebeneinander, breite Desktop-Ansicht und schmale Mobilansicht.
- Benachrichtigungen: Standard „Wie meine UI-Sprache“, ausdrückliche Auswahl Deutsch/Englisch, Anlegen und erneutes Bearbeiten.
- UI-Sprachwechsel: gespeicherte Auswahl nach Neuladen, sichtbare Fehlermeldung bei fehlgeschlagener Speicherung.

API-Integrationstests mit migrierter SQLite-Datenbank prüfen Kontosprache, Standardauflösung beim Versand, ausdrückliche Auswahl, ungültige Sprache und CSRF. Der Versand über echte SMTP- und Docker-Umgebungen bleibt in TODO.md offen.
