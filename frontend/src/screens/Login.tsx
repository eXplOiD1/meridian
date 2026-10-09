import { useState } from 'react';
import type { FormEvent } from 'react';
import { Alert } from '../components/Alert';
import { InsecureWarning } from '../components/InsecureWarning';
import { Brand } from '../components/Brand';
import { Field } from '../components/Field';
import { ApiError, request } from '../lib/api';
import { retryHint } from '../lib/format';
import type { Profile } from '../types';

interface LoginProps {
  onLoggedIn: (profile: Profile) => void;
}

/**
 * Anmeldung mit optionalem zweiten Faktor. Der Server verlangt den Code erst nach richtigem Passwort
 * (totp_required); Passwort und Code bleiben nur im Arbeitsspeicher und werden nach dem Versuch geleert.
 */
export function Login({ onLoggedIn }: LoginProps) {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [needsCode, setNeedsCode] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Konfigurationsfehler des Servers (Proxy-Header ohne MERIDIAN_TRUSTED_PROXIES): Meldung des Servers, kein Erneut-versuchen.
  const [proxyError, setProxyError] = useState<string | null>(null);

  async function submit(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setProxyError(null);
    try {
      await request<Profile>('POST', '/api/auth/login', {
        body: needsCode ? { username, password, totp_code: code.trim() } : { username, password },
      });
      setPassword('');
      setCode('');
      // Gegenprobe: Hat der Browser das Sitzungs-Cookie behalten? Es ist „Secure“ und wird über reines HTTP verworfen.
      try {
        onLoggedIn(await request<Profile>('GET', '/api/auth/me'));
      } catch (probe) {
        if (probe instanceof ApiError && probe.status === 401) {
          setError('Der Browser hat die Sitzung nicht gespeichert. Bitte Cookies für diese Adresse erlauben und es erneut versuchen.');
          return;
        }
        throw probe;
      }
    } catch (caught) {
      if (caught instanceof ApiError && caught.trustedProxiesRequired) {
        setProxyError(caught.message);
        setError(null);
        setNeedsCode(false);
      } else if (caught instanceof ApiError && caught.totpRequired) {
        setNeedsCode(true);
        setError(null);
      } else if (caught instanceof ApiError) {
        setError(caught.message + (caught.status === 429 ? retryHint(caught.retryAfter) : ''));
        if (needsCode) {
          setCode('');
        }
      } else {
        setError('Unerwarteter Fehler bei der Anmeldung.');
      }
    } finally {
      setBusy(false);
    }
  }

  function back(): void {
    setNeedsCode(false);
    setCode('');
    setPassword('');
    setError(null);
    setProxyError(null);
  }

  return (
    <div className="login">
      <aside className="login__side">
        <Brand />
        <h2 className="login__headline">
          Jeder Job <span>pünktlich</span> zur richtigen Zeit.
        </h2>
        <p className="login__tagline">
          Selbst gehosteter Zeitplaner für HTTP- und Shell-Jobs.
        </p>
      </aside>
      <main className="login__main">
        <form className="login__card form" onSubmit={(event) => void submit(event)} noValidate>
          <h1>{needsCode ? 'Zweiter Faktor' : 'Anmelden'}</h1>
          <InsecureWarning />
          {proxyError !== null && (
            <div className="alert alert--err" role="alert" data-testid="proxy-required">
              <strong>Anmeldung nicht möglich: Reverse-Proxy nicht eingerichtet.</strong>
              <p>{proxyError}</p>
              <p>
                Das ist ein Konfigurationsfehler des Servers (<code className="cron cron--inline">MERIDIAN_TRUSTED_PROXIES</code>), kein Problem mit deinen Zugangsdaten. Erneutes Versuchen hilft nicht, bis ein
                Administrator ihn behoben hat.
              </p>
            </div>
          )}
          {error !== null && <Alert tone="err">{error}</Alert>}
          {!needsCode ? (
            <>
              <Field label="Benutzername" name="username" autoComplete="username" autoFocus required value={username} onChange={(e) => { setUsername(e.target.value); setProxyError(null); }} />
              <Field label="Passwort" name="password" type="password" autoComplete="current-password" required value={password} onChange={(e) => { setPassword(e.target.value); setProxyError(null); }} />
            </>
          ) : (
            <>
              <p className="hint">Gib den 6-stelligen Code deiner Authenticator-App ein oder einen Wiederherstellungscode.</p>
              <Field
                label="Code"
                name="totp_code"
                inputMode="text"
                autoComplete="one-time-code"
                autoFocus
                required
                mono
                maxLength={32}
                value={code}
                onChange={(e) => setCode(e.target.value)}
                hint="Beispiel: 123456 oder ABCD-EFGH-JKLM"
              />
            </>
          )}
          <div className="row">
            <button type="submit" className="btn btn--solid" disabled={busy || proxyError !== null || username === '' || password === '' || (needsCode && code.trim() === '')}>
              {busy ? 'Einen Moment …' : needsCode ? 'Bestätigen' : 'Anmelden'}
            </button>
            {needsCode && (
              <button type="button" className="btn btn--ghost" onClick={back} disabled={busy}>
                Zurück
              </button>
            )}
          </div>
          <p className="hint">Die Anmeldung funktioniert nur über HTTPS (oder im Entwicklungsmodus).</p>
        </form>
      </main>
    </div>
  );
}
