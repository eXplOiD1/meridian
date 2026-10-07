import { useState } from 'react';

interface ConfirmProps {
  label: string;
  question: string;
  confirmLabel: string;
  onConfirm: () => void;
  busy?: boolean;
  disabled?: boolean;
}

/** Zerstörende Aktion mit Rückfrage; der Fokus startet beim sicheren „Abbrechen“. */
export function Confirm({ label, question, confirmLabel, onConfirm, busy = false, disabled = false }: ConfirmProps) {
  const [open, setOpen] = useState(false);
  if (!open) {
    return (
      <button type="button" className="btn btn--ghost btn--danger-text" disabled={disabled} onClick={() => setOpen(true)}>
        {label}
      </button>
    );
  }
  return (
    <div className="confirm" role="group" aria-label={label}>
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
