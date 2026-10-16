/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useId, useLayoutEffect, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { t } from '../i18n';

interface Props {
  open: boolean;
  title: string;
  onClose: () => void;
  busy?: boolean;
  children: ReactNode;
}

/** Native dialog keeps focus inside the editor and makes the overview inert. */
export function Modal({ open, title, onClose, busy = false, children }: Props) {
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useId();
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
        <button type="button" className="btn btn--ghost btn--small" disabled={busy} onClick={onClose} aria-label={t('Schließen')}>×</button>
      </header>
      <div className="editor-modal__body">{children}</div>
      <footer className="editor-modal__footer">
        <button type="button" className="btn btn--ghost" disabled={busy} onClick={onClose}>{t('Abbrechen')}</button>
      </footer>
    </dialog>, document.body,
  );
}
