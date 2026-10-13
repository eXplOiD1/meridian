/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useCallback, useEffect, useRef, useState } from 'react';
import { request } from '../lib/api';
import { t } from '../i18n';
import { Alert } from './Alert';
import { Confirm } from './Confirm';
import { useUpdateAvailability } from './UpdateAvailability';

interface UpdateStatus { configured: boolean; channel?: 'main' | 'beta'; mode?: string; current?: string; latest?: string; checked_at?: string; available?: boolean; busy?: boolean; pending?: boolean; phase?: string; message?: string }
export function SystemUpdates({ csrf }: { csrf: string }) {
  const { report } = useUpdateAvailability();
  const [status, setStatus] = useState<UpdateStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const initialCheck = useRef(false);
  const load = useCallback(async () => { try { const next = await request<UpdateStatus>('GET', '/api/admin/updates'); setStatus(next); report(next); setError(null); } catch { setError(t('Update-Dienst vorübergehend nicht erreichbar. Die Seite verbindet sich erneut.')); } }, [report]);
  useEffect(() => { void load(); const timer = window.setInterval(() => void load(), 5000); return () => clearInterval(timer); }, [load]);
  useEffect(() => {
    if (!status?.configured || initialCheck.current) return;
    initialCheck.current = true;
    if (!status.busy && !status.pending) void action('check');
  }, [status]);
  async function action(kind: 'check' | 'install' | 'channel/main' | 'channel/beta') {
    setSending(true);
    try { await request('POST', '/api/admin/updates/' + kind, { csrf }); setStatus(previous => previous ? { ...previous, pending: true } : previous); report({ configured: true, pending: true }); }
    catch (caught) { setError(caught instanceof Error ? caught.message : t('Update fehlgeschlagen.')); }
    finally { setSending(false); }
  }
  const disabled = sending || !status?.configured || status.busy || status.pending;
  const working = Boolean(sending || status?.busy || status?.pending);
  return <section className="card">
    <h2>{t('System-Updates')}</h2>
    <p className="hint">{t('Updates aus dem gewählten Kanal werden durch einen getrennten Update-Dienst installiert. Vorher wird eine Sicherung erstellt. Während des Neustarts ist Meridian kurz nicht erreichbar.')}</p>
    {error && <Alert tone="warn">{error}</Alert>}
    {status && !status.configured && <Alert tone="info">{t('Update-Dienst nicht erreichbar. Bei der aktuellen Installation wird er automatisch eingerichtet. Bei Docker die aktuelle Compose-Datei übernehmen und den Stack neu bereitstellen; bei systemd den aktuellen Installer ausführen. Danach die Dienstprotokolle prüfen, falls diese Meldung bestehen bleibt.')}</Alert>}
    <label className="field"><span className="field__label">{t('Update-Kanal')}</span>
      <select className="input" value={status?.channel ?? 'main'} disabled={Boolean(disabled) || !status?.channel} onChange={event => {
        if (event.target.value === 'main' || event.target.value === 'beta') void action(event.target.value === 'main' ? 'channel/main' : 'channel/beta');
      }}><option value="main">{t('Main (stabil)')}</option><option value="beta">{t('Beta (Vorabversion)')}</option></select>
    </label>
    <p className="hint">{t('Ein Kanalwechsel prüft die verfügbare Version und installiert noch nichts. Beta kann Fehler enthalten. Ein Rückwechsel auf Main kann ältere Software installieren; eine kompatible Datenbank ist dabei erforderlich.')}</p>
    {status?.configured && !status.channel && <Alert tone="info">{t('Für die Kanalwahl zuerst das Updater-Image neu abrufen und den Stack bereitstellen oder den systemd-Installer erneut ausführen.')}</Alert>}
    <dl className="facts"><dt>{t('Installiert')}</dt><dd>{status?.current?.slice(0, 12) || '—'}</dd><dt>{t('Verfügbar')}</dt><dd>{status?.latest?.slice(0, 12) || '—'}</dd><dt>{t('Betriebsart')}</dt><dd>{status?.mode || '—'}</dd><dt>{t('Status')}</dt><dd>{status?.message ? t(status.message) : '—'}</dd></dl>
    {working && <div className="update-progress" role="status" aria-live="polite"><span className="update-progress__spinner" aria-hidden="true" /><div><strong>{status?.busy && status.message ? t(status.message) : t('Anfrage wird verarbeitet …')}</strong><p className="hint">{t('Bitte warten. Der Status wird automatisch aktualisiert. Bei einem Neustart verbindet sich die Seite erneut.')}</p></div></div>}
    {!working && status?.phase === 'success' && <Alert tone="ok">{t('Update erfolgreich installiert.')}</Alert>}
    {!working && status?.phase === 'failed' && <Alert tone="warn">{status.message ? t(status.message) : t('Update fehlgeschlagen.')}</Alert>}
    <div className="row"><button className="btn btn--ghost" disabled={disabled} onClick={() => void action('check')}>{t('Updates prüfen')}</button>{!working && status?.available && status.phase !== 'failed' && <Confirm actionsBelow label={t('Jetzt aktualisieren')} question={t('Laufende Jobs werden beendet, Daten und Schlüssel gesichert und Meridian neu gestartet. Währenddessen ist die Oberfläche kurz nicht erreichbar.')} confirmLabel={t('Jetzt aktualisieren')} disabled={disabled} onConfirm={() => void action('install')} />}</div>
  </section>;
}
