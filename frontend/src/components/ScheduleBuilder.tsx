import { useEffect, useState } from 'react';
import { CRON_PRESETS } from '../lib/jobs';
import { t } from '../i18n';

type Mode = 'seconds' | 'minutes' | 'hours' | 'daily' | 'weekly' | 'monthly' | 'yearly' | 'custom';

const MODES: ReadonlyArray<{ id: Mode; label: string }> = [
  { id: 'seconds', label: 'Alle n Sekunden' },
  { id: 'minutes', label: 'Alle n Minuten' },
  { id: 'hours', label: 'Alle n Stunden' },
  { id: 'daily', label: 'Täglich' },
  { id: 'weekly', label: 'Wochentage' },
  { id: 'monthly', label: 'Monatlich' },
  { id: 'yearly', label: 'Jährlich' },
  { id: 'custom', label: 'Benutzerdefiniert' },
];
const WEEKDAYS: ReadonlyArray<{ n: number; label: string }> = [
  { n: 1, label: 'Mo' }, { n: 2, label: 'Di' }, { n: 3, label: 'Mi' }, { n: 4, label: 'Do' }, { n: 5, label: 'Fr' }, { n: 6, label: 'Sa' }, { n: 0, label: 'So' },
];
const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
const FIELD_NAMES = ['Minute', 'Stunde', 'Tag', 'Monat', 'Wochentag'];

interface Parts {
  n: number;
  hour: number;
  minute: number;
  days: number[];
  dom: number;
  month: number;
}

const DEFAULTS: Parts = { n: 15, hour: 3, minute: 0, days: [1, 2, 3, 4, 5], dom: 1, month: 1 };

const num = (text: string): number => Number.parseInt(text, 10);

/** Erkennt, ob ein Ausdruck vom Baukasten erzeugt werden kann; sonst „Benutzerdefiniert“. */
function detect(cron: string): { mode: Mode; parts: Parts } {
  const every = /^@every ([1-9][0-9]{0,4})s$/.exec(cron.trim());
  if (every !== null && every[1] !== undefined) {
    return { mode: 'seconds', parts: { ...DEFAULTS, n: num(every[1]) } };
  }
  const f = cron.trim().split(/\s+/);
  const parts: Parts = { ...DEFAULTS };
  const [m, h, d, mo, w] = f;
  if (f.length === 5 && m !== undefined && h !== undefined && d !== undefined && mo !== undefined && w !== undefined) {
    const int = /^[0-9]{1,2}$/;
    if (/^\*\/[0-9]{1,2}$/.test(m) && h === '*' && d === '*' && mo === '*' && w === '*') {
      return { mode: 'minutes', parts: { ...parts, n: num(m.slice(2)) } };
    }
    if (m === '0' && /^\*\/[0-9]{1,2}$/.test(h) && d === '*' && mo === '*' && w === '*') {
      return { mode: 'hours', parts: { ...parts, n: num(h.slice(2)) } };
    }
    if (int.test(m) && int.test(h) && num(m) < 60 && num(h) < 24) {
      parts.minute = num(m);
      parts.hour = num(h);
      if (d === '*' && mo === '*' && w === '*') {
        return { mode: 'daily', parts };
      }
      if (d === '*' && mo === '*' && /^[0-7](,[0-7])*$/.test(w)) {
        return { mode: 'weekly', parts: { ...parts, days: w.split(',').map((x) => num(x) % 7) } };
      }
      if (d === '*' && mo === '*' && w === '1-5') {
        return { mode: 'weekly', parts: { ...parts, days: [1, 2, 3, 4, 5] } };
      }
      if (int.test(d) && num(d) >= 1 && num(d) <= 31 && w === '*') {
        if (mo === '*') {
          return { mode: 'monthly', parts: { ...parts, dom: num(d) } };
        }
        if (int.test(mo) && num(mo) >= 1 && num(mo) <= 12) {
          return { mode: 'yearly', parts: { ...parts, dom: num(d), month: num(mo) } };
        }
      }
    }
  }
  return { mode: 'custom', parts };
}

