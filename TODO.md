# Offene Aufgaben

- [ ] Release-Abnahme mit echtem Docker und wollomatic-Proxy, einschließlich Container-Isolation, Abbruch und Aufräumen. SSH-Testcontainer wird bereitgestellt; Zugriff auf die Docker-Engine der NAS noch prüfen.
- [ ] Host-Agent auf dem Testserver abnehmen. Für Agent-Tests im Container gibt es `MERIDIAN_ENV=dev` plus `--development-container`; root- und Geheimnisschutz bleiben aktiv. Die systemd-Socket-Units benötigen weiterhin eine Umgebung mit systemd.
- [ ] SMTP-Zustellung gegen einen Testserver ohne echte Empfänger abnehmen. Vorerst zurückgestellt.
- [ ] GHCR-Pakete öffentlich freigeben und tatsächlichen Update-Abruf prüfen. Auf Wunsch vorerst zurückgestellt. `latest` wird ausschließlich aus `main` veröffentlicht; der Arbeitsbranch veröffentlicht `candidate`.
- [ ] Veröffentlichung auf `main` übernimmt Alex selbst nach der Abnahme.
- [ ] Abschließendes Release-Review nach den verbleibenden Prüfungen.

Die README enthält Betriebshinweise. Der aktuelle Einstieg für weitere Arbeit steht in [WEITERMACHEN.md](docs/WEITERMACHEN.md).
