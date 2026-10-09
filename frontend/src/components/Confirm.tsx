import { useState } from 'react';

interface ConfirmProps {
  label: string;
  question: string;
  confirmLabel: string;
  onConfirm: () => void;
  busy?: boolean;
  disabled?: boolean;
  /** Eindeutiger Name für Screenreader, wenn mehrere gleiche Schaltflächen auf der Seite stehen. */
  accessibleName?: string;
}

/** Zerstörende Aktion mit Rückfrage; der Fokus startet beim sicheren „Abbrechen“. */
export function Confirm({ label, question, confirmLabel, onConfirm, busy = false, disabled = false, accessibleName }: ConfirmProps) {
  const [open, setOpen] = useState(false);
  if (!open) {
    return (
      <button type="button" className="btn btn--ghost btn--danger-text" disabled={disabled} aria-label={accessibleName} onClick={() => setOpen(true)}>
        {label}
      </button>
    );
  }
  return (
    <div className="confirm" role="group" aria-label={accessibleName ?? label}>
      <span className="confirm__q">{question}</span>
      <button type="button" className="btn btn--danger btn--small" disabled={busy} onClick={onConfirm}>
        {confirmLabel}
      </button>
      <button type="button" className="btn btn--ghost btn--small" autoFocus onClick={() => setOpen(false)}>
        Abbrechen
      </button>
    </div>
  );
}
