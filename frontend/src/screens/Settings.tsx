/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { ExpandableLead } from '../components/ExpandableLead';
import { useCallback, useEffect, useId, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { NotificationChannels } from '../components/NotificationChannels';
import { SystemUpdates } from '../components/SystemUpdates';
import { UpdateAvailableDot, useUpdateAvailability } from '../components/UpdateAvailability';
import { FormField } from '../components/FormField';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import { canManageInternalTargets, canManageSettings, canManageShellTargets } from '../lib/permissions';
import type { CategoryRef, InternalTarget, Profile, SettingChange, SettingEntry, ShellTargetEntry } from '../types';
import { t } from '../i18n';

/** Beschreibung je Einstellung. Der Server kennt dieselbe Allowlist und lehnt alles andere ab. */
interface SettingSpec {
  key: string;
  title: string;
  lead: string;
  /** Auswahl (Wert → Beschriftung) oder null = Zahl. */
  options: { value: string; label: string; help: string }[] | null;
  numberLabel?: string;
  /** Freitext statt Zahl (z. B. eine Adresse). */
  text?: boolean;
  /** Einheit der Anzeige bei Zahlen (Standard: Sekunden). */
  unit?: string;
  /** Eigene Anzeige für Sonderwerte (z. B. -1 = Standard, 0 = niemals). */
  describe?: (value: number) => string | null;
  extra?: (entry: SettingEntry) => ReactNode;
}

const SPECS: SettingSpec[] = [
  {
    key: 'http.max_timeout_seconds',
    title: 'Höchstes Zeitlimit eines Jobs',
    lead: 'Obergrenze in Sekunden (1 bis 3600), die ein HTTP-Job als Zeitlimit wählen darf. Gilt sofort für neue und bestehende Jobs: Ein Job mit höherem Wert läuft nur bis zu diesem Maximum.',
    options: null,
    numberLabel: 'Sekunden',
    extra: () => (
      <Alert tone="info">
        {t('HTTP-Jobs und Shell-Jobs laufen in getrennten Workern. Die Anzahl gleichzeitiger Läufe wird beim Start der Worker festgelegt.') }</Alert>
    ),
  },
  {
    key: 'http.response_storage',
    title: 'Antworten im Verlauf speichern',
    lead: 'Legt fest, ob die Antwort eines HTTP-Aufrufs (maskiert, auf 64 KB gekürzt, nur Text) im Verlauf gespeichert wird.',
    options: [
      { value: 'off', label: 'Aus', help: 'Jobs, die „Wie global“ wählen, speichern nichts; ein Job kann das für sich einschalten.' },
      { value: 'on', label: 'An', help: 'Jobs, die „Wie global“ wählen, speichern die Antwort; ein Job kann das für sich ausschalten.' },
      { value: 'never', label: 'Nie', help: 'Erzwingt „nicht speichern“ für alle Jobs. Die Einstellung am Job wird ignoriert und im Editor als „Vom Administrator abgeschaltet“ gezeigt.' },
    ],
  },
  {
    key: 'http.display_path',
    title: 'Pfad in der URL-Anzeige',
    lead: 'Bestimmt, wie viel vom Pfad einer Adresse in Listen und Detailansichten erscheint. Die Adresse selbst bleibt verschlüsselt gespeichert und wird nie angezeigt. Standard: versteckt.',
    options: [
      { value: 'hidden', label: 'Immer verbergen', help: 'Standard. Es erscheinen nur Protokoll, Host und Port. Pfad und Query sind nie sichtbar.' },
      { value: 'auto', label: 'Automatisch', help: 'Einfache Pfade bleiben sichtbar, mögliche Geheimnisse in Pfad und Query werden durch •••• ersetzt. Restrisiko: Ein Geheimnis, das wie ein einfacher Kleinbuchstaben-Pfad aussieht, kann sichtbar bleiben.' },
    ],
    extra: (entry) => (
      <Alert tone="warn">
        {t('„Immer verbergen“ verschärft bestehende Jobs sofort: Ihre gespeicherten Anzeige-URLs werden gekürzt. Das Zurückstellen auf „Automatisch“ lockert nichts nachträglich: Bereits gekürzte Anzeigen kommen nicht zurück, erst neu gespeicherte Anfragen nutzen wieder die automatische Anzeige.')}
        {entry.value === 'hidden' ? ' ' + t('Aktuell ist „Immer verbergen“ aktiv.') : ''}
      </Alert>
    ),
  },
  {
    key: 'http.display_host',
    title: 'Host in der URL-Anzeige',
    lead: 'Bestimmt, ob der Host (und Port) eines Ziels in Listen, Übersicht und Anzeige-URL erscheint. Verborgen zeigt nur noch das Protokoll, z. B. https://••••, für alle Rollen. Standard: automatisch (Host sichtbar).',
    options: [
      { value: 'auto', label: 'Automatisch', help: 'Standard. Der Host bleibt in Listen und Anzeige sichtbar.' },
      { value: 'hidden', label: 'Immer verbergen', help: 'Host und Port erscheinen nirgends mehr, auch nicht für Administratoren. Den Host sieht nur, wer die Anfrage neu eingibt. Auch die Ausgabe neuer Läufe nennt ihn nicht.' },
    ],
    extra: (entry) => (
      <Alert tone="warn">
        {t('„Immer verbergen“ verschärft bestehende Jobs sofort: Ihre gespeicherten Anzeige-URLs verlieren den Host. Das Zurückstellen auf „Automatisch“ lockert nichts nachträglich: Bereits verborgene Anzeigen kommen nicht zurück, erst neu gespeicherte Anfragen zeigen den Host wieder.')}
        {entry.value === 'hidden' ? ' ' + t('Aktuell ist „Immer verbergen“ aktiv.') : ''}
      </Alert>
    ),
  },
];

const SHELL_SPECS: SettingSpec[] = [
  {
    key: 'shell.max_timeout_seconds',
    title: 'Höchstes Zeitlimit eines Shell-Jobs',
    lead: 'Obergrenze in Sekunden (1 bis 86400), die ein Shell-Job als Zeitlimit wählen darf. Gilt sofort für neue und bestehende Jobs: Ein Job mit höherem Wert läuft nur bis zu diesem Maximum. Standard: 3600 Sekunden.',
    options: null,
    numberLabel: 'Sekunden',
  },
];

const EDITOR_SPECS: SettingSpec[] = [
  {
    key: 'jobs.reveal_for_edit',
    title: 'Gespeicherte Skripte und Links im Editor anzeigen',
    lead: 'Bestimmt, ob der Job-Editor URL, Header, Body, Skript und Umgebungsvariablen eines gespeicherten Jobs vorbefüllt und bearbeitbar zeigt. Aus: Diese Werte sind nach dem Speichern nicht mehr lesbar und lassen sich nur als Ganzes ersetzen. Standard: aus.',
    options: [
      { value: 'off', label: 'Aus', help: 'Standard. Gespeicherte Werte erscheinen nirgends; im Editor gibt es nur „Anfrage ersetzen“ bzw. „Skript ersetzen“.' },
      { value: 'on', label: 'An', help: 'Wer einen Job bearbeiten darf, sieht beim Öffnen des Editors die gespeicherten Werte im Klartext. Beobachter und Benutzer ohne Bearbeitungsrecht für die Kategorie nie.' },
    ],
    extra: (entry) => (
      <Alert tone="warn">
        <strong>{t('Achtung:')}</strong> {t('Wer bearbeiten darf, kann Geheimnisse im Klartext sehen: Tokens in URLs, Header-Werte, Skripte und Umgebungsvariablen. Jeder Abruf wird im Audit-Log protokolliert („job.source_viewed“), höchstens 60 Abrufe je Benutzer und Stunde. Standard ist aus.')}
        {entry.value === 'on' ? ' ' + t('Aktuell ist die Anzeige eingeschaltet.') : ''}
      </Alert>
    ),
  },
];

const APP_SPECS: SettingSpec[] = [
  {
    key: 'auth.session_days',
    title: 'Sitzungsdauer',
    lead: 'Wie lange eine Anmeldung gilt. Standard: 2 Stunden ohne Aktivität, höchstens 12 Stunden. Mit einer Zahl von 1 bis 365 gilt die Anmeldung so viele Tage; 0 heißt: läuft nie ab (der Browser behält das Cookie höchstens 400 Tage). Die neue Dauer gilt für neue Anmeldungen, ein kürzerer Leerlauf wirkt sofort. „Zurücksetzen“ stellt den Standard wieder her.',
    options: null,
    numberLabel: 'Tage (0 = niemals)',
    unit: 'Tage',
    describe: (value) => (value < 0 ? t('Standard (2 Std. Leerlauf, 12 Std. gesamt)') : value === 0 ? t('niemals ablaufen') : null),
    extra: () => <Alert tone="warn">{t('Lange Sitzungen sind bequem, aber ein gestohlenes Cookie gilt dann ebenso lange. Besser sparsam einstellen; Benutzer können eigene Sitzungen unter „Mein Konto“ beenden.')}</Alert>,
  },
  {
    key: 'jobs.min_interval_seconds',
    title: 'Kleinster Abstand fester Intervalle',
    lead: 'Wie klein der Abstand „Alle n Sekunden“ beim Speichern eines Jobs sein darf. Kurze Abstände belasten Meridian und das Ziel und erzeugen viele Läufe im Verlauf (bei 10 s über 8.000 am Tag und Job). 1 bis 3600 Sekunden, Standard 10. Schon gespeicherte Jobs laufen unverändert weiter.',
    options: null,
    numberLabel: 'Sekunden',
    unit: 'Sekunden',
  },
  {
    key: 'runs.retention_days',
    title: 'Aufbewahrung einzelner Läufe',
    lead: 'Wie viele Tage einzelne Läufe (mit Ausgabe und Live-Log) im Verlauf bleiben. Ältere Läufe werden zu Tageswerten je Job zusammengefasst und gelöscht: Die Statistik bleibt, Ausgabe und Einzelheiten sind danach nicht mehr da. 1 bis 3650 Tage, Standard 30.',
    options: null,
    numberLabel: 'Tage',
    unit: 'Tage',
    extra: () => <Alert tone="warn">{t('Eine kürzere Zeit löscht ältere Läufe beim nächsten Durchgang (innerhalb von 10 Minuten). Das lässt sich nicht rückgängig machen.')}</Alert>,
  },
  {
    key: 'app.public_url',
    title: 'Adresse von Meridian',
    lead: 'Unter welcher Adresse du Meridian im Browser öffnest, z. B. http://192.168.0.110:8090 oder https://meridian.example.org. Sie wird für den Link zum Job in Benachrichtigungen gebraucht. Leer = Nachrichten enthalten keinen Link.',
    options: null,
    numberLabel: 'Adresse',
    text: true,
  },
];

function optionLabel(spec: SettingSpec, value: number | string): string {
  if (spec.text === true) {
    return value === '' ? t('(nicht gesetzt)') : String(value);
  }
  if (spec.options === null) {
    const special = spec.describe !== undefined && typeof value === 'number' ? spec.describe(value) : null;
    if (special !== null) {
      return t(special);
    }
    return String(value) + ' ' + t(spec.unit ?? 's');
  }
  const found = spec.options.find((option) => option.value === value)?.label;
  return found === undefined ? String(value) : t(found);
}

function SettingCard({ spec, entry, csrf, onSaved }: { spec: SettingSpec; entry: SettingEntry; csrf: string; onSaved: (entry: SettingEntry) => void }) {
  const [draft, setDraft] = useState(String(entry.value));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>(undefined);
  const [done, setDone] = useState<string | null>(null);
  const headingId = useId();

  // Nach dem Speichern oder Zurücksetzen zeigt das Feld wieder den Wert des Servers.
  useEffect(() => {
    setDraft(String(entry.value));
  }, [entry.value]);

  async function send(value: number | string | null): Promise<void> {
    setBusy(true);
    setError(null);
    setFieldError(undefined);
    setDone(null);
    try {
      const result = await request<SettingChange>('PUT', '/api/settings/' + encodeURIComponent(spec.key), { csrf, body: { value } });
      onSaved(result.setting);
      const tightened = result.tightened_jobs;
      setDone(
        (value === null ? t('Auf den Standard zurückgesetzt.') : 'Gespeichert.') +
          (tightened > 0 ? ' ' + t('Die Anzeige wurde bei {n} verschärft.', { n: String(tightened) + ' ' + (tightened === 1 ? t('Job') : t('Jobs')) }) : ''),
      );
    } catch (caught) {
      if (caught instanceof ApiError && caught.fields.value !== undefined) {
        setFieldError(caught.fields.value);
      } else {
        setError(errorMessage(caught));
      }
    } finally {
      setBusy(false);
    }
  }

  function submit(event: FormEvent): void {
    event.preventDefault();
    // Zahlen als Zahl senden; alles andere unverändert, damit der Server die feste Meldung liefert.
    const value = spec.options === null && spec.text !== true && /^[0-9]{1,6}$/.test(draft.trim()) ? Number.parseInt(draft, 10) : draft.trim();
    void send(value);
  }

  const help = spec.options?.find((option) => option.value === draft)?.help;
  const changed = draft !== String(entry.value);

  return (
    <form className="setting" onSubmit={submit} aria-labelledby={headingId}>
      <div className="setting__head">
        <h3 id={headingId}>{t(spec.title)}</h3>
        <span className={'badge ' + (entry.source === 'stored' ? 'badge--warn' : 'badge--ok')}>{entry.source === 'stored' ? t('Geändert') : t('Standard')}</span>
      </div>
      <ExpandableLead text={t(spec.lead)} />
      <dl className="facts">
        <dt>{t('Aktuell')}</dt>
        <dd>
          <strong>{optionLabel(spec, entry.value)}</strong>
        </dd>
        <dt>{t('Standard')}</dt>
        <dd>{optionLabel(spec, entry.default)}</dd>
        {entry.source === 'stored' && entry.updated_at !== null && (
          <>
            <dt>{t('Geändert')}</dt>
            <dd>
              {formatTime(entry.updated_at)}
              {entry.updated_by !== null ? ' ' + t('von') + ' ' + entry.updated_by.display_name : ''}
            </dd>
          </>
        )}
      </dl>
      {error !== null && <Alert tone="err">{error}</Alert>}
      {done !== null && <Alert tone="ok">{done}</Alert>}
      <FormField label={t(spec.numberLabel ?? 'Neuer Wert')} error={fieldError} hint={help === undefined ? undefined : t(help)}>
        {(aria) =>
          spec.options === null ? (
            <input {...aria} className={spec.text === true ? 'input' : 'input input--short'} name={spec.key} inputMode={spec.text === true ? 'url' : 'numeric'} autoComplete="off" value={draft} onChange={(e) => setDraft(e.target.value)} />
          ) : (
            <select {...aria} className="input input--short" name={spec.key} value={draft} onChange={(e) => setDraft(e.target.value)}>
              {spec.options.map((option) => (
                <option key={option.value} value={option.value}>
                  {t(option.label)}
                </option>
              ))}
            </select>
          )
        }
      </FormField>
      {spec.extra?.(entry)}
      <div className="row">
        <button type="submit" className="btn btn--solid" disabled={busy || !changed}>
          {busy ? t('Speichert …') : t('Speichern')}
        </button>
        <button type="button" className="btn btn--ghost" disabled={busy || entry.source === 'default'} onClick={() => void send(null)}>
          {t('Auf Standard zurücksetzen')}</button>
      </div>
    </form>
  );
}

function SettingsGroup({ id, title, lead, specs, csrf }: { id: string; title: string; lead: string; specs: SettingSpec[]; csrf: string }) {
  const [entries, setEntries] = useState<SettingEntry[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    request<{ settings: SettingEntry[] }>('GET', '/api/settings')
      .then((data) => {
        if (!cancelled) {
          setEntries(data.settings);
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setError(errorMessage(caught));
        }
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const replace = useCallback((entry: SettingEntry): void => {
    setEntries((current) => current?.map((old) => (old.key === entry.key ? entry : old)) ?? current);
  }, []);

  return (
    <section className="card" aria-labelledby={id}>
      <h2 id={id}>{title}</h2>
      <p className="card__lead">{lead}</p>
      {error !== null && <Alert tone="err">{error}</Alert>}
      {entries === null && error === null && <p className="hint">{t('Lädt …')}</p>}
      {entries !== null &&
        specs.map((spec) => {
          const entry = entries.find((candidate) => candidate.key === spec.key);
          return entry === undefined ? null : <SettingCard key={spec.key} spec={spec} entry={entry} csrf={csrf} onSaved={replace} />;
        })}
    </section>
  );
}

function HttpSettings({ csrf }: { csrf: string }) {
  return (
    <SettingsGroup
      id="settings-http"
      title={t('HTTP-Jobs')}
      lead={t('Globale Grenzwerte für alle HTTP-Jobs. Änderungen gelten beim nächsten Lauf ohne Neustart und werden im Audit-Log festgehalten.')}
      specs={SPECS}
      csrf={csrf}
    />
  );
}

function ShellSettings({ csrf }: { csrf: string }) {
  return (
    <SettingsGroup
      id="settings-shell"
      title={t('Shell-Jobs')}
      lead={t('Globale Grenzwerte für alle Shell-Jobs. Änderungen gelten beim nächsten Lauf ohne Neustart und werden im Audit-Log festgehalten.')}
      specs={SHELL_SPECS}
      csrf={csrf}
    />
  );
}

function AppSettings({ csrf }: { csrf: string }) {
  return <SettingsGroup id="settings-app" title={t('Allgemein')} lead={t('Angaben, die Meridian über sich selbst braucht. Änderungen gelten sofort und werden im Audit-Log festgehalten.')} specs={APP_SPECS} csrf={csrf} />;
}

function EditorSettings({ csrf }: { csrf: string }) {
  return (
    <SettingsGroup
      id="settings-editor"
      title={t('Job-Editor')}
      lead={t('Was der Job-Editor von gespeicherten Jobs zeigt. Änderungen gelten sofort und werden im Audit-Log festgehalten.')}
      specs={EDITOR_SPECS}
      csrf={csrf}
    />
  );
}

const EMPTY_FORM = { kind: 'cidr', value: '', port: '', categoryId: '', note: '' };

function scopeLabel(target: InternalTarget): string {
  return target.category === null ? t('Global (alle Kategorien)') : t('Kategorie „{name}“', { name: target.category.name });
}

function InternalTargets({ csrf }: { csrf: string }) {
  const [targets, setTargets] = useState<InternalTarget[] | null>(null);
  const [categories, setCategories] = useState<CategoryRef[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState<InternalTarget | null>(null);

  useEffect(() => {
    let cancelled = false;
    async function load(): Promise<void> {
      try {
        const [list, cats] = await Promise.all([
          request<{ targets: InternalTarget[] }>('GET', '/api/settings/internal-targets'),
          request<{ categories: CategoryRef[] }>('GET', '/api/categories?permission=jobs.view'),
        ]);
        if (!cancelled) {
          setTargets(list.targets);
          setCategories(cats.categories);
        }
      } catch (caught) {
        if (!cancelled) {
          setLoadError(errorMessage(caught));
        }
      }
    }
    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  function set<K extends keyof typeof EMPTY_FORM>(key: K, value: string): void {
    setForm((current) => ({ ...current, [key]: value }));
  }

  function startEdit(target: InternalTarget): void {
    setEditing(target);
    setErrors({});
    setFormError(null);
    setNotice(null);
    setForm({ kind: target.kind, value: target.value, port: target.port === 0 ? '' : String(target.port), categoryId: target.category === null ? '' : String(target.category.id), note: target.note });
    document.getElementById('settings-targets-add')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  function cancelEdit(): void {
    setEditing(null);
    setErrors({});
    setFormError(null);
    setForm(EMPTY_FORM);
  }

  async function save(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setFormError(null);
    setNotice(null);
    const portText = form.port.trim();
    // Leer = alle Ports (0). Ziffern als Zahl; sonstiges unverändert, damit der Server die feste Meldung liefert.
    const port = portText === '' ? 0 : /^[0-9]{1,6}$/.test(portText) ? Number.parseInt(portText, 10) : portText;
    try {
      const result = await request<{ target: InternalTarget }>(editing === null ? 'POST' : 'PUT', '/api/settings/internal-targets' + (editing === null ? '' : '/' + String(editing.id)), {
        csrf,
        body: { kind: form.kind, value: form.value.replace(/[\s\u200B-\u200F\u202A-\u202E\u2060\uFEFF]/g, ''), port, category_id: form.categoryId === '' ? null : Number.parseInt(form.categoryId, 10), note: form.note.trim() },
      });
      setTargets((current) => editing === null ? [...(current ?? []), result.target] : current?.map((target) => target.id === editing.id ? result.target : target) ?? current);
      setEditing(null);
      setForm({ ...EMPTY_FORM, kind: form.kind });
      setNotice(editing === null ? t('Freigabe angelegt. Sie gilt ab dem nächsten Lauf.') : t('Freigabe geändert. Sie gilt ab dem nächsten Lauf.'));
    } catch (caught) {
      if (caught instanceof ApiError && Object.keys(caught.fields).length > 0) {
        setErrors(caught.fields);
        setFormError(caught.message);
      } else {
        setFormError(errorMessage(caught));
      }
    } finally {
      setBusy(false);
    }
  }

  async function remove(target: InternalTarget): Promise<void> {
    setBusy(true);
    setFormError(null);
    setNotice(null);
    try {
      await request<null>('DELETE', '/api/settings/internal-targets/' + String(target.id), { csrf });
      setTargets((current) => current?.filter((entry) => entry.id !== target.id) ?? current);
      if (editing?.id === target.id) { cancelEdit(); }
      setNotice(t('Freigabe entfernt. Sie gilt ab dem nächsten Lauf nicht mehr.'));
    } catch (caught) {
      setFormError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card" aria-labelledby="settings-targets">
      <h2 id="settings-targets">{t('Freigaben interner Ziele')}</h2>
      <p className="card__lead">
        {t('HTTP-Jobs erreichen Adressen in privaten Netzen und auf dem eigenen Rechner nur, wenn hier eine passende Freigabe besteht. Eine Freigabe gilt global oder für genau eine Kategorie, optional nur für einen Port.')}</p>
      <Alert tone="warn">
        {t('Jede Freigabe lockert den Schutz vor Zugriffen auf interne Dienste: Wer Jobs in diesem Geltungsbereich bearbeiten darf, kann diesen Dienst aufrufen. Gib nur Ziele frei, die dafür gedacht sind, und wähle möglichst eine Kategorie und einen Port.')}</Alert>
      <p className="hint">
        {t('Nie freigebbar sind Link-local-Adressen (169.254.0.0/16, fe80::/10) einschließlich der Metadaten-Dienste von Cloud-Anbietern, unspezifizierte, Multicast-, Broadcast- und reservierte Bereiche sowie Meridians eigene Infrastruktur: der Docker-Socket-Proxy (jeder Port), die Docker-API-Ports 2375 und 2376 sowie Meridians eigener Port auf dem eigenen Rechner. Hostnamen geben nur private Adressen frei, Loopback nur als einzelne Adresse mit Port. Wird eine Kategorie gelöscht, entfallen ihre Freigaben, sie werden nie global.')}</p>

      {loadError !== null && <Alert tone="err">{loadError}</Alert>}
      {notice !== null && <Alert tone="ok">{notice}</Alert>}
      {targets === null && loadError === null && <p className="hint">{t('Lädt …')}</p>}
      {targets !== null && (
        <div className="table-wrap">
          <table className="table table--stack">
            <caption className="visually-hidden">{t('Freigegebene interne Ziele')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('Ziel')}</th>
                <th scope="col">{t('Port')}</th>
                <th scope="col">{t('Gilt für')}</th>
                <th scope="col">{t('Notiz')}</th>
                <th scope="col">{t('Angelegt')}</th>
                <th scope="col">
                  <span className="visually-hidden">{t('Aktion')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {targets.map((target) => (
                <tr key={target.id}>
                  <td data-label="Ziel">
                    <span className="mono">{target.value}</span> <span className="hint">({target.kind === 'cidr' ? t('Netz') : t('Hostname')})</span>
                  </td>
                  <td data-label="Port" className="mono">
                    {target.port === 0 ? 'alle' : target.port}
                  </td>
                  <td data-label="Gilt für">{scopeLabel(target)}</td>
                  <td data-label="Notiz">{target.note === '' ? <span className="hint">–</span> : target.note}</td>
                  <td data-label="Angelegt">
                    {formatTime(target.created_at)}
                    {target.created_by !== null && <span className="hint"> · {target.created_by.display_name}</span>}
                  </td>
                  <td className="targets__action-cell">
                    <div className="targets__actions">
                      <button type="button" className="btn btn--ghost" disabled={busy} aria-label={t('Freigabe {name} bearbeiten', { name: target.value })} onClick={() => startEdit(target)}>{t('Bearbeiten')}</button>
                      <Confirm
                        label={t('Entfernen')}
                        accessibleName={t('Freigabe {name} entfernen', { name: target.value })}
                        question={t('Freigabe entfernen?')}
                        confirmLabel={t('Ja, entfernen')}
                        busy={busy}
                        disabled={busy}
                        onConfirm={() => void remove(target)}
                      />
                    </div>
                  </td>
                </tr>
              ))}
              {targets.length === 0 && (
                <tr>
                  <td colSpan={6} className="hint">
                    {t('Keine Freigaben: Alle internen Ziele sind gesperrt.')}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      <form className="form targets__form" onSubmit={(event) => void save(event)} aria-labelledby="settings-targets-add">
        <h3 id="settings-targets-add">{editing === null ? t('Freigabe hinzufügen') : t('Freigabe bearbeiten')}</h3>
        {formError !== null && <Alert tone="err">{formError}</Alert>}
        <div className="grid-2">
          <FormField label={t('Art')} error={errors.kind}>
            {(aria) => (
              <select {...aria} className="input" name="kind" value={form.kind} onChange={(e) => set('kind', e.target.value)}>
                <option value="cidr">{t('Netz oder Adresse (CIDR)')}</option>
                <option value="host">{t('Hostname')}</option>
              </select>
            )}
          </FormField>
          <FormField
            label={form.kind === 'cidr' ? t('Netz oder Adresse') : t('Hostname')}
            error={errors.value}
            hint={form.kind === 'cidr' ? t('z. B. 192.168.10.0/24 oder 127.0.0.1/32') : t('z. B. nextcloud oder intranet.firma.local')}
          >
            {(aria) => (
              <input {...aria} className="input input--mono" name="value" required autoComplete="off" spellCheck={false} value={form.value} onChange={(e) => set('value', e.target.value)} />
            )}
          </FormField>
        </div>
        <div className="grid-2">
          <FormField label={t('Port')} error={errors.port} hint={t('Leer = alle Ports. Für Loopback ist ein Port nötig.')}>
            {(aria) => <input {...aria} className="input input--short" name="port" inputMode="numeric" autoComplete="off" value={form.port} onChange={(e) => set('port', e.target.value)} />}
          </FormField>
          <FormField label={t('Gilt für')} error={errors.category_id} hint={t('„Global“ erlaubt das Ziel allen Jobs, auch Jobs ohne Kategorie.')}>
            {(aria) => (
              <select {...aria} className="input" name="category" value={form.categoryId} onChange={(e) => set('categoryId', e.target.value)}>
                <option value="">{t('Global (alle Kategorien)')}</option>
                {categories.map((category) => (
                  <option key={category.id} value={String(category.id)}>
                    {t('Kategorie „{name}“', { name: category.name })}
                  </option>
                ))}
              </select>
            )}
          </FormField>
        </div>
        <FormField label={t('Notiz')} error={errors.note} hint={t('Wofür ist die Freigabe? (optional)')}>
          {(aria) => <input {...aria} className="input" name="note" maxLength={200} autoComplete="off" value={form.note} onChange={(e) => set('note', e.target.value)} />}
        </FormField>
        {errors.body !== undefined && <Alert tone="err">{errors.body}</Alert>}
        <div className="row">
          <button type="submit" className="btn btn--solid" disabled={busy || form.value.trim() === ''}>
            {busy ? t('Speichert …') : editing === null ? t('Freigabe anlegen') : t('Änderung speichern')}
          </button>
          {editing !== null && <button type="button" className="btn btn--ghost" disabled={busy} onClick={cancelEdit}>{t('Abbrechen')}</button>}
        </div>
      </form>
    </section>
  );
}

const EMPTY_SHELL_FORM = { kind: 'docker', name: '', categoryId: '', users: '', defaultUser: '', note: '' };

function shellScopeLabel(target: ShellTargetEntry): string {
  return target.category === null ? t('Global (alle Kategorien)') : t('Kategorie „{name}“', { name: target.category.name });
}

/** Benutzerliste aus Kommas, Leerzeichen oder Zeilen; Leereinträge fallen weg, geprüft wird auf dem Server. */
function splitUsers(text: string): string[] {
  return text
    .split(/[\s,]+/)
    .map((entry) => entry.trim())
    .filter((entry) => entry !== '');
}

function ShellTargets({ csrf }: { csrf: string }) {
  const [targets, setTargets] = useState<ShellTargetEntry[] | null>(null);
  const [categories, setCategories] = useState<CategoryRef[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [form, setForm] = useState(EMPTY_SHELL_FORM);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState<ShellTargetEntry | null>(null);

  useEffect(() => {
    let cancelled = false;
    async function load(): Promise<void> {
      try {
        const [list, cats] = await Promise.all([
          request<{ targets: ShellTargetEntry[] }>('GET', '/api/settings/shell-targets'),
          request<{ categories: CategoryRef[] }>('GET', '/api/categories?permission=jobs.view'),
        ]);
        if (!cancelled) {
          setTargets(list.targets);
          setCategories(cats.categories);
        }
      } catch (caught) {
        if (!cancelled) {
          setLoadError(errorMessage(caught));
        }
      }
    }
    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  function set<K extends keyof typeof EMPTY_SHELL_FORM>(key: K, value: string): void {
    setForm((current) => ({ ...current, [key]: value }));
  }

  const docker = form.kind === 'docker';
  const users = splitUsers(form.users);
  const rootChosen = docker && (users.some((user) => user === 'root' || /^0(:|$)/.test(user)) || form.defaultUser.trim() === 'root' || /^0(:|$)/.test(form.defaultUser.trim()));

  function startEdit(target: ShellTargetEntry): void {
    setEditing(target);
    setErrors({});
    setFormError(null);
    setNotice(null);
    setForm({
      kind: target.kind,
      name: target.name,
      categoryId: target.category === null ? '' : String(target.category.id),
      users: target.users.join(', '),
      defaultUser: target.default_user ?? '',
      note: target.note,
    });
  }

  function cancelEdit(): void {
    setEditing(null);
    setErrors({});
    setFormError(null);
    setForm(EMPTY_SHELL_FORM);
  }

  async function save(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setFormError(null);
    setNotice(null);
    const body = {
      kind: form.kind,
      name: form.name.trim(),
      category_id: form.categoryId === '' ? null : Number.parseInt(form.categoryId, 10),
      users: docker ? users : [],
      default_user: docker && form.defaultUser.trim() !== '' ? form.defaultUser.trim() : null,
      note: form.note.trim(),
    };
    try {
      if (editing !== null) {
        const result = await request<{ target: ShellTargetEntry; affected_jobs: number }>('PUT', '/api/settings/shell-targets/' + String(editing.id), { csrf, body });
        setTargets((current) => current?.map((entry) => (entry.id === result.target.id ? result.target : entry)) ?? current);
        setEditing(null);
        setForm(EMPTY_SHELL_FORM);
        setNotice(
          'Ausführungsort geändert. ' +
            String(result.affected_jobs) +
            ' Job(s) verwendeten den bisherigen Ort; zeigt ein Job nicht mehr auf einen freigegebenen Ort oder Benutzer, schlägt er ab dem nächsten Lauf fehl, bis er angepasst ist.',
        );
        return;
      }
      const result = await request<{ target: ShellTargetEntry }>('POST', '/api/settings/shell-targets', { csrf, body });
      setTargets((current) => [...(current ?? []), result.target]);
      setForm({ ...EMPTY_SHELL_FORM, kind: form.kind });
      setNotice(t('Ausführungsort angelegt. Er steht ab sofort im Job-Editor zur Wahl.'));
    } catch (caught) {
      if (caught instanceof ApiError && Object.keys(caught.fields).length > 0) {
        setErrors(caught.fields);
        setFormError(caught.message);
      } else {
        setFormError(errorMessage(caught));
      }
    } finally {
      setBusy(false);
    }
  }

  async function remove(target: ShellTargetEntry): Promise<void> {
    setBusy(true);
    setFormError(null);
    setNotice(null);
    try {
      await request<null>('DELETE', '/api/settings/shell-targets/' + String(target.id), { csrf });
      setTargets((current) => current?.filter((entry) => entry.id !== target.id) ?? current);
      if (editing?.id === target.id) {
        cancelEdit();
      }
      setNotice(t('Ausführungsort entfernt. Jobs, die ihn nutzen, schlagen ab dem nächsten Lauf fehl, bis ein anderer gewählt ist.'));
    } catch (caught) {
      setFormError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card" aria-labelledby="settings-shell-targets">
      <h2 id="settings-shell-targets">{t('Ausführungsorte')}</h2>
      <p className="card__lead">
        {t('Shell-Jobs laufen nie in Meridian selbst, sondern nur an einem hier freigegebenen Ort: in einem Docker-Container oder als Host-Profil. Eine Freigabe gilt global oder für genau eine Kategorie.')}</p>
      <Alert tone="warn">
        {t('Eine Freigabe erlaubt Administratoren mit dem Recht „Shell-Jobs bearbeiten“ beliebige Befehle an diesem Ort. Bei Containern muss der Container laufen und der Name exakt stimmen. Host-Profile benötigen zusätzlich einen eingerichteten Host-Dienst. Meridians eigene Container (meridian-…, außer meridian-sandbox) sind gesperrt. Gib nur Orte frei, die dafür gedacht sind, und wähle möglichst eine Kategorie.')}</Alert>
      <p className="hint">
        <strong>{t('root nur ausdrücklich:')}</strong> {t('Trage root (oder die Benutzer-ID 0) nur ein, wenn es unbedingt nötig ist. Bei Container-Jobs kann ohne diesen Eintrag kein Job als root laufen. Bei Host-Profilen legt der Host-Dienst den Benutzer fest. Wird eine Kategorie gelöscht, entfallen ihre Freigaben, sie werden nie global.')}
      </p>

      {loadError !== null && <Alert tone="err">{loadError}</Alert>}
      {notice !== null && <Alert tone="ok">{notice}</Alert>}
      {targets === null && loadError === null && <p className="hint">{t('Lädt …')}</p>}
      {targets !== null && (
        <div className="table-wrap">
          <table className="table table--stack">
            <caption className="visually-hidden">{t('Freigegebene Ausführungsorte')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('Art')}</th>
                <th scope="col">{t('Name')}</th>
                <th scope="col">{t('Gilt für')}</th>
                <th scope="col">{t('Benutzer (Standard)')}</th>
                <th scope="col">{t('Notiz')}</th>
                <th scope="col">{t('Angelegt')}</th>
                <th scope="col">
                  <span className="visually-hidden">{t('Aktion')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {targets.map((target) => (
                <tr key={target.id}>
                  <td data-label="Art">{target.kind === 'docker' ? 'Container' : 'Host'}</td>
                  <td data-label="Name" className="mono">
                    {target.name}
                  </td>
                  <td data-label="Gilt für">{shellScopeLabel(target)}</td>
                  <td data-label="Benutzer">
                    {target.kind === 'host' ? (
                      <span className="hint">{t('fest (Host-Dienst)')}</span>
                    ) : (
                      <>
                        <span className="mono">{target.users.join(', ')}</span>
                        {target.default_user !== null && <span className="hint"> ({t('Standard')}: {target.default_user})</span>}
                        {target.allows_root && <span className="badge badge--danger"> {t('root erlaubt')}</span>}
                      </>
                    )}
                  </td>
                  <td data-label="Notiz">{target.note === '' ? <span className="hint">–</span> : target.note}</td>
                  <td data-label="Angelegt">
                    {formatTime(target.created_at)}
                    {target.created_by !== null && <span className="hint"> · {target.created_by.display_name}</span>}
                  </td>
                  <td className="targets__action-cell">
                    <div className="targets__actions">
                      <button type="button" className="btn btn--ghost" disabled={busy} aria-label={t('Ausführungsort {name} bearbeiten', { name: target.name })} onClick={() => startEdit(target)}>
                        {t('Bearbeiten')}</button>
                      <Confirm
                        label={t('Entfernen')}
                        accessibleName={t('Ausführungsort {name} entfernen', { name: target.name })}
                        question={t('Ausführungsort entfernen?')}
                        disabled={busy}
                        confirmLabel={t('Ja, entfernen')}
                        busy={busy}
                        onConfirm={() => void remove(target)}
                      />
                    </div>
                  </td>
                </tr>
              ))}
              {targets.length === 0 && (
                <tr>
                  <td colSpan={7} className="hint">
                    {t('Keine Ausführungsorte: Shell-Jobs lassen sich nicht anlegen.')}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      <form className="form targets__form" onSubmit={(event) => void save(event)} aria-labelledby="settings-shell-targets-add">
        <h3 id="settings-shell-targets-add">{editing === null ? t('Ausführungsort hinzufügen') : t('Ausführungsort bearbeiten')}</h3>
        {editing !== null && (
          <p className="hint">
            {t('Die Art lässt sich nicht ändern. Jobs, die auf den bisherigen Namen, Geltungsbereich oder Benutzer zeigen, werden ab dem nächsten Lauf abgelehnt, bis sie angepasst sind.')}</p>
        )}
        {formError !== null && <Alert tone="err">{formError}</Alert>}
        <div className="grid-2">
          <FormField label={t('Art')} error={errors.kind}>
            {(aria) => (
              <select {...aria} className="input" name="shell-kind" value={form.kind} disabled={editing !== null} onChange={(e) => set('kind', e.target.value)}>
                <option value="docker">{t('Docker-Container')}</option>
                <option value="host">{t('Host-Profil')}</option>
              </select>
            )}
          </FormField>
          <FormField
            label={docker ? 'Name des Containers' : 'Name des Host-Profils'}
            error={errors.name}
            hint={docker ? t('Genau der Containername, z. B. nextcloud.') : 'z. B. default'}
          >
            {(aria) => <input {...aria} className="input input--mono" name="shell-name" required autoComplete="off" autoCapitalize="off" spellCheck={false} value={form.name} onChange={(e) => set('name', e.target.value)} />}
          </FormField>
        </div>
        <div className="grid-2">
          <FormField label={t('Gilt für')} error={errors.category_id} hint={t('„Global“ erlaubt den Ort für Jobs in allen Kategorien.')}>
            {(aria) => (
              <select {...aria} className="input" name="shell-category" value={form.categoryId} onChange={(e) => set('categoryId', e.target.value)}>
                <option value="">{t('Global (alle Kategorien)')}</option>
                {categories.map((category) => (
                  <option key={category.id} value={String(category.id)}>
                    {t('Kategorie „{name}“', { name: category.name })}
                  </option>
                ))}
              </select>
            )}
          </FormField>
          {docker ? (
            <FormField label={t('Standardbenutzer')} error={errors.default_user} hint={t('Muss in der Liste der erlaubten Benutzer stehen, z. B. www-data.')}>
              {(aria) => (
                <input {...aria} className="input input--mono" name="shell-default-user" autoComplete="off" autoCapitalize="off" spellCheck={false} value={form.defaultUser} onChange={(e) => set('defaultUser', e.target.value)} />
              )}
            </FormField>
          ) : (
            <div className="field">
              <span className="field__label">{t('Benutzer')}</span>
              <span className="hint">{t('Host-Profile verwenden den fest eingestellten Benutzer des Host-Dienstes. Unter Docker muss der Host-Zugriff zusätzlich mit compose.host.yaml aktiviert werden.')}</span>
            </div>
          )}
        </div>
        {docker && (
          <FormField label={t('Erlaubte Benutzer')} error={errors.users} hint={t('Durch Komma oder Leerzeichen getrennt, 1 bis 10 Einträge, Kleinbuchstaben oder numerische ID (z. B. www-data 1001).')}>
            {(aria) => <input {...aria} className="input input--mono" name="shell-users" autoComplete="off" autoCapitalize="off" spellCheck={false} value={form.users} onChange={(e) => set('users', e.target.value)} />}
          </FormField>
        )}
        {rootChosen && (
          <Alert tone="err">
            <strong>{t('root ist eingetragen.')}</strong> {t('Jobs an diesem Ort können dann als root laufen und im Container alles ändern. Trage root nur ausdrücklich und nur ein, wenn es nötig ist.')}
          </Alert>
        )}
        <FormField label={t('Notiz')} error={errors.note} hint={t('Wofür ist der Ort freigegeben? (optional)')}>
          {(aria) => <input {...aria} className="input" name="shell-note" maxLength={200} autoComplete="off" value={form.note} onChange={(e) => set('note', e.target.value)} />}
        </FormField>
        {errors.body !== undefined && <Alert tone="err">{errors.body}</Alert>}
        <div className="row">
          <button type="submit" className="btn btn--solid" disabled={busy || form.name.trim() === ''}>
            {busy ? t('Speichert …') : editing === null ? t('Ausführungsort anlegen') : t('Änderung speichern')}
          </button>
          {editing !== null && (
            <button type="button" className="btn btn--ghost" disabled={busy} onClick={cancelEdit}>
              {t('Abbrechen')}</button>
          )}
        </div>
      </form>
    </section>
  );
}

export function Settings({ profile }: { profile: Profile }) {
  const settings = canManageSettings(profile);
  const targets = canManageInternalTargets(profile);
  const shellTargets = canManageShellTargets(profile);
  // Nur Bedienkomfort: Der Server prüft die Rechte bei jeder Anfrage selbst.
  if (!settings && !targets && !shellTargets) {
    return <Alert tone="info">{t('Für die Einstellungen fehlt dir das Recht. Sie sind Administratoren vorbehalten.')}</Alert>;
  }
  const csrf = profile.csrf_token;
  const groups: Array<{ id: string; label: string; panel: ReactNode }> = [];
  if (settings) {
    groups.push({ id: 'general', label: 'Allgemein', panel: <AppSettings csrf={csrf} /> });
    groups.push({ id: 'http', label: 'HTTP-Jobs', panel: <HttpSettings csrf={csrf} /> });
    groups.push({ id: 'shell', label: 'Shell-Jobs', panel: <ShellSettings csrf={csrf} /> });
    groups.push({ id: 'editor', label: 'Job-Editor', panel: <EditorSettings csrf={csrf} /> });
    groups.push({ id: 'notify', label: 'Benachrichtigungen', panel: <NotificationChannels csrf={csrf} /> });
    groups.push({ id: 'updates', label: 'Updates', panel: <SystemUpdates csrf={csrf} /> });
  }
  if (targets) {
    groups.push({ id: 'internal', label: 'Interne Ziele', panel: <InternalTargets csrf={csrf} /> });
  }
  if (shellTargets) {
    groups.push({ id: 'locations', label: 'Ausführungsorte', panel: <ShellTargets csrf={csrf} /> });
  }
  return <SettingsTabs groups={groups} />;
}

/** Gruppen-Menü wie im Klickdummy: ein Bereich zugleich, Pfeiltasten wechseln, die Wahl bleibt im Browser (nur eine Kennung). */
function SettingsTabs({ groups }: { groups: Array<{ id: string; label: string; panel: ReactNode }> }) {
  const { available: updateAvailable } = useUpdateAvailability();
  const [chosen, setChosen] = useState<string>(() => {
    try {
      return window.localStorage.getItem('meridian.settings.group') ?? '';
    } catch {
      return '';
    }
  });
  const current = groups.find((g) => g.id === chosen) ?? groups[0];
  if (current === undefined) {
    return null;
  }
  function choose(id: string): void {
    setChosen(id);
    try {
      window.localStorage.setItem('meridian.settings.group', id);
    } catch {
      // ohne Speicher gilt die Wahl nur für diese Seite
    }
  }
  function onKey(event: React.KeyboardEvent<HTMLButtonElement>, index: number): void {
    const step = event.key === 'ArrowDown' || event.key === 'ArrowRight' ? 1 : event.key === 'ArrowUp' || event.key === 'ArrowLeft' ? -1 : 0;
    if (step === 0) {
      return;
    }
    event.preventDefault();
    const next = groups[(index + step + groups.length) % groups.length];
    if (next !== undefined) {
      choose(next.id);
      document.getElementById('settab-' + next.id)?.focus();
    }
  }
  return (
    <div className="settings-layout">
      <div className="settings-menu" role="tablist" aria-orientation="vertical" aria-label={t('Einstellungen')}>
        {groups.map((g, index) => (
          <button
            key={g.id}
            type="button"
            role="tab"
            id={'settab-' + g.id}
            className="settings-menu__item"
            aria-selected={g.id === current.id}
            aria-controls="settings-panel"
            tabIndex={g.id === current.id ? 0 : -1}
            onClick={() => choose(g.id)}
            onKeyDown={(e) => onKey(e, index)}
          >
            {t(g.label)}
            {g.id === 'updates' && updateAvailable && <UpdateAvailableDot />}
          </button>
        ))}
      </div>
      <div className="settings" role="tabpanel" id="settings-panel" aria-labelledby={'settab-' + current.id}>
        {current.panel}
      </div>
    </div>
  );
}
