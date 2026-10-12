/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import QRCode from 'qrcode';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { Field } from '../components/Field';
import { ApiError, request } from '../lib/api';
import { groupSecret, retryHint } from '../lib/format';
import { roleNames } from '../lib/permissions';
import type { Profile } from '../types';
import { ApiTokens } from '../components/ApiTokens';
import { PersonalBrowserPush } from '../components/PersonalBrowserPush';
import { DisplayNameCard, PasswordCard, SessionsCard } from './AccountExtras';
import { t } from '../i18n';

interface AccountProps {
  profile: Profile;
  onChanged: () => Promise<void>;
  onCsrfToken: (token: string) => void;
}

interface Setup {
  secret: string;
  otpauth_uri: string;
}

function message(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message + (error.status === 429 ? retryHint(error.retryAfter) : '');
  }
  return t('Unerwarteter Fehler.');
}

/** QR-Code lokal im Browser erzeugt (data:-Bild, img-src der CSP erlaubt es): das Geheimnis verlässt die Seite nie. */
function QrImage({ uri }: { uri: string }) {
  const [src, setSrc] = useState<string | null>(null);
  useEffect(() => {
    let alive = true;
    QRCode.toDataURL(uri, { margin: 1, width: 224, errorCorrectionLevel: 'M' })
      .then((url) => {
        if (alive) {
          setSrc(url);
        }
      })
      .catch(() => {
        if (alive) {
          setSrc(null);
        }
      });
    return () => {
      alive = false;
    };
  }, [uri]);
  return src === null ? <p className="hint">{t('QR-Code wird erzeugt …')}</p> : <img src={src} alt={t('QR-Code zum Einscannen mit der Authenticator-App')} />;
}

