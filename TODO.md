# Offene Aufgaben

- [ ] Release-Abnahme mit echtem Docker und wollomatic-Proxy, einschließlich Container-Isolation, Abbruch und Aufräumen. Auf Wunsch vorerst zurückgestellt; Alex testet die Zielumgebung.
- [ ] Host-Agent unter Linux/systemd abnehmen. Vorerst zurückgestellt.
- [ ] SMTP-Zustellung gegen einen Testserver ohne echte Empfänger abnehmen. Vorerst zurückgestellt.
- [ ] Gesamte ältere Testsuite an die aktuellen Schnittstellen anpassen und unter Linux ausführen. Lokale Windows-Gesamtläufe sind derzeit nicht grün; erfolgreiche Einzelprüfungen ersetzen diese Abnahme nicht.
- [ ] Container-Workflow auf GitHub erfolgreich ausführen und GHCR-Pakete öffentlich freigeben. `latest` wird ausschließlich aus `main` veröffentlicht; der Arbeitsbranch veröffentlicht `candidate`.
- [ ] Arbeitsbranch nach Abschluss der Abnahme in `main` übernehmen und Veröffentlichung mit `docker compose pull` prüfen.
- [ ] Laufzeitvergleich „braucht länger als üblich“ ergänzen. Optionale Funktion; vor dem Release Umfang entscheiden.
- [ ] Abschließendes Release-Review nach den verbleibenden Prüfungen.

Die README enthält Betriebshinweise. Der aktuelle Einstieg für weitere Arbeit steht in [WEITERMACHEN.md](docs/WEITERMACHEN.md).
