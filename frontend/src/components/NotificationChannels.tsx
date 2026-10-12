/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from './Alert';
import { BrowserPushDevices } from './BrowserPushDevices';
import { Confirm } from './Confirm';
import { FormField } from './FormField';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import type { ChannelKind, NotificationChannel } from '../types';
import { t } from '../i18n';

interface ChannelForm {
  type: ChannelKind;
  language: 'ui' | 'de' | 'en';
  name: string;
  enabled: boolean;
  host: string;
  port: string;
  encryption: 'none' | 'starttls' | 'ssl';
  username: string;
  password: string;
  clearSecret: boolean;
  from: string;
  fromName: string;
  to: string;
  server: string;
  topic: string;
  priority: string;
  token: string;
  url: string;
  chatId: string;
  userKey: string;
  service: string;
  contact: string;
}

const EMPTY: ChannelForm = {
  type: 'email',
  language: 'ui',
  name: '',
  enabled: true,
  host: '',
  port: '587',
  encryption: 'starttls',
  username: '',
  password: '',
  clearSecret: false,
  from: '',
  fromName: 'Meridian',
  to: '',
  server: 'https://ntfy.sh',
  topic: '',
  priority: '3',
  token: '',
  url: '',
  chatId: '',
  userKey: '',
  service: 'persistent_notification.create',
  contact: '',
};

const TYPE_LABEL: Record<NotificationChannel['type'], string> = { email: 'Email (SMTP)', ntfy: 'ntfy', webhook: 'Webhook', telegram: 'Telegram', discord: 'Discord', slack: 'Slack', pushover: 'Pushover', homeassistant: 'Home Assistant', browser: 'Browser (Chrome / Firefox)' };
const ENCRYPTION_LABEL = { none: 'Keine (nur im vertrauenswürdigen Heimnetz)', starttls: 'STARTTLS (meist Port 587)', ssl: 'SSL/TLS (meist Port 465)' } as const;
const STATUS_LABEL: Record<string, string> = { pending: 'wartet auf Zustellung', sent: 'zugestellt', failed: 'fehlgeschlagen' };

function fromChannel(channel: NotificationChannel): ChannelForm {
  const c = channel.config;
  return {
    ...EMPTY,
    type: channel.type,
    language: c.language ?? 'ui',
    name: channel.name,
    enabled: channel.enabled,
    host: c.host ?? '',
    port: String(c.port ?? 587),
    encryption: c.encryption ?? 'starttls',
    username: c.username ?? '',
    from: c.from ?? '',
    fromName: c.from_name ?? '',
    to: (c.to ?? []).join('\n'),
    server: c.server ?? 'https://ntfy.sh',
    topic: c.topic ?? '',
    priority: String(c.priority ?? 3),
    url: c.url ?? '',
    chatId: c.chat_id ?? '',
    service: c.service ?? 'persistent_notification.create',
    contact: c.contact ?? '',
  };
}

