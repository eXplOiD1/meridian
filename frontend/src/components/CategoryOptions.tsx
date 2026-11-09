/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import type { CategoryRef } from '../types';
import { t } from '../i18n';

/** Einträge einer Kategorie-Auswahl; ohne Kategorien steht dort nur „Keine“. */
export function CategoryOptions({ categories }: { categories: CategoryRef[] }) {
  return (
    <>
      {categories.length === 0 && <option value="">{t('Keine')}</option>}
      {categories.map((c) => (
        <option key={c.id} value={String(c.id)}>
          {c.name}
        </option>
      ))}
    </>
  );
}
