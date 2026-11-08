/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary */
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { t } from '../i18n';
import { Alert } from './Alert';
import { FormField } from './FormField';

/** Öffentlicher Teil der Update-Quelle, wie ihn der Update-Dienst im Status meldet (nie das Token). */
export interface UpdateSourceInfo { kind?: 'github' | 'gitlab'; repo?: string; gitlab_url?: string; registry?: string; username?: string; has_token?: boolean; saved?: boolean }

interface Form { kind: 'github' | 'gitlab'; repo: string; gitlabUrl: string; registry: string; username: string; token: string; clearToken: boolean; password: string }

function fromInfo(info: UpdateSourceInfo | undefined): Form {
  return { kind: info?.kind === 'gitlab' ? 'gitlab' : 'github', repo: info?.repo ?? '', gitlabUrl: info?.gitlab_url ?? '', registry: info?.saved ? (info.registry ?? '') : '', username: info?.username ?? '', token: '', clearToken: false, password: '' };
}

/**
 * Woher der Update-Dienst Version und Images holt. Nur für Benutzer mit dem Recht „Einstellungen“ (Admin);
 * wer die Quelle ändert, bestimmt, welchen Code der Dienst mit Docker-Zugriff installiert. Darum:
 * eigenes Passwort zur Bestätigung, das Token nur schreibend (es wird nie angezeigt), Eintrag im Audit-Log.
 */
