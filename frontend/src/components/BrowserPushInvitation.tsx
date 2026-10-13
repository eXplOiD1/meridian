/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useEffect, useState } from 'react';
import { request } from '../lib/api';
import { t } from '../i18n';
import { Alert } from './Alert';

const dismissedUsers = new Set<number>();
const dismissalKey = (userId: number) => `meridian.browser-push-invitation.dismissed.${userId}`;

function isDismissed(userId: number) {
  if (dismissedUsers.has(userId)) return true;
  try { return localStorage.getItem(dismissalKey(userId)) === '1'; }
  catch { return false; }
}

export function BrowserPushInvitation({ userId }: { userId: number }) {
  const [show, setShow] = useState(false);
  function dismiss() {
    dismissedUsers.add(userId);
    try { localStorage.setItem(dismissalKey(userId), '1'); } catch { /* Keep the dismissal in memory when browser storage is unavailable. */ }
    setShow(false);
  }
  useEffect(() => {
    let cancelled = false;
    setShow(false);
    if (isDismissed(userId)) return;
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
        if (!connected && !cancelled && !isDismissed(userId)) { setShow(true); return; }
      }
    }
    void check().catch(() => {});
    return () => { cancelled = true; };
  }, [userId]);
  if (!show) return null;
  return <Alert tone="info"><span>{t('Browser-Benachrichtigungen sind verfügbar. Verbinde diesen Browser unter „Mein Konto“, wenn du Meldungen zu deinen freigegebenen Aufgaben erhalten möchtest.')}</span> <a className="btn btn--ghost" href="#/konto">{t('Browser-Freigabe einrichten')}</a> <button className="btn btn--ghost" onClick={dismiss}>{t('Später')}</button></Alert>;
}
