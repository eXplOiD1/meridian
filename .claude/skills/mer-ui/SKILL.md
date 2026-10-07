---
name: mer-ui
description: Oberfläche von Meridian — React + Vite unter frontend/, Build nach public/app/, Klickdummy „Takt“ als Vorlage (Farben Emaille, Tafelblau, Signalgelb, Grün, Rot, Schiefer; Schriften Barlow Condensed, Public Sans, JetBrains Mono), Abfahrtstafel, Taktband, Content-Security-Policy ohne Inline-Skripte und fremde Quellen, selbst gehostete Schriften, kein dangerouslySetInnerHTML, Lauf-Ausgaben als Text, Sitzung nur per HttpOnly-Cookie, CSRF-Header, keine Tokens im localStorage, Geheimnisfelder nur schreibend, Rechte in der Oberfläche, Barrierefreiheit, Handybreite, prefers-reduced-motion, npm-Abhängigkeiten. Nutze das bei jeder Arbeit an frontend/, public/app/, Screens, Komponenten, Styles und Vite-Konfiguration.
---

# Meridian-Oberfläche

Normativ für `frontend/`. Gestaltung nach dem Klickdummy (Artifact „Takt – Klickdummy“).

## 1. Gestaltung

- Farben: Emaille `#F2F4F1` (Grund), Tafelblau `#0B2545` (Struktur), Signalgelb `#F2B705` (nur „jetzt“
  und „läuft“), Grün `#1F7A52`, Rot `#B8321E`, Schiefer `#566476` (Nebentext). Als CSS-Variablen, nie
  verstreut als Literale.
- Schriften: Barlow Condensed (Überschriften, Tafel), Public Sans (Text), JetBrains Mono (Cron, Logs).
  **Selbst gehostet** unter `frontend/` — kein Google-Fonts- oder CDN-Abruf.
- Begriffe aus dem Klickdummy übernehmen; Texte auf Deutsch.
- Bis Handybreite benutzbar, sichtbarer Tastaturfokus, `prefers-reduced-motion` beachtet.

## 2. CSP-verträglich bauen

- CSP aus `SecurityHeaders` gilt unverändert: nur eigene Quellen, keine Inline-Skripte, keine
  Inline-`<style>`-Blöcke, keine `eval`-artigen Konstrukte (`new Function`, String-`setTimeout`).
- Vite-Build ohne Inline-Skript im `index.html`; Konfiguration (z. B. API-Basis) über einen
  API-Aufruf, nicht über ein eingebettetes Skript.
- Keine externen Skripte, Schriften, Bilder, Analytics.

## 3. Daten und Sitzung

- Sitzung nur per `HttpOnly`-Cookie. Keine Tokens oder Geheimnisse in `localStorage`, `sessionStorage`,
  IndexedDB oder in der URL.
- `fetch` mit `credentials: 'same-origin'`; ändernde Anfragen senden das CSRF-Token im Header.
- Geheimnisfelder (URL mit Parametern, Header, Body, Skript, TOTP) sind **nur schreibend**: Anzeige
  „gesetzt“ + „Ersetzen“, nie vorbefüllt, nie zurückgelesen.
- Keine Geheimnisse in Query-Strings (landen in Logs und Verlauf).

## 4. Ausgabe

- Kein `dangerouslySetInnerHTML`, kein `innerHTML`. Lauf-Ausgaben und Live-Log als Text (`<pre>{text}</pre>`).
- Links aus Benutzerdaten nur `http`/`https`; externe Links mit `rel="noopener noreferrer"`.
- Fehlermeldungen des Servers als Text anzeigen, nie als HTML.

## 5. Rechte in der Oberfläche

- Schaltflächen und Menüs nach Rechten ausblenden — das ist Bedienkomfort, kein Schutz. Der Server
  prüft immer selbst.
- Ein Hinweis zur Sichtbarkeit („nur deine Kategorien“) beschreibt die **wirkliche** Sichtbarkeit.
- Gefährliche Rechte (Shell-Jobs, Benutzerverwaltung) in der Rechte-Matrix deutlich kennzeichnen.

## 6. Abhängigkeiten und Build

- Neue npm-Abhängigkeit nur nach Rückfrage bei Alex; `package-lock.json` einchecken, `npm audit` sauber.
- Build landet in `public/app/`; `frontend/node_modules/` und `frontend/dist/` nie einchecken.

## Verboten

- ❌ `dangerouslySetInnerHTML`, `innerHTML`, `eval`, `new Function`
- ❌ Inline-Skripte/-Styles im HTML, CSP aufweichen
- ❌ Schriften, Skripte oder Bilder von fremden Servern
- ❌ Tokens/Geheimnisse im Browser-Speicher oder in URLs
- ❌ Geheimnisfeld mit gespeichertem Wert vorbefüllen
- ❌ Rechte nur in der Oberfläche durchsetzen

## Checkliste

| Prüfpunkt | ✓ |
|---|---|
| Farben/Schriften wie Klickdummy, Schriften selbst gehostet? | |
| Build läuft mit der unveränderten CSP (Browser-Konsole ohne CSP-Fehler)? | |
| Keine Tokens im Browser-Speicher, CSRF-Header bei ändernden Anfragen? | |
| Geheimnisfelder nur schreibend? | |
| Ausgaben als Text, kein HTML aus Daten? | |
| Handybreite, Tastaturfokus, reduzierte Bewegung geprüft? | |
| Neue Abhängigkeit abgestimmt, `npm audit` sauber? | |
