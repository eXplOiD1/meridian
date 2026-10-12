/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useCallback, useEffect, useState } from 'react';
import { request } from '../lib/api';
import { t } from '../i18n';
import { Alert } from './Alert';

interface Device { id: number; label: string; fingerprint: string }
interface Devices { application_server_key: string; devices: Device[] }

function available(): boolean {
  return window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

function keyBytes(key: string): Uint8Array<ArrayBuffer> {
  const decoded = atob(key.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - key.length % 4) % 4));
  return Uint8Array.from(decoded, c => c.charCodeAt(0));
}

async function endpointFingerprint(endpoint: string): Promise<string> {
  const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint));
  return Array.from(new Uint8Array(hash), b => b.toString(16).padStart(2, '0')).join('');
}

async function active(registration: ServiceWorkerRegistration): Promise<void> {
  if (registration.active) return;
  const worker = registration.installing ?? registration.waiting;
  if (!worker) throw new Error(t('Browser-Dienst konnte nicht gestartet werden.'));
  await new Promise<void>((resolve, reject) => {
    const timer = window.setTimeout(() => { worker.removeEventListener('statechange', changed); reject(new Error(t('Browser-Dienst konnte nicht gestartet werden.'))); }, 20000);
    function changed(): void {
      if (worker?.state === 'activated' || worker?.state === 'redundant') {
        clearTimeout(timer); worker.removeEventListener('statechange', changed);
        if (worker.state === 'activated') resolve(); else reject(new Error(t('Browser-Dienst konnte nicht gestartet werden.')));
      }
    }
    worker.addEventListener('statechange', changed);
    changed();
  });
}

