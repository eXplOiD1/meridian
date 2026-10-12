/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { t } from '../i18n';

/**
 * Kurzbeschreibung mit aufklappbarem Rest: Der erste Satz steht immer da, der Rest unter „Mehr erfahren“.
 * Der Text bleibt vollständig erhalten; es wird nur aufgeteilt.
 */
export function ExpandableLead({ text }: { text: string }) {
  const cut = text.search(/[.!?](\s|$)/);
  if (cut < 0 || cut >= text.length - 2 || text.length < 120) {
    return <p className="card__lead">{text}</p>;
  }
  const first = text.slice(0, cut + 1);
  const rest = text.slice(cut + 1).trim();
  if (rest === '') {
    return <p className="card__lead">{text}</p>;
  }
  return (
    <div className="lead">
      <p className="card__lead">{first}</p>
      <details className="lead__more">
        <summary>{t('Mehr erfahren')}</summary>
        <p className="card__lead">{rest}</p>
      </details>
    </div>
  );
}
