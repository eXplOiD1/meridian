import { Alert } from './Alert';

/** Die Seite läuft über reines HTTP: Passwort und Sitzung sind im Netz mitlesbar. */
export function isInsecureConnection(): boolean {
  return window.location.protocol === 'http:';
}

export function InsecureWarning() {
  if (!isInsecureConnection()) {
    return null;
  }

  return (
    <Alert tone="warn">
      <strong>Unverschlüsselte Verbindung (HTTP).</strong> Passwort und Sitzung können im Netzwerk mitgelesen werden.
      Nur in einem vertrauenswürdigen Heimnetz verwenden; sonst Meridian hinter einem HTTPS-Proxy betreiben.
    </Alert>
  );
}