/** Der Request-Körper: Passwort/Token nur, wenn neu eingegeben (`null` löscht, fehlend lässt es unverändert). */
function bodyOf(form: ChannelForm): Record<string, unknown> {
  const body: Record<string, unknown> = { type: form.type, name: form.name, enabled: form.enabled, language: form.language };
  if (form.type === 'email') {
    body.host = form.host.trim();
    body.port = /^\d+$/.test(form.port) ? Number.parseInt(form.port, 10) : form.port;
    body.encryption = form.encryption;
    body.username = form.username.trim() === '' ? null : form.username.trim();
    body.from = form.from.trim();
    body.from_name = form.fromName.trim() === '' ? null : form.fromName.trim();
    body.to = form.to.split(/[\n,;]+/).map((part) => part.trim()).filter((part) => part !== '');
    if (form.clearSecret) {
      body.password = null;
    } else if (form.password !== '') {
      body.password = form.password;
    }
  } else if (['discord', 'slack', 'pushover', 'homeassistant', 'browser'].includes(form.type)) {
    if (form.type === 'homeassistant') { body.server = form.server.trim(); body.service = form.service.trim(); }
    if (form.type === 'browser') body.contact = form.contact.trim();
    if (form.type === 'pushover') body.priority = /^-?\d+$/.test(form.priority) ? Number.parseInt(form.priority, 10) : form.priority;
    if (form.type !== 'browser') {
      if (form.clearSecret) body.token = null;
      else if (form.token !== '') { body.token = form.token; if (form.type === 'pushover') body.user_key = form.userKey.trim(); }
      else if (form.type === 'pushover' && form.userKey !== '') body.user_key = form.userKey.trim();
    }
  } else if (form.type === 'webhook') {
    body.url = form.url.trim();
    if (form.clearSecret) {
      body.token = null;
    } else if (form.token !== '') {
      body.token = form.token;
    }
  } else if (form.type === 'telegram') {
    body.chat_id = form.chatId.trim();
    if (form.clearSecret) {
      body.token = null;
    } else if (form.token !== '') {
      body.token = form.token;
    }
  } else {
    body.server = form.server.trim();
    body.topic = form.topic.trim();
    body.priority = /^\d+$/.test(form.priority) ? Number.parseInt(form.priority, 10) : form.priority;
    if (form.clearSecret) {
      body.token = null;
    } else if (form.token !== '') {
      body.token = form.token;
    }
  }
  return body;
}

function summary(channel: NotificationChannel): string {
  const c = channel.config;
  switch (channel.type) {
    case 'email':
      return (c.host ?? '') + ':' + String(c.port ?? '') + ' · ' + t('an {n} Empfänger', { n: (c.to ?? []).length });
    case 'webhook':
      return c.url ?? '';
    case 'browser': return t('Browser-Geräte');
    case 'homeassistant': return (c.server ?? '') + ' · ' + (c.service ?? '');
    case 'discord': case 'slack': case 'pushover': return TYPE_LABEL[channel.type];
    case 'telegram':
      return 'Chat ' + (c.chat_id ?? '');
    default:
      return (c.server ?? '') + ' · Thema ' + (c.topic ?? '');
  }
}

/**
 * Kanäle der Benachrichtigungen (E-Mail über SMTP, ntfy). Passwort und Token werden nur geschrieben, nie angezeigt:
 * Das Feld bleibt leer; „leer lassen“ behält den gespeicherten Wert. Nur für Benutzer mit dem Recht „Einstellungen ändern“.
 */
