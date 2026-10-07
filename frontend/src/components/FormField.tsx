import type { ReactNode } from 'react';
import { useId } from 'react';

interface Aria {
  id: string;
  'aria-describedby': string | undefined;
  'aria-invalid': true | undefined;
}

interface FormFieldProps {
  label: string;
  hint?: ReactNode;
  error?: string | undefined;
  children: (aria: Aria) => ReactNode;
}

/** Beschriftetes Feld mit Hinweis und Fehlermeldung; Label, Hinweis und Fehler sind mit dem Steuerelement verknüpft. */
export function FormField({ label, hint, error, children }: FormFieldProps) {
  const id = useId();
  const ids = [hint === undefined ? null : id + '-hint', error === undefined ? null : id + '-err'].filter((x): x is string => x !== null);
  return (
    <div className="field">
      <label className="field__label" htmlFor={id}>
        {label}
      </label>
      {children({ id, 'aria-describedby': ids.length > 0 ? ids.join(' ') : undefined, 'aria-invalid': error === undefined ? undefined : true })}
      {hint !== undefined && (
        <span id={id + '-hint'} className="field__hint">
          {hint}
        </span>
      )}
      {error !== undefined && (
        <span id={id + '-err'} className="field__error">
          {error}
        </span>
      )}
    </div>
  );
}
