import { request } from '../lib/api';
import { getLang, setLang, t, useLang } from '../i18n';
import type { Lang } from '../i18n';

const LANGS: ReadonlyArray<{ id: Lang; label: string; name: string }> = [
  { id: 'de', label: 'DE', name: 'Deutsch' },
  { id: 'en', label: 'EN', name: 'English' },
];

/**
 * Sprachumschalter (oben rechts). Gewählt wird sofort; angemeldete Benutzer speichern die Wahl auf dem Server
 * (`PUT /api/auth/language`), davor gilt sie nur für diesen Browser.
 */
export function LanguageSwitch({ csrf }: { csrf?: string }) {
  const lang = useLang();
  async function choose(next: Lang): Promise<void> {
    if (next === getLang()) {
      return;
    }
    setLang(next);
    if (csrf !== undefined) {
      try {
        await request('PUT', '/api/auth/language', { csrf, body: { language: next } });
      } catch {
        // Die Anzeige bleibt in der gewählten Sprache; gespeichert wird beim nächsten Wechsel erneut.
      }
    }
  }
  return (
    <div className="langswitch" role="group" aria-label={t('Sprache')}>
      {LANGS.map((entry) => (
        <button key={entry.id} type="button" className="langswitch__btn" lang={entry.id} title={entry.name} aria-pressed={lang === entry.id} onClick={() => void choose(entry.id)}>
          {entry.label}
        </button>
      ))}
    </div>
  );
}