export function NotificationChannels({ csrf }: { csrf: string }) {
  const [channels, setChannels] = useState<NotificationChannel[] | null>(null);
  const [max, setMax] = useState(20);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [form, setForm] = useState<ChannelForm>(EMPTY);
  const [editing, setEditing] = useState<NotificationChannel | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async (): Promise<void> => {
    try {
      const data = await request<{ channels: NotificationChannel[]; max_channels: number }>('GET', '/api/notifications/channels');
      setChannels(data.channels);
      setMax(data.max_channels);
      setLoadError(null);
    } catch (caught) {
      setLoadError(errorMessage(caught));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  function set<K extends keyof ChannelForm>(key: K, value: ChannelForm[K]): void {
    setForm((current) => ({ ...current, [key]: value }));
  }

  function reset(): void {
    setForm(EMPTY);
    setEditing(null);
    setErrors({});
    setFormError(null);
  }

  async function submit(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setFormError(null);
    setNotice(null);
    try {
      if (editing === null) {
        const created = await request<{ channel: NotificationChannel }>('POST', '/api/notifications/channels', { csrf, body: bodyOf(form) });
        if (form.type === 'browser') {
          setEditing(created.channel); setForm(fromChannel(created.channel));
          setNotice(t('Kanal gespeichert. Jetzt „Diesen Browser verbinden“ wählen und die Freigabe im Browser bestätigen. Weitere Nutzer verbinden ihren Browser unter „Mein Konto“.'));
          await load(); return;
        }
        setNotice(t('Kanal angelegt. Mit „Testnachricht“ prüfst du, ob er funktioniert.'));
      } else {
        await request('PUT', '/api/notifications/channels/' + String(editing.id), { csrf, body: bodyOf(form) });
        setNotice('Kanal gespeichert.');
      }
      reset();
      await load();
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 422) {
        setErrors(caught.fields);
        setFormError(t('Bitte die markierten Felder korrigieren.'));
      } else {
        setFormError(errorMessage(caught));
      }
    } finally {
      setBusy(false);
    }
  }

  async function act(label: string, run: () => Promise<unknown>): Promise<void> {
    setBusy(true);
    setNotice(null);
    setFormError(null);
    try {
      await run();
      setNotice(label);
      await load();
    } catch (caught) {
      setFormError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  const isEmail = form.type === 'email';
  const secretLabel = isEmail ? t('Passwort') : form.type === 'telegram' ? 'Bot-Token' : 'Zugriffstoken (optional)';
  const stored = editing?.has_secret === true;

  return (
    <section className="card" aria-label={t('Benachrichtigungen')}>
      <h2>{t('Benachrichtigungen')}</h2>
      <p className="card__lead">
        {t('Kanäle, über die Meridian bei Fehlschlägen und Wiederherstellung Bescheid gibt. Welche Jobs melden und an welche Kanäle, stellst du im Job unter „Benachrichtigungen“ ein. Passwörter und Token werden verschlüsselt gespeichert und nie wieder angezeigt.')}</p>
      <Alert tone="info">
        {t('Zugestellt wird vom Dienst')} <code>worker-http</code>{t(', innerhalb einer Minute. Der Server eines Kanals wird wie bei HTTP-Jobs aufgelöst und geprüft; Adressen im eigenen Netz sind für genau diesen Kanal erlaubt, Metadaten-Dienste und Meridians eigene Dienste nie.')}
      </Alert>
      {loadError !== null && <Alert tone="err">{loadError}</Alert>}
      {notice !== null && <Alert tone="ok">{notice}</Alert>}
      {formError !== null && <Alert tone="err">{formError}</Alert>}
      {channels !== null && channels.length === 0 && <p className="card__lead">{t('Noch kein Kanal angelegt. Ohne Kanal verschickt Meridian keine Benachrichtigungen.')}</p>}
      {channels !== null && channels.length > 0 && (
        <div className="table-wrap">
          <table className="table">
            <caption className="visually-hidden">{t('Benachrichtigungskanäle')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('Name')}</th>
                <th scope="col">{t('Art')}</th>
                <th scope="col">{t('Ziel')}</th>
                <th scope="col">{t('Letzte Zustellung')}</th>
                <th scope="col">{t('Aktionen')}</th>
              </tr>
            </thead>
            <tbody>
              {channels.map((channel) => (
                <tr key={channel.id}>
                  <td>
                    {channel.name}
                    {!channel.enabled && <span className="muted"> {t('(aus)')}</span>}
                  </td>
                  <td>{t(TYPE_LABEL[channel.type])}</td>
                  <td className="mono">{summary(channel)}</td>
                  <td>
                    {channel.last_delivery == null ? (
                      <span className="muted">{t('noch keine')}</span>
                    ) : (
                      <>
                        {t(STATUS_LABEL[channel.last_delivery.status] ?? channel.last_delivery.status)} · {formatTime(channel.last_delivery.at)}
                        {channel.last_delivery.error !== null && <div className="field__error">{channel.last_delivery.error}</div>}
                      </>
                    )}
                  </td>
                  <td>
                    <div className="row">
                      <button
                        type="button"
                        className="btn btn--ghost btn--small"
                        disabled={busy}
                        onClick={() => {
                          setEditing(channel);
                          setForm(fromChannel(channel));
                          setErrors({});
                          setNotice(null);
                        }}
                      >
                        {t('Bearbeiten')}</button>
                      <button type="button" className="btn btn--ghost btn--small" disabled={busy} onClick={() => void act('Testnachricht eingereiht. Das Ergebnis erscheint hier innerhalb einer Minute.', () => request('POST', '/api/notifications/channels/' + String(channel.id) + '/test', { csrf }))}>
                        {t('Testnachricht')}</button>
                      <Confirm label={t('Löschen')} question={t('Kanal löschen? Er verschwindet auch aus den Jobs.')} confirmLabel={t('Ja, löschen')} busy={busy} accessibleName={t('Kanal {name} löschen', { name: channel.name })} onConfirm={() => void act(t('Kanal gelöscht.'), () => request('DELETE', '/api/notifications/channels/' + String(channel.id), { csrf }))} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <form className="form targets__form" onSubmit={(event) => void submit(event)} noValidate>
        <h3>{editing === null ? t('Kanal hinzufügen') : t('Kanal bearbeiten:') + ' ' + editing.name}</h3>
        {(channels?.length ?? 0) >= max && editing === null && <Alert tone="warn">{t('Es sind schon {n} Kanäle angelegt. Nicht benötigte zuerst löschen.', { n: max })}</Alert>}
        <div className="grid-2">
          <FormField label={t('Art')} error={errors.type} hint={editing !== null ? t('Die Art eines Kanals lässt sich nicht ändern.') : undefined}>
            {(aria) => (
              <select {...aria} className="input" name="channel-type" value={form.type} disabled={editing !== null} onChange={(e) => { set('type', e.target.value as ChannelKind); set('token', ''); set('userKey', ''); set('priority', e.target.value === 'pushover' ? '0' : '3'); set('server', e.target.value === 'homeassistant' ? '' : 'https://ntfy.sh'); }}>
                <option value="email">{TYPE_LABEL.email}</option>
                <option value="ntfy">{TYPE_LABEL.ntfy}</option>
                <option value="webhook">{TYPE_LABEL.webhook}</option>
                <option value="telegram">{TYPE_LABEL.telegram}</option>
                {(["discord", "slack", "pushover", "homeassistant", "browser"] as ChannelKind[]).map(type => <option key={type} value={type}>{TYPE_LABEL[type]}</option>)}
              </select>
            )}
          </FormField>
          <FormField label={t('Name')} error={errors.name} hint={t('Wie der Kanal im Job-Editor heißt.')}>
            {(aria) => <input {...aria} className="input" name="channel-name" value={form.name} maxLength={60} onChange={(e) => set('name', e.target.value)} />}
          </FormField>
        </div>
        <FormField label={t('Benachrichtigungssprache')} error={errors.language} hint={t('Standard: folgt der gespeicherten UI-Sprache deines Kontos.')}>
          {(aria) => <select {...aria} className="input" name="notification-language" value={form.language} onChange={(e) => set('language', e.target.value as 'ui' | 'de' | 'en')}>
            <option value="ui">{t('Wie meine UI-Sprache')}</option>
            <option value="de">Deutsch</option>
            <option value="en">English</option>
          </select>}
        </FormField>
        {isEmail ? (
          <>
            <div className="grid-2">
              <FormField label={t('SMTP-Server')} error={errors.host} hint={t('Rechnername oder IP-Adresse, z. B. smtp.example.org.')}>
                {(aria) => <input {...aria} className="input" name="smtp-host" value={form.host} autoComplete="off" onChange={(e) => set('host', e.target.value)} />}
              </FormField>
              <FormField label={t('Port')} error={errors.port}>
                {(aria) => <input {...aria} className="input" name="smtp-port" inputMode="numeric" value={form.port} onChange={(e) => set('port', e.target.value)} />}
              </FormField>
            </div>
            <FormField label={t('Verschlüsselung')} error={errors.encryption} hint={t('Das Zertifikat des Servers wird immer geprüft.')}>
              {(aria) => (
                <select {...aria} className="input" name="smtp-encryption" value={form.encryption} onChange={(e) => set('encryption', e.target.value as ChannelForm['encryption'])}>
                  {(Object.keys(ENCRYPTION_LABEL) as Array<keyof typeof ENCRYPTION_LABEL>).map((key) => (
                    <option key={key} value={key}>
                      {t(ENCRYPTION_LABEL[key])}
                    </option>
                  ))}
                </select>
              )}
            </FormField>
            <div className="grid-2">
              <FormField label={t('Benutzer (leer = ohne Anmeldung)')} error={errors.username}>
                {(aria) => <input {...aria} className="input" name="smtp-username" value={form.username} autoComplete="off" onChange={(e) => set('username', e.target.value)} />}
              </FormField>
              <FormField label={secretLabel} error={errors.password} hint={stored ? t('Ein Passwort ist gespeichert. Leer lassen, um es zu behalten.') : undefined}>
                {(aria) => <input {...aria} className="input" type="password" name="smtp-password" value={form.password} autoComplete="new-password" disabled={form.clearSecret} onChange={(e) => set('password', e.target.value)} />}
              </FormField>
            </div>
            <div className="grid-2">
              <FormField label={t('Absender (E-Mail-Adresse)')} error={errors.from}>
                {(aria) => <input {...aria} className="input" name="smtp-from" value={form.from} autoComplete="off" onChange={(e) => set('from', e.target.value)} />}
              </FormField>
              <FormField label={t('Absendername (optional)')} error={errors.from_name}>
                {(aria) => <input {...aria} className="input" name="smtp-from-name" value={form.fromName} maxLength={60} onChange={(e) => set('fromName', e.target.value)} />}
              </FormField>
            </div>
            <FormField label={t('Empfänger')} error={errors.to} hint={t('Eine Adresse pro Zeile, höchstens 10.')}>
              {(aria) => <textarea {...aria} className="input" name="smtp-to" rows={3} value={form.to} onChange={(e) => set('to', e.target.value)} />}
            </FormField>
          </>
        ) : ['discord', 'slack', 'pushover', 'homeassistant', 'browser'].includes(form.type) ? (
          <>
            {form.type === 'browser' ? <>
              <FormField label={t('Kontaktadresse des Betreibers')} error={errors.contact} hint={t('Deine erreichbare E-Mail-Adresse als Betreiber, z. B. admin@example.org. Sie wird dem Push-Dienst als Kontakt mitgegeben. Hierhin werden keine Benachrichtigungen verschickt; SMTP und ein E-Mail-Passwort sind dafür nicht erforderlich.')}>
                {aria => <input {...aria} className="input" type="email" value={form.contact} onChange={e => set('contact', e.target.value)} />}
              </FormField>
              {editing ? <BrowserPushDevices channelId={editing.id} csrf={csrf} /> : <p className="hint">{t('Kanal zuerst speichern, dann bearbeiten und deinen Browser verbinden.')}</p>}
            </> : <>
              {form.type === 'homeassistant' && <>
                <FormField label={t('Home-Assistant-Adresse')} error={errors.server}>{aria => <input {...aria} className="input" placeholder="http://homeassistant:8123" value={form.server} onChange={e => set('server', e.target.value)} />}</FormField>
                <FormField label={t('Benachrichtigungsziel')} error={errors.service}>
                  {aria => <select {...aria} className="input" value={['persistent_notification.create', 'notify.notify'].includes(form.service) ? form.service : 'custom'} onChange={e => set('service', e.target.value === 'custom' ? '' : e.target.value)}>
                    <option value="persistent_notification.create">{t('Home-Assistant-Dashboard')}</option>
                    <option value="notify.notify">{t('Standard-Benachrichtigungsdienst')}</option>
                    <option value="custom">{t('Bestimmtes Handy oder eigene Benachrichtigungsgruppe')}</option>
                  </select>}
                </FormField>
                {form.service === 'persistent_notification.create' ? <p className="hint">{t('Erzeugt eine Meldung unter „Benachrichtigungen“ in Home Assistant. Sendet keine Push-Nachricht an dein Handy.')}</p> : form.service === 'notify.notify' ? <p className="hint">{t('Verwendet notify.notify in Home Assistant. Welche Geräte Nachrichten erhalten, bestimmt dein dort eingerichteter Standarddienst. Prüfe diesen Dienst zuerst in Home Assistant unter Einstellungen → Werkzeuge → Aktionen.')}</p> : <FormField label={t('Home-Assistant-Aktionsname')} error={errors.service} hint={t('In Home Assistant unter Einstellungen → Werkzeuge → Aktionen nach notify.mobile_app suchen. Die Aktion deines Handys auswählen, direkt testen und den vollständigen Namen hier kopieren. Für eine bestehende Gruppe ihren notify.-Aktionsnamen verwenden.')}>
                  {aria => <input {...aria} className="input" placeholder="notify.mobile_app_mein_handy" value={form.service} onChange={e => set('service', e.target.value)} />}
                </FormField>}
                <p className="hint">{t('Zum Einrichten: Die Companion-App muss mit diesem Home-Assistant-Server verbunden sein und Benachrichtigungen erlauben. Nach dem Speichern einmal „Testnachricht“ wählen. Eine funktionierende Automation zeigt ihren Aktionsnamen über ⋮ → In YAML bearbeiten.')}</p>
              </>}
              <FormField label={t(form.type === 'discord' || form.type === 'slack' ? 'Webhook-Adresse' : form.type === 'pushover' ? 'Pushover App-Token' : 'Home Assistant Long-Lived Access Token')} error={errors.token} hint={t('Zugangsdaten werden verschlüsselt gespeichert. Beim Bearbeiten leer lassen, um sie zu behalten.')}>
                {aria => <input {...aria} className="input" type="password" autoComplete="new-password" disabled={form.clearSecret} value={form.token} onChange={e => set('token', e.target.value)} />}
              </FormField>
              {form.type === 'discord' && <p className="hint">{t('Discord: Im gewünschten Textkanal unter „Kanal bearbeiten → Integrationen → Webhooks“ einen Webhook erstellen und die vollständige Webhook-URL hier einfügen. Die Adresse enthält den Token und das Ziel; ein zusätzlicher Bot-Token ist nicht erforderlich.')}</p>}
              {form.type === 'slack' && <p className="hint">{t('Slack: Eine Slack-App mit „Incoming Webhooks“ erstellen, „Add New Webhook to Workspace“ wählen, den Zielkanal freigeben und die vollständige hooks.slack.com/services/-Adresse hier einfügen. Ein zusätzlicher Bot-Token ist nicht erforderlich.')}</p>}
              {form.type === 'pushover' && <>
                <FormField label={t('Pushover User/Group-Key')} error={errors.user_key} hint={t('Beim Ändern beide Pushover-Schlüssel neu eingeben.')}>
                  {aria => <input {...aria} className="input" type="password" autoComplete="new-password" disabled={form.clearSecret} value={form.userKey} onChange={e => set('userKey', e.target.value)} />}
                </FormField>
                <FormField label={t('Priorität')} error={errors.priority}>{aria => <select {...aria} className="input" value={form.priority} onChange={e => set('priority', e.target.value)}><option value="-1">{t('Niedrig')}</option><option value="0">{t('Normal')}</option><option value="1">{t('Hoch')}</option></select>}</FormField>
              </>}
            </>}
          </>
        ) : form.type === 'webhook' ? (
          <>
            <FormField label={t('Adresse (URL)')} error={errors.url} hint={t('Meridian sendet einen JSON-Körper per POST (Ereignis, Job, Status, Zeit, Hinweis, Link, Text). Parameter in der Adresse sind nicht erlaubt; Zugangsdaten gehören ins Token-Feld.')}>
              {(aria) => <input {...aria} className="input" name="webhook-url" value={form.url} autoComplete="off" onChange={(e) => set('url', e.target.value)} />}
            </FormField>
            <FormField label={secretLabel} error={errors.token} hint={stored ? t('Ein Token ist gespeichert. Leer lassen, um es zu behalten. Es wird als „Authorization: Bearer …“ gesendet.') : 'Wird als „Authorization: Bearer …“ gesendet.'}>
              {(aria) => <input {...aria} className="input" type="password" name="webhook-token" value={form.token} autoComplete="new-password" disabled={form.clearSecret} onChange={(e) => set('token', e.target.value)} />}
            </FormField>
          </>
        ) : form.type === 'telegram' ? (
          <>
            <FormField label={t('Chat')} error={errors.chat_id} hint={t('Nummer des Chats oder der Gruppe (Gruppen beginnen mit -) oder @kanalname.')}>
              {(aria) => <input {...aria} className="input" name="telegram-chat" value={form.chatId} autoComplete="off" onChange={(e) => set('chatId', e.target.value)} />}
            </FormField>
            <FormField label={secretLabel} error={errors.token} hint={stored ? t('Ein Token ist gespeichert. Leer lassen, um es zu behalten.') : t('Vom BotFather, Format 123456789:ABC… Der Bot muss im Chat sein.')}>
              {(aria) => <input {...aria} className="input" type="password" name="telegram-token" value={form.token} autoComplete="new-password" disabled={form.clearSecret} onChange={(e) => set('token', e.target.value)} />}
            </FormField>
          </>
        ) : (
          <>
            <div className="grid-2">
              <FormField label={t('Server')} error={errors.server} hint={t('https://ntfy.sh oder dein eigener ntfy-Server.')}>
                {(aria) => <input {...aria} className="input" name="ntfy-server" value={form.server} autoComplete="off" onChange={(e) => set('server', e.target.value)} />}
              </FormField>
              <FormField label={t('Thema')} error={errors.topic} hint={t('Buchstaben, Ziffern, _ und -. Wer das Thema kennt, kann mitlesen: wähle etwas Schwer-Erratbares oder nutze ein Token.')}>
                {(aria) => <input {...aria} className="input" name="ntfy-topic" value={form.topic} autoComplete="off" onChange={(e) => set('topic', e.target.value)} />}
              </FormField>
            </div>
            <div className="grid-2">
              <FormField label={t('Priorität (1 bis 5)')} error={errors.priority}>
                {(aria) => <input {...aria} className="input" name="ntfy-priority" inputMode="numeric" value={form.priority} onChange={(e) => set('priority', e.target.value)} />}
              </FormField>
              <FormField label={secretLabel} error={errors.token} hint={stored ? t('Ein Token ist gespeichert. Leer lassen, um es zu behalten.') : undefined}>
                {(aria) => <input {...aria} className="input" type="password" name="ntfy-token" value={form.token} autoComplete="new-password" disabled={form.clearSecret} onChange={(e) => set('token', e.target.value)} />}
              </FormField>
            </div>
          </>
        )}
        {stored && form.type !== 'browser' && (
          <label className="check">
            <input type="checkbox" checked={form.clearSecret} onChange={(e) => set('clearSecret', e.target.checked)} />
            <span>{isEmail ? t('Gespeichertes Passwort entfernen') : t('Gespeichertes Token entfernen')}</span>
          </label>
        )}
        <label className="check">
          <input type="checkbox" checked={form.enabled} onChange={(e) => set('enabled', e.target.checked)} />
          <span>{t('Kanal ist aktiv')}</span>
        </label>
        <div className="row">
          <button type="submit" className="btn btn--solid" disabled={busy}>
            {editing === null ? t('Kanal anlegen') : t('Speichern')}
          </button>
          {editing !== null && (
            <button type="button" className="btn btn--ghost" onClick={reset}>
              {t('Abbrechen')}</button>
          )}
        </div>
      </form>
    </section>
  );
}
