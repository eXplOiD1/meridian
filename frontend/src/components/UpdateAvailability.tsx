/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { request } from '../lib/api';
import { t } from '../i18n';

interface AvailabilityStatus { configured: boolean; available?: boolean; busy?: boolean; pending?: boolean }
const UpdateAvailabilityContext = createContext({ available: false, report: (_status: AvailabilityStatus) => {} });
export const useUpdateAvailability = () => useContext(UpdateAvailabilityContext);

export function UpdateAvailabilityProvider({ enabled, children }: { enabled: boolean; children: ReactNode }) {
  const [available, setAvailable] = useState(false);
  const report = useCallback((status: AvailabilityStatus) => {
    setAvailable(Boolean(status.configured && status.available && !status.busy && !status.pending));
  }, []);
  useEffect(() => {
    if (!enabled) { setAvailable(false); return; }
    let cancelled = false;
    let loading = false;
    async function load() {
      if (loading || document.visibilityState === 'hidden') return;
      loading = true;
      try {
        const status = await request<AvailabilityStatus>('GET', '/api/admin/updates');
        if (!cancelled) report(status);
      } catch { if (!cancelled) setAvailable(false); }
      finally { loading = false; }
    }
    void load();
    const timer = window.setInterval(() => void load(), 60000);
    const onVisible = () => { if (document.visibilityState === 'visible') void load(); };
    document.addEventListener('visibilitychange', onVisible);
    return () => { cancelled = true; clearInterval(timer); document.removeEventListener('visibilitychange', onVisible); };
  }, [enabled, report]);
  return <UpdateAvailabilityContext.Provider value={{ available: enabled && available, report }}>{children}</UpdateAvailabilityContext.Provider>;
}

export function UpdateAvailableDot() {
  return <span className="update-available-dot" role="img" aria-label={t('Update verfügbar.')} title={t('Update verfügbar.')} />;
}
