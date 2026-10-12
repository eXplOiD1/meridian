/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { t } from '../i18n';
/** Wortmarke mit der Uhr aus dem Klickdummy (nur CSS/SVG, keine Bilddatei). */
export function Brand({ subtitle = 'Zeitplaner' }: { subtitle?: string }) {
  return (
    <div className="brand">
      <svg className="brand__clock" viewBox="0 0 34 34" aria-hidden="true" focusable="false">
        <circle cx="17" cy="17" r="15" fill="none" stroke="var(--signal)" strokeWidth="2" />
        <path d="M17 7v10l6 4" fill="none" stroke="var(--signal)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        <circle cx="17" cy="17" r="2.2" fill="var(--signal)" />
      </svg>
      <div>
        <span className="brand__name">{t('MERIDIAN')}</span>
        <span className="brand__sub">{t(subtitle)}</span>
      </div>
    </div>
  );
}