function build(mode: Mode, p: Parts): string {
  const time = String(p.minute) + ' ' + String(p.hour);
  switch (mode) {
    case 'seconds':
      return '@every ' + String(p.n) + 's';
    case 'minutes':
      return '*/' + String(p.n) + ' * * * *';
    case 'hours':
      return '0 */' + String(p.n) + ' * * *';
    case 'daily':
      return time + ' * * *';
    case 'weekly':
      return time + ' * * ' + ([...p.days].sort((a, b) => ((a + 6) % 7) - ((b + 6) % 7)).join(',') || '1');
    case 'monthly':
      return time + ' ' + String(p.dom) + ' * *';
    case 'yearly':
      return time + ' ' + String(p.dom) + ' ' + String(p.month) + ' *';
    case 'custom':
      return '';
  }
}

const pad = (n: number): string => String(n).padStart(2, '0');

function clamp(text: string, min: number, max: number, fallback: number): number {
  const value = Number.parseInt(text, 10);
  return Number.isNaN(value) ? fallback : Math.min(max, Math.max(min, value));
}

interface Props {
  cron: string;
  onChange: (cron: string) => void;
  error?: string | undefined;
  /** Kleinster erlaubter Abstand fester Intervalle (Einstellung des Admins). */
  minSeconds?: number;
}

/**
 * Baukasten für den Cron-Ausdruck. Der Ausdruck selbst bleibt die einzige Wahrheit (Formularzustand des Elternteils);
 * der Baukasten erzeugt ihn nur. Ausdrücke, die er nicht abbilden kann, stehen unter „Benutzerdefiniert“.
 */
