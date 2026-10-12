/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useCallback, useEffect, useState } from 'react';
import { request } from '../lib/api';
import { t } from '../i18n';
import { Alert } from './Alert';
import { Confirm } from './Confirm';

interface UpdateStatus { configured: boolean; mode?: string; current?: string; latest?: string; checked_at?: string; available?: boolean; busy?: boolean; pending?: boolean; phase?: string; message?: string }
export function SystemUpdates({ csrf }: { csrf: string }) {
  const [status, setStatus] = useState<UpdateStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const load = useCallback(async () => { try { setStatus(await request<UpdateStatus>('GET', '/api/admin/updates')); setError(null); } catch { setError(t('Update-Dienst vorübergehend nicht erreichbar. Die Seite verbindet sich erneut.')); } }, []);
  useEffect(() => { void load(); const timer = window.setInterval(() => void load(), 5000); return () => clearInterval(timer); }, [load]);
  async function action(kind: 'check' | 'install') {
    setSending(true);
    try { await request('POST', '/api/admin/updates/' + kind, { csrf }); setStatus(previous => previous ? { ...previous, pending: true } : previous); }
    catch (caught) { setError(caught instanceof Error ? caught.message : t('Update fehlgeschlagen.')); }
    finally { setSending(false); }
  }
  const disabled = sending || !status?.configured || status.busy || status.pending;
  return <section className="card">
    <h2>{t('System-Updates')}</h2>
    <p className="hint">{t('Updates aus main werden durch einen getrennten Update-Dienst installiert. Vorher wird eine Sicherung erstellt. Während des Neustarts ist Meridian kurz nicht erreichbar.')}</p>
    {error && <Alert tone="warn">{error}</Alert>}
    {status && !status.configured && <Alert tone="info">{t('Update-Dienst noch nicht eingerichtet. Die einmalige Einrichtung für Docker oder systemd steht in der README.')}</Alert>}
    <dl className="facts"><dt>{t('Installiert')}</dt><dd>{status?.current?.slice(0, 12) || '—'}</dd><dt>{t('Verfügbar')}</dt><dd>{status?.latest?.slice(0, 12) || '—'}</dd><dt>{t('Betriebsart')}</dt><dd>{status?.mode || '—'}</dd><dt>{t('Status')}</dt><dd>{status?.message ? t(status.message) : '—'}</dd></dl>
    <div className="row"><button className="btn btn--ghost" disabled={disabled} onClick={() => void action('check')}>{t('Updates prüfen')}</button><Confirm label={t('Jetzt aktualisieren')} question={t('Laufende Jobs werden beendet, Daten und Schlüssel gesichert und Meridian neu gestartet. Währenddessen ist die Oberfläche kurz nicht erreichbar.')} confirmLabel={t('Jetzt aktualisieren')} disabled={disabled || !status?.available || status.phase === 'failed'} busy={Boolean(disabled) || !status?.available || status.phase === 'failed'} onConfirm={() => void action('install')} /></div>
  </section>;
}
