import type { InputHTMLAttributes, ReactNode } from 'react';
import { useId } from 'react';

interface FieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'className'> {
  label: string;
  hint?: ReactNode;
  mono?: boolean;
}

/** Beschriftetes Eingabefeld: Label und Eingabe sind immer verknüpft (Bedienung per Screenreader und Tastatur). */
export function Field({ label, hint, mono = false, ...input }: FieldProps) {
  const id = useId();
  const hintId = hint === undefined ? undefined : id + '-hint';
  return (
    <div className="field">
      <label className="field__label" htmlFor={id}>
        {label}
      </label>
      <input id={id} className={mono ? 'input input--mono' : 'input'} aria-describedby={hintId} {...input} />
      {hint !== undefined && (
        <span id={hintId} className="field__hint">
          {hint}
        </span>
      )}
    </div>
  );
}
