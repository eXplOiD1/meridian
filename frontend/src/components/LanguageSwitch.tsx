import { useState } from 'react';
import { Alert } from './Alert';
import { request } from '../lib/api';
import { getLang, setLang, t, useLang } from '../i18n';
import type { Lang } from '../i18n';

const LANGS: ReadonlyArray<{ id: Lang; label: string; name: string }> = [
  { id: 'de', label: 'DE', name: 'Deutsch' },
  { id: 'en', label: 'EN', name: 'English' },
];

/**
 * Sprachumschalter (oben rechts). Angemeldete Benutzer speichern die Wahl vor dem Umschalten auf dem Server
 * (`PUT /api/auth/language`), davor gilt sie nur für diesen Browser.
 */
export function LanguageSwitch({ csrf }: { csrf?: string }) {
  const lang = useLang();
  const [error, setError] = useState(false);
  const [busy, setBusy] = useState(false);
  async function choose(next: Lang): Promise<void> {
    if (next === getLang()) {
      return;
    }
    setError(false);
    setBusy(true);
    // Erst speichern, dann umschalten: Das Umschalten baut die Oberfläche neu auf und lädt das Profil, das dann schon die neue Sprache tragen muss.
    if (csrf !== undefined) {
      try {
        await request('PUT', '/api/auth/language', { csrf, body: { language: next } });
      } catch {
        setError(true);
        setBusy(false);
        return;
      }
    }
    setBusy(false);
    setLang(next);
  }
  return (
    <>
      <div className="langswitch" role="group" aria-label={t('Sprache')}>
        {LANGS.map((entry) => (
          <button key={entry.id} type="button" className="langswitch__btn" lang={entry.id} title={entry.name} disabled={busy} aria-pressed={lang === entry.id} onClick={() => void choose(entry.id)}>
            {entry.label}
          </button>
        ))}
      </div>
      {error && <Alert tone="err">{t('Sprache konnte nicht gespeichert werden. Bitte erneut versuchen.')}</Alert>}
    </>
  );
}