export function Account({ profile, onChanged, onCsrfToken }: AccountProps) {
  const [setup, setSetup] = useState<Setup | null>(null);
  const [recovery, setRecovery] = useState<string[] | null>(null);
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const csrf = profile.csrf_token;

  async function run(action: () => Promise<void>): Promise<void> {
    setBusy(true);
    setError(null);
    setInfo(null);
    try {
      await action();
    } catch (caught) {
      setError(message(caught));
    } finally {
      setBusy(false);
    }
  }

  const start = (): Promise<void> =>
    run(async () => {
      setSetup(await request<Setup>('POST', '/api/auth/2fa/setup', { csrf }));
    });

  const enable = (event: FormEvent): Promise<void> => {
    event.preventDefault();
    return run(async () => {
      const result = await request<{ recovery_codes: string[] }>('POST', '/api/auth/2fa/enable', { csrf, body: { password, code: code.trim() } });
      setRecovery(result.recovery_codes);
      setSetup(null);
      setPassword('');
      setCode('');
    });
  };

  const disable = (event: FormEvent): Promise<void> => {
    event.preventDefault();
    return run(async () => {
      await request('POST', '/api/auth/2fa/disable', { csrf, body: { password, code: code.trim() } });
      setPassword('');
      setCode('');
      setInfo(t('Die Zwei-Faktor-Anmeldung ist abgeschaltet.'));
      await onChanged();
    });
  };

  async function finish(): Promise<void> {
    setRecovery(null);
    setSaved(false);
    await onChanged();
  }

  // Wiederherstellungscodes: genau einmal sichtbar
  if (recovery !== null) {
    return (
      <section className="card" aria-label={t('Wiederherstellungscodes')}>
        <h2>{t('Wiederherstellungscodes')}</h2>
        <Alert tone="warn">
          {t('Diese Codes erscheinen nur jetzt. Jeder gilt einmal und ersetzt den App-Code, falls du dein Gerät verlierst. Bewahre sie getrennt vom Passwort auf.')}</Alert>
        <ul className="codes">
          {recovery.map((entry) => (
            <li key={entry}>{entry}</li>
          ))}
        </ul>
        <label className="check">
          <input type="checkbox" checked={saved} onChange={(event) => setSaved(event.target.checked)} />
          <span>{t('Ich habe die Codes sicher gespeichert.')}</span>
        </label>
        <div className="row">
          <button type="button" className="btn btn--solid" disabled={!saved} onClick={() => void finish()}>
            {t('Fertig')}</button>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={() => {
              void navigator.clipboard?.writeText(recovery.join('\n'));
            }}
          >
            {t('In die Zwischenablage kopieren')}</button>
        </div>
      </section>
    );
  }

  return (
    <div className="settings">
    <PersonalBrowserPush csrf={csrf} />
    <div className="grid-2">
      <section className="card" aria-label={t('Konto')}>
        <h2>{t('Konto')}</h2>
        <dl className="facts">
          <dt>{t('Benutzername')}</dt>
          <dd>{profile.user.username}</dd>
          <dt>{t('Anzeigename')}</dt>
          <dd>{profile.user.display_name}</dd>
          <dt>{t('Rolle')}</dt>
          <dd>{roleNames(profile)}</dd>
        </dl>
      </section>

      <section className="card" aria-label={t('Zwei-Faktor-Anmeldung')}>
        <div className="row">
          <h2>{t('Zwei-Faktor-Anmeldung')}</h2>
          <span className={profile.totp_enabled ? 'badge badge--ok' : 'badge badge--warn'}>{profile.totp_enabled ? t('aktiv') : t('nicht aktiv')}</span>
        </div>
        {error !== null && <Alert tone="err">{error}</Alert>}
        {info !== null && <Alert tone="ok">{info}</Alert>}

        {!profile.totp_enabled && setup === null && (
          <>
            <p className="card__lead">{t('Zusätzlich zum Passwort fragt die Anmeldung dann nach einem Code aus einer Authenticator-App (z. B. Aegis, 2FAS oder Google Authenticator).')}</p>
            <div className="row">
              <button type="button" className="btn btn--solid" disabled={busy} onClick={() => void start()}>
                {t('Einrichten')}</button>
            </div>
          </>
        )}

        {!profile.totp_enabled && setup !== null && (
          <form className="form" onSubmit={(event) => void enable(event)}>
            <p className="card__lead">{t('1. Code in der App einscannen, oder den Schlüssel von Hand eintragen.')}</p>
            <div className="qr">
              <QrImage uri={setup.otpauth_uri} />
              <div className="field">
                <span className="field__label">{t('Schlüssel zum Abtippen')}</span>
                <span className="secret">{groupSecret(setup.secret)}</span>
                <span className="field__hint">{t('Wird nur jetzt angezeigt.')}</span>
              </div>
            </div>
            <p className="card__lead">{t('2. Zur Bestätigung dein Passwort und den aktuellen 6-stelligen Code der App eingeben.')}</p>
            <Field label={t('Passwort')} type="password" name="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
            <Field label={t('Code aus der App')} name="code" inputMode="numeric" autoComplete="one-time-code" required mono maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} />
            <div className="row">
              <button type="submit" className="btn btn--solid" disabled={busy || password === '' || code.trim().length !== 6}>
                {t('Aktivieren')}</button>
              <button
                type="button"
                className="btn btn--ghost"
                disabled={busy}
                onClick={() => {
                  setSetup(null);
                  setPassword('');
                  setCode('');
                }}
              >
                {t('Abbrechen')}</button>
            </div>
          </form>
        )}

        {profile.totp_enabled && (
          <form className="form" onSubmit={(event) => void disable(event)}>
            <p className="card__lead">{t('Zum Abschalten brauchst du dein Passwort und einen gültigen Code (App oder Wiederherstellungscode).')}</p>
            <Field label={t('Passwort')} type="password" name="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
            <Field label={t('Code')} name="code" autoComplete="one-time-code" required mono maxLength={32} value={code} onChange={(e) => setCode(e.target.value)} />
            <div className="row">
              <button type="submit" className="btn btn--danger" disabled={busy || password === '' || code.trim() === ''}>
                {t('Abschalten')}</button>
            </div>
          </form>
        )}
      </section>
    </div>
    <div className="grid-2">
      <DisplayNameCard profile={profile} onChanged={onChanged} />
      <PasswordCard profile={profile} onChanged={onChanged} onCsrfToken={onCsrfToken} />
    </div>
    <SessionsCard profile={profile} />
    <ApiTokens csrf={profile.csrf_token} />
    </div>
  );
}