export function UpdateSource({ csrf, info, disabled, onSaved }: { csrf: string; info: UpdateSourceInfo | undefined; disabled: boolean; onSaved: () => void }) {
  const [form, setForm] = useState<Form>(() => fromInfo(info));
  const [loaded, setLoaded] = useState(info !== undefined);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  // Beim ersten Eintreffen des Status die aktuellen Werte übernehmen; spätere Statusabfragen überschreiben keine Eingabe.
  useEffect(() => {
    if (!loaded && info !== undefined) { setForm(fromInfo(info)); setLoaded(true); }
  }, [info, loaded]);

  function set<K extends keyof Form>(key: K, value: Form[K]): void { setForm((current) => ({ ...current, [key]: value })); setSaved(false); }

  async function submit(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      await request('PUT', '/api/admin/updates/source', {
        csrf,
        body: {
          kind: form.kind,
          repo: form.repo.trim(),
          gitlab_url: form.kind === 'gitlab' ? form.gitlabUrl.trim() : '',
          registry: form.registry.trim(),
          username: form.username.trim(),
          ...(form.token !== '' ? { token: form.token.trim() } : {}),
          ...(form.clearToken ? { clear_token: true } : {}),
          current_password: form.password,
        },
      });
      setSaved(true);
      setForm((current) => ({ ...current, token: '', clearToken: false, password: '' }));
      onSaved();
    } catch (caught) {
      if (caught instanceof ApiError && Object.keys(caught.fields).length > 0) { setErrors(caught.fields); setError(caught.message); }
      else setError(errorMessage(caught));
      // Weder Passwort noch Token bleiben nach einem Versuch im Formular stehen.
      setForm((current) => ({ ...current, token: '', password: '' }));
    } finally {
      setBusy(false);
    }
  }

  const gitlab = form.kind === 'gitlab';
  return <section className="card">
    <h2>{t('Update-Quelle')}</h2>
    <p className="hint">{t('Woher der Update-Dienst die aktuelle Version und die Images holt. Bei einem privaten Repository ist ein Zugriffstoken nötig. Wer die Quelle ändert, bestimmt, welcher Code installiert wird – deshalb nur mit Bestätigung durch dein Passwort.')}</p>
    {!loaded && <Alert tone="info">{t('Der Update-Dienst meldet seine Quelle erst nach dem Update auf die aktuelle Version.')}</Alert>}
    <form onSubmit={(event) => void submit(event)} noValidate>
      <FormField label={t('Quelle')} error={errors.kind}>
        {(aria) => <select {...aria} className="input" value={form.kind} disabled={disabled || busy} onChange={(event) => set('kind', event.target.value === 'gitlab' ? 'gitlab' : 'github')}><option value="github">GitHub</option><option value="gitlab">GitLab</option></select>}
      </FormField>
      <FormField label={t('Repository')} error={errors.repo} hint={gitlab ? t('Pfad des Projekts, z. B. gruppe/untergruppe/projekt') : t('Besitzer und Name, z. B. besitzer/projekt')}>
        {(aria) => <input {...aria} className="input" value={form.repo} maxLength={200} spellCheck={false} autoComplete="off" disabled={disabled || busy} onChange={(event) => set('repo', event.target.value)} />}
      </FormField>
      {gitlab && <FormField label={t('GitLab-Adresse')} error={errors.gitlab_url} hint={t('Nur bei selbst gehostetem GitLab, mit https:// und ohne Pfad. Leer = gitlab.com.')}>
        {(aria) => <input {...aria} className="input" value={form.gitlabUrl} maxLength={200} spellCheck={false} autoComplete="off" placeholder="https://gitlab.com" disabled={disabled || busy} onChange={(event) => set('gitlabUrl', event.target.value)} />}
      </FormField>}
      <FormField label={t('Image-Registry')} error={errors.registry} hint={t('Präfix der Images, z. B. ghcr.io/besitzer oder registry.gitlab.com/gruppe/projekt. Leer = aus dem Repository abgeleitet; bei selbst gehostetem GitLab Pflicht.')}>
        {(aria) => <input {...aria} className="input" value={form.registry} maxLength={200} spellCheck={false} autoComplete="off" disabled={disabled || busy} onChange={(event) => set('registry', event.target.value)} />}
      </FormField>
      <FormField label={t('Benutzername für die Registry')} error={errors.username} hint={t('Nötig, wenn die Images privat sind. Leer lassen, wenn nur das Repository privat ist.')}>
        {(aria) => <input {...aria} className="input" value={form.username} maxLength={100} spellCheck={false} autoComplete="off" disabled={disabled || busy} onChange={(event) => set('username', event.target.value)} />}
      </FormField>
      <FormField label={t('Zugriffstoken')} error={errors.token} hint={info?.has_token ? t('Ein Token ist gespeichert und wird nie angezeigt. Leer lassen, um es zu behalten; ein neues Token ersetzt es.') : t('Nur Lesezugriff genügt (GitHub: Contents und Pakete lesen; GitLab: read_api und read_registry). Das Token wird nur an den Update-Dienst übergeben und nie angezeigt.')}>
        {(aria) => <input {...aria} className="input" type="password" name="update-token" autoComplete="new-password" maxLength={255} value={form.token} placeholder={info?.has_token ? '••••••••' : ''} disabled={disabled || busy || form.clearToken} onChange={(event) => set('token', event.target.value)} />}
      </FormField>
      {info?.has_token && <label className="check"><input type="checkbox" checked={form.clearToken} disabled={disabled || busy || form.token !== ''} onChange={(event) => set('clearToken', event.target.checked)} /> {t('Gespeichertes Token entfernen')}</label>}
      {errors.clear_token !== undefined && <Alert tone="err">{errors.clear_token}</Alert>}
      <FormField label={t('Aktuelles Passwort')} error={errors.current_password} hint={t('Zur Bestätigung. Die Änderung wird im Audit-Log festgehalten.')}>
        {(aria) => <input {...aria} className="input" type="password" autoComplete="current-password" maxLength={1024} value={form.password} required disabled={disabled || busy} onChange={(event) => set('password', event.target.value)} />}
      </FormField>
      {errors.body !== undefined && <Alert tone="err">{errors.body}</Alert>}
      {error !== null && <Alert tone="warn">{error}</Alert>}
      {saved && <Alert tone="ok">{t('Gespeichert. Der Update-Dienst prüft jetzt mit der neuen Quelle.')}</Alert>}
      <div className="row"><button className="btn" type="submit" disabled={disabled || busy || !loaded || form.repo.trim() === '' || form.password === ''}>{t('Quelle speichern')}</button></div>
    </form>
  </section>;
}
