import { request } from '../lib/api';
import { t } from '../i18n';
import { getTheme, setTheme, useTheme } from '../theme';
import type { Theme } from '../theme';

const MOON = 'M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z';

/** Umschalter dunkel/hell; angemeldet wird die Wahl am Benutzer gespeichert (`PUT /api/auth/theme`). */
export function ThemeSwitch({ csrf }: { csrf?: string }) {
  const theme = useTheme();
  async function choose(next: Theme): Promise<void> {
    if (next === getTheme()) {
      return;
    }
    // Erst speichern, dann umschalten: Ein zwischenzeitliches Neuladen des Profils darf das alte Schema nicht zurückholen.
    if (csrf !== undefined) {
      try {
        await request('PUT', '/api/auth/theme', { csrf, body: { theme: next } });
      } catch {
        // Die Anzeige wechselt trotzdem; gespeichert wird beim nächsten Wechsel erneut.
      }
    }
    setTheme(next);
  }
  return (
    <div className="themeswitch" role="group" aria-label={t('Farbschema')}>
      <button type="button" className="themeswitch__btn" aria-pressed={theme === 'dark'} onClick={() => void choose('dark')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d={MOON} /></svg>
        <span>{t('Dunkel')}</span>
      </button>
      <button type="button" className="themeswitch__btn" aria-pressed={theme === 'light'} onClick={() => void choose('light')}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.4 1.4M17.6 17.6L19 19M5 19l1.4-1.4M17.6 6.4L19 5" /></svg>
        <span>{t('Hell')}</span>
      </button>
    </div>
  );
}
