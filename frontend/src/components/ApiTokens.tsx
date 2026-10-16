/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useId, useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Modal, ModalActions } from './Modal';
import { Alert } from './Alert';
import { Confirm } from './Confirm';
import { FormField } from './FormField';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import type { ApiTokenEntry } from '../types';
import { t } from '../i18n';

const EXPIRY_CHOICES: ReadonlyArray<{ value: string; label: string }> = [
  { value: '30', label: '30 Tage' },
  { value: '90', label: '90 Tage (Standard)' },
  { value: '180', label: '180 Tage' },
  { value: '365', label: '1 Jahr' },
  { value: 'never', label: 'Läuft nie ab (nicht empfohlen)' },
];

/**
 * Zugriffstoken für die REST-API (ADR 0009): anlegen mit Passwort, Klartext nur einmal sichtbar und nur im Arbeitsspeicher
 * (nie in localStorage, URL oder Verlauf), widerrufen. Ein Token handelt mit den Rechten seines Benutzers, aber nur für Jobs,
 * Läufe, Übersicht und Statistik.
 */
export function ApiTokens({ csrf }: { csrf: string }) {
  const formId = useId();
  const [open, setOpen] = useState(false);
  const [tokens, setTokens] = useState<ApiTokenEntry[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [name, setName] = useState('');
  const [mode, setMode] = useState<'read' | 'write'>('read');
  const [expiry, setExpiry] = useState('90');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [created, setCreated] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async (): Promise<void> => {
    try {
      setTokens((await request<{ tokens: ApiTokenEntry[] }>('GET', '/api/auth/tokens')).tokens);
      setLoadError(null);
    } catch (caught) {
      setLoadError(errorMessage(caught));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function create(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setFormError(null);
    setCreated(null);
    try {
      const result = await request<{ plain: string }>('POST', '/api/auth/tokens', {
        csrf,
        body: { name, read_only: mode === 'read', expires_in_days: expiry === 'never' ? null : Number.parseInt(expiry, 10), current_password: password },
      });
      setCreated(result.plain);
      setOpen(false);
      setName('');
      setPassword('');
      await load();
    } catch (caught) {
      setPassword('');
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

  async function revoke(id: number): Promise<void> {
    setBusy(true);
    setFormError(null);
    try {
      await request('DELETE', '/api/auth/tokens/' + String(id), { csrf });
      await load();
    } catch (caught) {
      setFormError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  const origin = window.location.origin;

  return (
    <section className="card" aria-label={t('API-Zugriff')}>
      <h2>{t('API-Zugriff')}</h2>
      <p className="card__lead">
        {t('Zugriffstoken lassen Skripte und andere Programme Jobs, Läufe, Übersicht und Statistik abrufen (und mit „Lesen und Schreiben“ auch Jobs anlegen, ändern und starten). Ein Token hat genau die Rechte seines Benutzers, nie mehr. Für Konto, Einstellungen, Benutzer und gespeicherte Skripte gilt es nie.')}</p>
      {loadError !== null && <Alert tone="err">{loadError}</Alert>}
      {!open && formError !== null && <Alert tone="err">{formError}</Alert>}
      {created !== null && (
        <Alert tone="warn">
          <strong>{t('Dein neues Token (wird nur jetzt angezeigt):')}</strong>
          <pre className="runout" aria-label={t('Neues Zugriffstoken')}>{created}</pre>
          {t('Beispiel:')} <code>curl -H &quot;Authorization: Bearer …&quot; {origin}/api/jobs</code>
          <div className="row">
            <button type="button" className="btn btn--ghost btn--small" onClick={() => setCreated(null)}>
              {t('Habe ich gespeichert, ausblenden')}</button>
          </div>
        </Alert>
      )}
      {tokens !== null && tokens.length === 0 && <p className="card__lead">{t('Noch kein Token angelegt.')}</p>}
      {tokens !== null && tokens.length > 0 && (
        <div className="table-wrap">
          <table className="table">
            <caption className="visually-hidden">{t('Zugriffstoken')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('Name')}</th>
                <th scope="col">{t('Umfang')}</th>
                <th scope="col">{t('Läuft ab')}</th>
                <th scope="col">{t('Zuletzt benutzt')}</th>
                <th scope="col">{t('Aktion')}</th>
              </tr>
            </thead>
            <tbody>
              {tokens.map((token) => (
                <tr key={token.id}>
                  <td>{token.name}</td>
                  <td>{token.read_only ? 'Nur lesen' : t('Lesen und Schreiben')}</td>
                  <td>{token.expires_at === null ? 'nie' : formatTime(token.expires_at)}</td>
                  <td>{token.last_used_at === null ? <span className="muted">{t('noch nie')}</span> : formatTime(token.last_used_at)}</td>
                  <td>
                    <Confirm label={t('Widerrufen')} question={t('Token widerrufen? Programme damit verlieren sofort den Zugriff.')} confirmLabel={t('Ja, widerrufen')} busy={busy} accessibleName={t('Token {name} widerrufen', { name: token.name })} onConfirm={() => void revoke(token.id)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <button type="button" className="btn btn--solid" onClick={() => { setName(''); setPassword(''); setMode('read'); setExpiry('90'); setErrors({}); setFormError(null); setOpen(true); }}>{t('Token anlegen')}</button>
      <Modal open={open} title={t('Token anlegen')} busy={busy} onClose={() => { setOpen(false); setPassword(''); setName(''); setMode('read'); setExpiry('90'); setErrors({}); setFormError(null); }}>
      {formError !== null && <Alert tone="err">{formError}</Alert>}
      <form id={formId} className="form" onSubmit={(event) => void create(event)} noValidate>
        <div className="grid-2">
          <FormField label={t('Name')} error={errors.name} hint={t('Wofür das Token gedacht ist, z. B. „Grafana“.')}>
            {(aria) => <input {...aria} className="input" name="token-name" value={name} maxLength={60} autoComplete="off" onChange={(e) => setName(e.target.value)} />}
          </FormField>
          <FormField label={t('Umfang')} error={errors.read_only}>
            {(aria) => (
              <select {...aria} className="input" name="token-mode" value={mode} onChange={(e) => setMode(e.target.value === 'write' ? 'write' : 'read')}>
                <option value="read">{t('Nur lesen (empfohlen)')}</option>
                <option value="write">{t('Lesen und Schreiben')}</option>
              </select>
            )}
          </FormField>
        </div>
        <div className="grid-2">
          <FormField label={t('Gültigkeit')} error={errors.expires_in_days}>
            {(aria) => (
              <select {...aria} className="input" name="token-expiry" value={expiry} onChange={(e) => setExpiry(e.target.value)}>
                {EXPIRY_CHOICES.map((choice) => (
                  <option key={choice.value} value={choice.value}>
                    {t(choice.label)}
                  </option>
                ))}
              </select>
            )}
          </FormField>
          <FormField label={t('Dein Passwort zur Bestätigung')} error={errors.current_password}>
            {(aria) => <input {...aria} className="input" type="password" name="token-password" value={password} autoComplete="current-password" onChange={(e) => setPassword(e.target.value)} />}
          </FormField>
        </div>
        {mode === 'write' && <Alert tone="warn">{t('Ein Token mit Schreibrecht kann Jobs anlegen, ändern, löschen und starten, so wie du es darfst. Gib es nur Programmen, denen du vertraust.')}</Alert>}
        <ModalActions>
          <button type="submit" form={formId} className="btn btn--solid" disabled={busy || name.trim() === '' || password === ''}>
            {t('Token erzeugen')}</button>
        </ModalActions>
      </form>
      </Modal>
    </section>
  );
}
