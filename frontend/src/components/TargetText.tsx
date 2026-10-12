/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { isHostHidden, targetLabel } from '../lib/jobs';
import { t } from '../i18n';

/** Ziel eines HTTP-Jobs als reiner Text (nie als Link). Bei verborgenem Host mit kurzem Hinweis auf die Einstellung. */
export function TargetText({ target }: { target: string }) {
  return (
    <>
      {targetLabel(target)}
      {isHostHidden(target) && <span className="hint hint--inline"> {t('· Host verborgen (Einstellung)')}</span>}
    </>
  );
}
