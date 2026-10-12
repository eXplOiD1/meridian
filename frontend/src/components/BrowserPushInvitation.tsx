/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useEffect, useState } from 'react';
import { request } from '../lib/api';
import { t } from '../i18n';
import { Alert } from './Alert';

export function BrowserPushInvitation({ userId }: { userId: number }) {
  const [show, setShow] = useState(false);
  useEffect(() => {
    let cancelled = false;
    async function check() {
      const data = await request<{ channels: { id: number }[] }>('GET', '/api/account/browser-channels');
      if (!data.channels.length || cancelled) return;
      for (const channel of data.channels) {
        const registration = 'serviceWorker' in navigator ? await navigator.serviceWorker.getRegistration(new URL('/app/push/' + channel.id + '/', location.origin).href) : null;
        const subscription = await registration?.pushManager.getSubscription();
        let connected = false;
        if (subscription && 'Notification' in window && Notification.permission === 'granted') {
          const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(subscription.endpoint));
          const hash = Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('');
          const devices = await request<{ devices: { fingerprint: string }[] }>('GET', '/api/notifications/channels/' + channel.id + '/browser');
          connected = devices.devices.some(device => device.fingerprint === hash);
        }
        if (!connected && !cancelled) { setShow(true); return; }
      }
    }
    void check().catch(() => {});
    return () => { cancelled = true; };
  }, [userId]);
  if (!show) return null;
  return <Alert tone="info"><span>{t('Browser-Benachrichtigungen sind verfügbar. Verbinde diesen Browser unter „Mein Konto“, wenn du Meldungen zu deinen freigegebenen Aufgaben erhalten möchtest.')}</span> <a className="btn btn--ghost" href="#/konto">{t('Browser-Freigabe einrichten')}</a> <button className="btn btn--ghost" onClick={() => setShow(false)}>{t('Später')}</button></Alert>;
}
