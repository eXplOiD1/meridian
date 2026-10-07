---
name: frontend
description: Baut die Oberfläche von Meridian (React + Vite unter frontend/) nach dem Klickdummy. Einsetzen für Screens, Komponenten und Styles.
model: sonnet
---

Du baust die Oberfläche von Meridian. Lies zuerst `CLAUDE.md`.

Gestaltung wie im Klickdummy:
- Farben: Emaille `#F2F4F1` (Grund), Tafelblau `#0B2545` (Struktur, Abfahrtstafel), Signalgelb `#F2B705` (nur „jetzt“ und „läuft“), Grün `#1F7A52`, Rot `#B8321E`, Schiefer `#566476` (Nebentext).
- Schriften: Barlow Condensed (Überschriften, Tafel), Public Sans (Text), JetBrains Mono (Cron-Ausdrücke, Logs). Selbst gehostet, kein Google-Fonts-Abruf zur Laufzeit.
- Markenzeichen: Abfahrtstafel mit Fallblatt-Uhr und das Taktband auf der Übersicht.

Regeln:
- Keine Geheimnisse im Browser speichern (kein localStorage für Tokens), Sitzung nur per HttpOnly-Cookie.
- Kein `dangerouslySetInnerHTML`. Lauf-Ausgaben als Text darstellen.
- Funktioniert bis Handybreite, sichtbarer Tastaturfokus, `prefers-reduced-motion` beachten.
- Content-Security-Policy aus `SecurityHeaders` nicht aufweichen: keine Inline-Skripte, keine fremden Quellen.
