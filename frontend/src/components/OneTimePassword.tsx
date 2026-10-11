import { useState } from 'react';
import { formatTime } from '../lib/format';
import { Alert } from './Alert';
import { t } from '../i18n';

interface Props {
  username: string;
  password: string;
  expiresAt: string;
  /** Der Aufrufer löscht das Passwort aus seinem Zustand. */
  onDone: () => void;
}

/** Einmalpasswort: erscheint genau einmal, nur im Arbeitsspeicher dieser Ansicht, nie in URL oder Browser-Speicher. */
export function OneTimePassword({ username, password, expiresAt, onDone }: Props) {
  const [copied, setCopied] = useState<'yes' | 'no' | null>(null);

  async function copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(password);
      setCopied('yes');
    } catch {
      setCopied('no');
    }
  }

  return (
    <section className="card" aria-label={t('Einmalpasswort')}>
      <h2>{t('Einmalpasswort für {name}', { name: username })}</h2>
      <Alert tone="warn">
        {t('Dieses Passwort wird nur jetzt angezeigt und nicht gespeichert. Gib es sicher an {name} weiter (nicht per E-Mail oder Chat im Klartext, wenn es sich vermeiden lässt). Nach dem Schließen lässt es sich nur durch ein neues Einmalpasswort ersetzen.', { name: username })}
      </Alert>
      <p className="otp" data-testid="one-time-password">
        {password}
      </p>
      <p className="hint">
        {t('Gültig bis {time}. {name} muss beim ersten Anmelden ein eigenes Passwort festlegen.', { time: formatTime(expiresAt), name: username })}
      </p>
      <div className="row">
        <button type="button" className="btn btn--solid" onClick={() => void copy()}>
          {t('In die Zwischenablage kopieren')}</button>
        <button type="button" className="btn btn--ghost" onClick={onDone}>
          {t('Fertig, Passwort ausblenden')}</button>
      </div>
      <p className="hint" role="status">
        {copied === 'yes' && t('Kopiert. Die Zwischenablage nach dem Einfügen leeren.')}
        {copied === 'no' && t('Kopieren nicht möglich. Das Passwort oben markieren und von Hand kopieren.')}
      </p>
    </section>
  );
}