export function BrowserPushDevices({ channelId, csrf }: { channelId: number; csrf: string }) {
  const [devices, setDevices] = useState<Device[]>([]);
  const [currentDeviceId, setCurrentDeviceId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [label, setLabel] = useState(t('Dieser Browser'));
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [permission, setPermission] = useState<NotificationPermission | 'unsupported'>(available() ? Notification.permission : 'unsupported');
  const scope = '/app/push/' + channelId + '/';
  const api = '/api/notifications/channels/' + channelId + '/browser';
  const load = useCallback(async () => {
    const data = await request<Devices>('GET', api);
    setDevices(data.devices);
    let current: number | null = null;
    if (available() && Notification.permission === 'granted') {
      const registration = await navigator.serviceWorker.getRegistration(new URL(scope, location.origin).href);
      const subscription = registration?.scope === new URL(scope, location.origin).href ? await registration.pushManager.getSubscription() : null;
      if (subscription) {
        const fingerprint = await endpointFingerprint(subscription.endpoint);
        current = data.devices.find(device => device.fingerprint === fingerprint)?.id ?? null;
      }
    }
    setCurrentDeviceId(current);
  }, [api, scope]);
  useEffect(() => { setLoading(true); void load().catch(() => setMessage(t('Browser-Geräte konnten nicht geladen werden.'))).finally(() => setLoading(false)); }, [load]);

  async function connect(): Promise<void> {
    if (!available()) { setMessage(t('Browser-Push benötigt HTTPS und einen unterstützten Chrome- oder Firefox-Browser.')); return; }
    setBusy(true); setMessage(null);
    try {
      const result = await Notification.requestPermission();
      setPermission(result);
      if (result !== 'granted') throw new Error(t('Benachrichtigungen sind im Browser nicht erlaubt. Bitte in den Website-Einstellungen freigeben.'));
      const data = await request<Devices>('GET', api);
      const key = keyBytes(data.application_server_key);
      try { await crypto.subtle.importKey('raw', key, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify']); }
      catch { throw new Error(t('Der gespeicherte Browser-Push-Schlüssel ist ungültig. Bitte den Browser-Kanal neu anlegen. Die Browser-Freigabe bleibt dabei erhalten.')); }
      const registration = await navigator.serviceWorker.register('/app/push-worker.js?channel=' + channelId, { scope, updateViaCache: 'none' });
      await active(registration);
      let subscription = await registration.pushManager.getSubscription();
      const previousKey = subscription?.options.applicationServerKey;
      if (subscription && previousKey && Array.from(new Uint8Array(previousKey)).join(',') !== Array.from(key).join(',')) { await subscription.unsubscribe(); subscription = null; }
      subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
      await request('POST', api, { csrf, body: { label, subscription: subscription.toJSON() } });
      await load(); setMessage(t('Dieser Browser ist verbunden. Eine Testnachricht kannst du am Kanal auslösen.'));
    } catch (error) {
      const pushServiceError = error instanceof Error && /push service|push server/i.test(error.message);
      setMessage(pushServiceError ? t('Browser-Push-Registrierung fehlgeschlagen: {error}. In Chrome zeigt chrome://gcm-internals im Registration Log die genaue Antwort des Push-Dienstes, zum Beispiel TOO_MANY_REGISTRATIONS. Die Browser-Freigabe allein bestätigt noch keine Verbindung.', { error: error.message }) : error instanceof Error ? error.message : t('Browser konnte nicht verbunden werden.'));
    }
    finally { setBusy(false); }
  }

  async function remove(device: Device): Promise<void> {
    setBusy(true); setMessage(null);
    try {
      await request('DELETE', api + '/' + device.id, { csrf });
      if (available()) {
        const registration = await navigator.serviceWorker.getRegistration(new URL(scope, location.origin).href);
        const subscription = await registration?.pushManager.getSubscription();
        if (subscription) {
          const fingerprint = await endpointFingerprint(subscription.endpoint);
          if (fingerprint === device.fingerprint) { await subscription.unsubscribe(); await registration?.unregister(); }
        }
      }
      await load();
    } catch { setMessage(t('Browser-Gerät konnte nicht entfernt werden.')); }
    finally { setBusy(false); }
  }

  const connected = currentDeviceId !== null && permission === 'granted';
  return <fieldset className="statusitems">
    <legend className="field__label">{t('Browser-Geräte')}</legend>
    <p className="hint">{t('Chrome und Firefox: Jeder Browser muss die Benachrichtigungen selbst erlauben. Auf Desktop muss der Browser weiterhin laufen. Nur deine eigenen Geräte werden angezeigt.')}</p>
    {message && <Alert tone="info">{message}</Alert>}
    {permission === 'denied' && <Alert tone="warn">{t('Die Freigabe wurde im Browser abgelehnt. Öffne die Website-Einstellungen über das Symbol neben der Adresse, erlaube Benachrichtigungen und klicke danach erneut auf „Diesen Browser verbinden“. Der Browser darf eine abgelehnte Freigabe nicht erneut automatisch abfragen.')}</Alert>}
    <p className="hint">{t('Browser-Freigabe')}: {permission === 'granted' ? t('Erlaubt') : permission === 'denied' ? t('Abgelehnt') : permission === 'unsupported' ? t('Nicht unterstützt') : t('Noch nicht erteilt')}</p>
    {loading ? <p role="status">{t('Browser-Verbindung wird geprüft …')}</p> : connected ? <p role="status">{t('Dieser Browser ist bereits verbunden.')}</p> : <>
      <label className="field"><span className="field__label">{t('Gerätename')}</span><input className="input" value={label} maxLength={60} onChange={e => setLabel(e.target.value)} /></label>
      <button type="button" className="btn btn--solid" disabled={busy || !label.trim()} onClick={() => void connect()}>{t('Diesen Browser verbinden')}</button>
    </>}
    <button type="button" className="btn btn--ghost" disabled={busy || loading || !connected} onClick={() => { setBusy(true); void request('POST', api + '/test', { csrf }).then(() => setMessage(t('Testnachricht eingereiht.'))).catch(error => setMessage(error instanceof Error ? error.message : t('Browser konnte nicht verbunden werden.'))).finally(() => setBusy(false)); }}>{t('Testnachricht')}</button>
    {devices.map(device => <div className="row" key={device.id}><span>{device.label}</span><button type="button" className="btn btn--ghost" disabled={busy} onClick={() => void remove(device)}>{t('Entfernen')}</button></div>)}
  </fieldset>;
}
