import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { errorMessage, fieldErrors, isStatus } from '../lib/errors';

interface PasswordConfirmProps {
  label: string;
  /** Folgen der Aktion in einem Satz, vor der Bestätigung gelesen. */
  question: string;
  confirmLabel: string;
  /** Sendet die Anfrage mit dem Passwort des Handelnden; wirft ApiError bei Fehlern. */
  onSubmit: (password: string) => Promise<void>;
  disabled?: boolean;
  accessibleName?: string;
  tone?: 'danger' | 'normal';
}

/**
 * Gefährliche Verwaltungsaktion mit Rückfrage und Passwort des Handelnden (ADR 0005 E7). Das Passwort lebt nur im
 * Feld dieser Komponente und wird nach jedem Absenden geleert. Ein falsches Passwort steht am Feld, alles andere
 * (409, 429, …) als Meldung in der Rückfrage. Der Fokus startet im Passwortfeld; Escape bricht ab.
 */
export function PasswordConfirm({ label, question, confirmLabel, onSubmit, disabled = false, accessibleName, tone = 'danger' }: PasswordConfirmProps) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [fieldError, setFieldError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  function close(): void {
    setOpen(false);
    setPassword('');
    setFieldError(null);
    setError(null);
  }

  async function submit(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setFieldError(null);
    setError(null);
    const typed = password;
    setPassword('');
    try {
      await onSubmit(typed);
      close();
    } catch (caught) {
      const fields = fieldErrors(caught);
      if (fields.current_password !== undefined) {
        setFieldError(fields.current_password);
      } else if (isStatus(caught, 403) && /passwort/i.test(errorMessage(caught))) {
        setFieldError(errorMessage(caught));
      } else {
        setError(errorMessage(caught));
      }
    } finally {
      setBusy(false);
    }
  }

  if (!open) {
    return (
      <button
        type="button"
        className={tone === 'danger' ? 'btn btn--ghost btn--danger-text' : 'btn btn--ghost'}
        disabled={disabled}
        aria-label={accessibleName}
        onClick={() => setOpen(true)}
      >
        {label}
      </button>
    );
  }
  return (
    <form
      className="confirm confirm--form"
      aria-label={accessibleName ?? label}
      onSubmit={(event) => void submit(event)}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          close();
        }
      }}
    >
      <span className="confirm__q">{question}</span>
      <div className="field">
        <label className="field__label" htmlFor={id}>
          Dein Passwort zur Bestätigung
        </label>
        <input
          id={id}
          className="input"
          type="password"
          name="current_password"
          autoComplete="current-password"
          autoFocus
          required
          value={password}
          aria-invalid={fieldError === null ? undefined : true}
          aria-describedby={fieldError === null ? undefined : id + '-err'}
          onChange={(event) => setPassword(event.target.value)}
        />
        {fieldError !== null && (
          <span id={id + '-err'} className="field__error">
            {fieldError}
          </span>
        )}
      </div>
      {error !== null && (
        <p className="confirm__err" role="alert">
          {error}
        </p>
      )}
      <div className="row">
        <button type="submit" className={tone === 'danger' ? 'btn btn--danger btn--small' : 'btn btn--solid btn--small'} disabled={busy || password === ''}>
          {confirmLabel}
        </button>
        <button type="button" className="btn btn--ghost btn--small" onClick={close}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}
