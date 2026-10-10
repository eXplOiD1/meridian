import { useState } from 'react';
import { formatTime } from '../lib/format';
import { Alert } from './Alert';

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
    <section className="card" aria-label="Einmalpasswort">
      <h2>Einmalpasswort für {username}</h2>
      <Alert tone="warn">
        Dieses Passwort wird nur jetzt angezeigt und nicht gespeichert. Gib es sicher an {username} weiter (nicht per E-Mail oder Chat im Klartext, wenn es sich vermeiden lässt). Nach dem Schließen lässt es sich nur durch
        ein neues Einmalpasswort ersetzen.
      </Alert>
      <p className="otp" data-testid="one-time-password">
        {password}
      </p>
      <p className="hint">
        Gültig bis {formatTime(expiresAt)}. {username} muss beim ersten Anmelden ein eigenes Passwort festlegen.
      </p>
      <div className="row">
        <button type="button" className="btn btn--solid" onClick={() => void copy()}>
          In die Zwischenablage kopieren
        </button>
        <button type="button" className="btn btn--ghost" onClick={onDone}>
          Fertig, Passwort ausblenden
        </button>
      </div>
      <p className="hint" role="status">
        {copied === 'yes' && 'Kopiert. Die Zwischenablage nach dem Einfügen leeren.'}
        {copied === 'no' && 'Kopieren nicht möglich. Das Passwort oben markieren und von Hand kopieren.'}
      </p>
    </section>
  );
}
