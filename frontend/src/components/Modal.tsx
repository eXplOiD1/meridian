/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { createContext, useContext, useId, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { t } from '../i18n';

interface Props {
  open: boolean;
  title: string;
  onClose: () => void;
  busy?: boolean;
  children: ReactNode;
}

const ActionsContext = createContext<{
  target: HTMLDivElement | null;
  onClose: () => void;
  busy: boolean;
  setHasActions: (present: boolean) => void;
} | null>(null);

/** Only the action row moves; submit buttons must name their original form via the form attribute. */
export function ModalActions({ children, cancel, className = 'row', showCancel = true }: { children: ReactNode; cancel?: ReactNode; className?: string; showCancel?: boolean }) {
  const modal = useContext(ActionsContext);
  const register = modal?.setHasActions;
  useLayoutEffect(() => {
    register?.(true);
    return () => register?.(false);
  }, [register]);
  if (!modal) return <div className={className}>{children}{cancel}</div>;
  if (!modal.target) return null;
  return createPortal(<div className="row modal-actions">
    {children}
    {showCancel && <button type="button" className="btn btn--ghost" disabled={modal.busy} onClick={modal.onClose}>{t('Abbrechen')}</button>}
  </div>, modal.target);
}

/** Native dialog keeps focus inside the editor and makes the overview inert. */
export function Modal({ open, title, onClose, busy = false, children }: Props) {
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useId();
  const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
  const [hasActions, setHasActions] = useState(false);
  useLayoutEffect(() => {
    if (!open || !dialog.current) return;
    const element = dialog.current;
    const previous = document.activeElement;
    element.showModal();
    document.body.classList.add('modal-open');
    return () => {
      element.close();
      document.body.classList.remove('modal-open');
      if (previous instanceof HTMLElement && previous.isConnected) previous.focus();
    };
  }, [open]);
  if (!open) return null;
  return createPortal(
    <dialog ref={dialog} className="editor-modal" aria-labelledby={heading} aria-modal="true"
      onCancel={event => { event.preventDefault(); if (!busy) onClose(); }}
      onKeyDown={event => {
        if (event.key !== 'Tab') return;
        const fields = [...event.currentTarget.querySelectorAll<HTMLElement>('button, a[href], input, select, textarea, [tabindex]')]
          .filter(field => field.tabIndex >= 0 && !field.matches(':disabled') && field.getClientRects().length > 0);
        const first = fields[0];
        const last = fields.at(-1);
        if (!first || !last) { event.preventDefault(); return; }
        const active = document.activeElement;
        if (event.shiftKey && (active === first || !fields.includes(active as HTMLElement))) {
          event.preventDefault(); last.focus();
        } else if (!event.shiftKey && (active === last || !fields.includes(active as HTMLElement))) {
          event.preventDefault(); first.focus();
        }
      }}>
      <header className="editor-modal__header">
        <h2 id={heading} tabIndex={-1} autoFocus>{title}</h2>
        <button type="button" className="btn btn--ghost editor-modal__close" disabled={busy} onClick={onClose} aria-label={t('Schließen')}>×</button>
      </header>
      <div className="editor-modal__body">
        <ActionsContext.Provider value={{ target: actionsTarget, onClose, busy, setHasActions }}>{children}</ActionsContext.Provider>
      </div>
      <footer className="editor-modal__footer">
        <div ref={setActionsTarget} />
        {!hasActions && <div className="row modal-actions"><button type="button" className="btn btn--ghost" disabled={busy} onClick={onClose}>{t('Abbrechen')}</button></div>}
      </footer>
    </dialog>, document.body,
  );
}
