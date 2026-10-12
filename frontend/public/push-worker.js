/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
self.addEventListener('push', event => {
  let message = {};
  try { message = event.data ? event.data.json() : {}; } catch {}
  if (!message || typeof message !== 'object') message = {};
  const title = typeof message.title === 'string' ? message.title : 'Meridian';
  const body = typeof message.body === 'string' ? message.body : 'Meridian notification';
  let url = '/app/';
  try {
    const target = new URL(message.url || '/app/', self.location.origin);
    if (target.origin === self.location.origin && target.pathname.startsWith('/app/')) url = target.href;
  } catch {}
  event.waitUntil(self.registration.showNotification(title, { body, tag: typeof message.tag === 'string' ? message.tag : undefined, icon: '/app/favicon.svg', data: { url } }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil((async () => {
    const url = event.notification.data?.url || '/app/';
    const target = new URL(url, self.location.origin);
    if (target.origin !== self.location.origin) return;
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const existing = windows.find(client => client.url === target.href);
    if (existing) await existing.focus();
    else await self.clients.openWindow(target.href);
  })());
});