export function ScheduleBuilder({ cron, onChange, error, minSeconds = 10 }: Props) {
  const [initial] = useState(() => detect(cron));
  const [mode, setMode] = useState<Mode>(initial.mode);
  const [parts, setParts] = useState<Parts>(initial.parts);

  // Wird der Ausdruck im Textfeld von Hand geändert, folgt der Baukasten, soweit er ihn abbilden kann (sonst „Benutzerdefiniert“).
  useEffect(() => {
    if (mode !== 'custom' && build(mode, parts) !== cron.trim()) {
      const found = detect(cron);
      setMode(found.mode);
      setParts(found.parts);
    }
  }, [cron, mode, parts]);

  function update(requestedMode: Mode, requestedParts: Parts): void {
    // Beim Wechsel in „Sekunden“ nie unter dem erlaubten Abstand beginnen.
    const nextParts = requestedMode === 'seconds' && requestedParts.n < minSeconds ? { ...requestedParts, n: minSeconds } : requestedParts;
    const nextMode = requestedMode;
    setMode(nextMode);
    setParts(nextParts);
    if (nextMode !== 'custom') {
      onChange(build(nextMode, nextParts));
    }
  }

  const fields = cron.trim().split(/\s+/);
  const patch = (patchParts: Partial<Parts>): void => update(mode, { ...parts, ...patchParts });
  const timeValue = pad(parts.hour) + ':' + pad(parts.minute);
  const timeField = (
    <label className="field sb__field">
      <span className="field__label">{t('Uhrzeit')}</span>
      <input
        className="input input--short"
        type="time"
        value={timeValue}
        onChange={(e) => {
          const [h, m] = e.target.value.split(':');
          if (h !== undefined && m !== undefined && h !== '' && m !== '') {
            patch({ hour: clamp(h, 0, 23, 0), minute: clamp(m, 0, 59, 0) });
          }
        }}
      />
    </label>
  );

  return (
    <div className="sb">
      <div className="chips" role="group" aria-label={t('Schnellwahl')}>
        {CRON_PRESETS.map((preset) => (
          <button
            key={preset.cron}
            type="button"
            className="chip"
            aria-pressed={cron.trim() === preset.cron}
            onClick={() => {
              const found = detect(preset.cron);
              setMode(found.mode);
              setParts(found.parts);
              onChange(preset.cron);
            }}
          >
            {t(preset.label)}
          </button>
        ))}
      </div>

      <div className="sb__box">
        <div className="sb__modes" role="group" aria-label={t('Zeitplan zusammenstellen')}>
          {MODES.map((entry) => (
            <button
              key={entry.id}
              type="button"
              className="chip"
              aria-pressed={mode === entry.id}
              onClick={() => update(entry.id, parts)}
            >
              {t(entry.label)}
            </button>
          ))}
        </div>

        <div className="sb__params">
          {mode === 'seconds' && (
            <label className="field sb__field">
              <span className="field__label">{t('Alle n Sekunden') + ' (' + String(minSeconds) + '–86400)'}</span>
              <input className="input input--short" inputMode="numeric" value={parts.n} onChange={(e) => patch({ n: clamp(e.target.value, 1, 86400, minSeconds) })} />
            </label>
          )}
          {(mode === 'minutes' || mode === 'hours') && (
            <label className="field sb__field">
              <span className="field__label">{mode === 'minutes' ? t('Alle n Minuten') + ' (1–59)' : t('Alle n Stunden') + ' (1–23)'}</span>
              <input
                className="input input--short"
                inputMode="numeric"
                value={parts.n}
                onChange={(e) => patch({ n: clamp(e.target.value, 1, mode === 'minutes' ? 59 : 23, 1) })}
              />
            </label>
          )}
          {mode === 'weekly' && (
            <fieldset className="sb__days">
              <legend className="field__label">{t('Wochentage')}</legend>
              <div className="chips">
                {WEEKDAYS.map((day) => (
                  <button
                    key={day.n}
                    type="button"
                    className="chip"
                    aria-pressed={parts.days.includes(day.n)}
                    onClick={() => patch({ days: parts.days.includes(day.n) ? (parts.days.length > 1 ? parts.days.filter((x) => x !== day.n) : parts.days) : [...parts.days, day.n] })}
                  >
                    {t(day.label)}
                  </button>
                ))}
              </div>
            </fieldset>
          )}
          {mode === 'yearly' && (
            <label className="field sb__field">
              <span className="field__label">{t('Monat')}</span>
              <select className="input" value={parts.month} onChange={(e) => patch({ month: clamp(e.target.value, 1, 12, 1) })}>
                {MONTHS.map((name, index) => (
                  <option key={name} value={index + 1}>
                    {t(name)}
                  </option>
                ))}
              </select>
            </label>
          )}
          {(mode === 'monthly' || mode === 'yearly') && (
            <label className="field sb__field">
              <span className="field__label">{t('Tag des Monats (1–31)')}</span>
              <input className="input input--short" inputMode="numeric" value={parts.dom} onChange={(e) => patch({ dom: clamp(e.target.value, 1, 31, 1) })} />
            </label>
          )}
          {(mode === 'daily' || mode === 'weekly' || mode === 'monthly' || mode === 'yearly') && timeField}
          {mode === 'custom' && (
            <div className="sb__cron5" role="group" aria-label={t('Die fünf Cron-Felder')}>
              {FIELD_NAMES.map((name, index) => (
                <label key={name} className="field sb__field">
                  <span className="field__label">{t(name)}</span>
                  <input
                    className="input input--mono"
                    spellCheck={false}
                    autoComplete="off"
                    value={fields[index] ?? ''}
                    onChange={(e) => {
                      const next = FIELD_NAMES.map((_, i) => (i === index ? e.target.value.replace(/\s+/g, '') : (fields[i] ?? '*')));
                      onChange(next.map((x) => (x === '' ? '*' : x)).join(' '));
                    }}
                  />
                </label>
              ))}
            </div>
          )}
        </div>
        {mode === 'seconds' && (
          <p className="hint">
            {t('Feste Abstände, ausgerichtet auf Vielfache von n Sekunden. Der kleinste erlaubte Abstand ist {n} s (Einstellung des Admins); sehr kurze Abstände erzeugen viele Läufe. Dauert ein Lauf länger als der Abstand, entscheidet „Überlappung“ über das Überspringen.', { n: minSeconds })}
          </p>
        )}
        {mode === 'minutes' && <p className="hint">{t('Läuft zu jeder n-ten Minute einer Stunde, nicht n Minuten nach dem letzten Lauf.')}</p>}
      </div>
      {error !== undefined && <div className="field__error">{error}</div>}
    </div>
  );
}
