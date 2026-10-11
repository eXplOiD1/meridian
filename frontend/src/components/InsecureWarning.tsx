import { Alert } from './Alert';
import { t } from '../i18n';

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
      <strong>{t('Unverschlüsselte Verbindung (HTTP).')}</strong> {t('Passwort und Sitzung können im Netzwerk mitgelesen werden. Nur in einem vertrauenswürdigen Heimnetz verwenden; sonst Meridian hinter einem HTTPS-Proxy betreiben.')}
    </Alert>
  );
}
