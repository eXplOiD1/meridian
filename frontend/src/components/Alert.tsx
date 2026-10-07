import type { ReactNode } from 'react';

/** Meldungen immer als Text; err/warn werden Screenreadern sofort vorgelesen. */
export function Alert({ tone, children }: { tone: 'err' | 'ok' | 'warn' | 'info'; children: ReactNode }) {
  return (
    <div className={'alert alert--' + tone} role={tone === 'err' || tone === 'warn' ? 'alert' : 'status'}>
      {children}
    </div>
  );
}
