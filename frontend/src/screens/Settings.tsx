import { useCallback, useEffect, useId, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Alert } from '../components/Alert';
import { Confirm } from '../components/Confirm';
import { FormField } from '../components/FormField';
import { ApiError, request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import { formatTime } from '../lib/format';
import { canManageInternalTargets, canManageSettings, canManageShellTargets } from '../lib/permissions';
import type { CategoryRef, InternalTarget, Profile, SettingChange, SettingEntry, ShellTargetEntry } from '../types';

/** Beschreibung je Einstellung. Der Server kennt dieselbe Allowlist und lehnt alles andere ab. */
interface SettingSpec {
  key: string;
  title: string;
  lead: ReactNode;
  /** Auswahl (Wert → Beschriftung) oder null = Zahl. */
  options: { value: string; label: string; help: string }[] | null;
  numberLabel?: string;
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
        Alle Jobs laufen derzeit nacheinander. Ein Lauf mit langem Zeitlimit kann andere Läufe so lange aufhalten; Termine, die dabei mehr als 5 Minuten überfällig werden, gelten als verpasst.
      </Alert>
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
        „Immer verbergen“ verschärft bestehende Jobs sofort: Ihre gespeicherten Anzeige-URLs werden gekürzt. Das Zurückstellen auf „Automatisch“ lockert nichts nachträglich: Bereits gekürzte Anzeigen
        kommen nicht zurück, erst neu gespeicherte Anfragen nutzen wieder die automatische Anzeige.
        {entry.value === 'hidden' ? ' Aktuell ist „Immer verbergen“ aktiv.' : ''}
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
        „Immer verbergen“ verschärft bestehende Jobs sofort: Ihre gespeicherten Anzeige-URLs verlieren den Host. Das Zurückstellen auf „Automatisch“ lockert nichts nachträglich: Bereits verborgene Anzeigen
        kommen nicht zurück, erst neu gespeicherte Anfragen zeigen den Host wieder.
        {entry.value === 'hidden' ? ' Aktuell ist „Immer verbergen“ aktiv.' : ''}
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
        <strong>Achtung:</strong> Wer bearbeiten darf, kann Geheimnisse im Klartext sehen: Tokens in URLs, Header-Werte, Skripte und Umgebungsvariablen. Jeder Abruf wird im Audit-Log protokolliert
        („job.source_viewed“), höchstens 60 Abrufe je Benutzer und Stunde. Standard ist aus.
        {entry.value === 'on' ? ' Aktuell ist die Anzeige eingeschaltet.' : ''}
      </Alert>
    ),
  },
];

function optionLabel(spec: SettingSpec, value: number | string): string {
  if (spec.options === null) {
    return String(value) + ' s';
  }
  return spec.options.find((option) => option.value === value)?.label ?? String(value);
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
        (value === null ? 'Auf den Standard zurückgesetzt.' : 'Gespeichert.') +
          (tightened > 0 ? ' Die Anzeige wurde bei ' + String(tightened) + (tightened === 1 ? ' Job' : ' Jobs') + ' verschärft.' : ''),
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
    const value = spec.options === null && /^[0-9]{1,6}$/.test(draft.trim()) ? Number.parseInt(draft, 10) : draft;
    void send(value);
  }

  const help = spec.options?.find((option) => option.value === draft)?.help;
  const changed = draft !== String(entry.value);

  return (
    <form className="setting" onSubmit={submit} aria-labelledby={headingId}>
      <div className="setting__head">
        <h3 id={headingId}>{spec.title}</h3>
        <span className={'badge ' + (entry.source === 'stored' ? 'badge--warn' : 'badge--ok')}>{entry.source === 'stored' ? 'Geändert' : 'Standard'}</span>
      </div>
      <p className="card__lead">{spec.lead}</p>
      <dl className="facts">
        <dt>Aktuell</dt>
        <dd>
          <strong>{optionLabel(spec, entry.value)}</strong>
        </dd>
        <dt>Standard</dt>
        <dd>{optionLabel(spec, entry.default)}</dd>
        {entry.source === 'stored' && entry.updated_at !== null && (
          <>
            <dt>Geändert</dt>
            <dd>
              {formatTime(entry.updated_at)}
              {entry.updated_by !== null ? ' von ' + entry.updated_by.display_name : ''}
            </dd>
          </>
        )}
      </dl>
      {error !== null && <Alert tone="err">{error}</Alert>}
      {done !== null && <Alert tone="ok">{done}</Alert>}
      <FormField label={spec.numberLabel ?? 'Neuer Wert'} error={fieldError} hint={help}>
        {(aria) =>
          spec.options === null ? (
            <input {...aria} className="input input--short" name={spec.key} inputMode="numeric" autoComplete="off" value={draft} onChange={(e) => setDraft(e.target.value)} />
          ) : (
            <select {...aria} className="input input--short" name={spec.key} value={draft} onChange={(e) => setDraft(e.target.value)}>
              {spec.options.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          )
        }
      </FormField>
      {spec.extra?.(entry)}
      <div className="row">
        <button type="submit" className="btn btn--solid" disabled={busy || !changed}>
          {busy ? 'Speichert …' : 'Speichern'}
        </button>
        <button type="button" className="btn btn--ghost" disabled={busy || entry.source === 'default'} onClick={() => void send(null)}>
          Auf Standard zurücksetzen
        </button>
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
      {entries === null && error === null && <p className="hint">Lädt …</p>}
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
      title="HTTP-Jobs"
      lead="Globale Grenzwerte für alle HTTP-Jobs. Änderungen gelten beim nächsten Lauf ohne Neustart und werden im Audit-Log festgehalten."
      specs={SPECS}
      csrf={csrf}
    />
  );
}

function ShellSettings({ csrf }: { csrf: string }) {
  return (
    <SettingsGroup
      id="settings-shell"
      title="Shell-Jobs"
      lead="Globale Grenzwerte für alle Shell-Jobs. Änderungen gelten beim nächsten Lauf ohne Neustart und werden im Audit-Log festgehalten."
      specs={SHELL_SPECS}
      csrf={csrf}
    />
  );
}

function EditorSettings({ csrf }: { csrf: string }) {
  return (
    <SettingsGroup
      id="settings-editor"
      title="Job-Editor"
      lead="Was der Job-Editor von gespeicherten Jobs zeigt. Änderungen gelten sofort und werden im Audit-Log festgehalten."
      specs={EDITOR_SPECS}
      csrf={csrf}
    />
  );
}

const EMPTY_FORM = { kind: 'cidr', value: '', port: '', categoryId: '', note: '' };

function scopeLabel(target: InternalTarget): string {
  return target.category === null ? 'Global (alle Kategorien)' : 'Kategorie „' + target.category.name + '“';
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

  async function add(event: FormEvent): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setFormError(null);
    setNotice(null);
    const portText = form.port.trim();
    // Leer = alle Ports (0). Ziffern als Zahl; sonstiges unverändert, damit der Server die feste Meldung liefert.
    const port = portText === '' ? 0 : /^[0-9]{1,6}$/.test(portText) ? Number.parseInt(portText, 10) : portText;
    try {
      const result = await request<{ target: InternalTarget }>('POST', '/api/settings/internal-targets', {
        csrf,
        body: { kind: form.kind, value: form.value.trim(), port, category_id: form.categoryId === '' ? null : Number.parseInt(form.categoryId, 10), note: form.note.trim() },
      });
      setTargets((current) => [...(current ?? []), result.target]);
      setForm({ ...EMPTY_FORM, kind: form.kind });
      setNotice('Freigabe angelegt. Sie gilt ab dem nächsten Lauf.');
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
      setNotice('Freigabe entfernt. Sie gilt ab dem nächsten Lauf nicht mehr.');
    } catch (caught) {
      setFormError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card" aria-labelledby="settings-targets">
      <h2 id="settings-targets">Freigaben interner Ziele</h2>
      <p className="card__lead">
        HTTP-Jobs erreichen Adressen in privaten Netzen und auf dem eigenen Rechner nur, wenn hier eine passende Freigabe besteht. Eine Freigabe gilt global oder für genau eine Kategorie, optional nur für einen Port.
      </p>
      <Alert tone="warn">
        Jede Freigabe lockert den Schutz vor Zugriffen auf interne Dienste: Wer Jobs in diesem Geltungsbereich bearbeiten darf, kann diesen Dienst aufrufen. Gib nur Ziele frei, die dafür gedacht sind, und
        wähle möglichst eine Kategorie und einen Port.
      </Alert>
      <p className="hint">
        Nie freigebbar sind Link-local-Adressen (169.254.0.0/16, fe80::/10) einschließlich der Metadaten-Dienste von Cloud-Anbietern, unspezifizierte, Multicast-, Broadcast- und reservierte Bereiche
        sowie Meridians eigene Infrastruktur: der Docker-Socket-Proxy (jeder Port), die Docker-API-Ports 2375 und 2376 sowie Meridians eigener Port auf dem eigenen Rechner. Hostnamen geben nur private Adressen frei, Loopback nur als einzelne Adresse mit Port. Wird eine Kategorie gelöscht, entfallen ihre Freigaben, sie werden nie global.
      </p>

      {loadError !== null && <Alert tone="err">{loadError}</Alert>}
      {notice !== null && <Alert tone="ok">{notice}</Alert>}
      {targets === null && loadError === null && <p className="hint">Lädt …</p>}
      {targets !== null && (
        <div className="table-wrap">
          <table className="table table--stack">
            <caption className="visually-hidden">Freigegebene interne Ziele</caption>
            <thead>
              <tr>
                <th scope="col">Ziel</th>
                <th scope="col">Port</th>
                <th scope="col">Gilt für</th>
                <th scope="col">Notiz</th>
                <th scope="col">Angelegt</th>
                <th scope="col">
                  <span className="visually-hidden">Aktion</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {targets.map((target) => (
                <tr key={target.id}>
                  <td data-label="Ziel">
                    <span className="mono">{target.value}</span> <span className="hint">({target.kind === 'cidr' ? 'Netz' : 'Hostname'})</span>
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
                  <td>
                    <Confirm
                      label="Entfernen"
                      accessibleName={'Freigabe ' + target.value + ' entfernen'}
                      question="Freigabe entfernen?"
                      confirmLabel="Ja, entfernen"
                      busy={busy}
                      onConfirm={() => void remove(target)}
                    />
                  </td>
                </tr>
              ))}
              {targets.length === 0 && (
                <tr>
                  <td colSpan={6} className="hint">
                    Keine Freigaben: Alle internen Ziele sind gesperrt.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      <form className="form targets__form" onSubmit={(event) => void add(event)} aria-labelledby="settings-targets-add">
        <h3 id="settings-targets-add">Freigabe hinzufügen</h3>
        {formError !== null && <Alert tone="err">{formError}</Alert>}
        <div className="grid-2">
          <FormField label="Art" error={errors.kind}>
            {(aria) => (
              <select {...aria} className="input" name="kind" value={form.kind} onChange={(e) => set('kind', e.target.value)}>
                <option value="cidr">Netz oder Adresse (CIDR)</option>
                <option value="host">Hostname</option>
              </select>
            )}
          </FormField>
          <FormField
            label={form.kind === 'cidr' ? 'Netz oder Adresse' : 'Hostname'}
            error={errors.value}
            hint={form.kind === 'cidr' ? 'z. B. 192.168.10.0/24 oder 127.0.0.1/32' : 'z. B. nextcloud oder intranet.firma.local'}
          >
            {(aria) => (
              <input {...aria} className="input input--mono" name="value" required autoComplete="off" spellCheck={false} value={form.value} onChange={(e) => set('value', e.target.value)} />
            )}
          </FormField>
        </div>
        <div className="grid-2">
          <FormField label="Port" error={errors.port} hint="Leer = alle Ports. Für Loopback ist ein Port nötig.">
            {(aria) => <input {...aria} className="input input--short" name="port" inputMode="numeric" autoComplete="off" value={form.port} onChange={(e) => set('port', e.target.value)} />}
          </FormField>
          <FormField label="Gilt für" error={errors.category_id} hint="„Global“ erlaubt das Ziel allen Jobs, auch Jobs ohne Kategorie.">
            {(aria) => (
              <select {...aria} className="input" name="category" value={form.categoryId} onChange={(e) => set('categoryId', e.target.value)}>
                <option value="">Global (alle Kategorien)</option>
                {categories.map((category) => (
                  <option key={category.id} value={String(category.id)}>
                    Kategorie „{category.name}“
                  </option>
                ))}
              </select>
            )}
          </FormField>
        </div>
        <FormField label="Notiz" error={errors.note} hint="Wofür ist die Freigabe? (optional)">
          {(aria) => <input {...aria} className="input" name="note" maxLength={200} autoComplete="off" value={form.note} onChange={(e) => set('note', e.target.value)} />}
        </FormField>
        {errors.body !== undefined && <Alert tone="err">{errors.body}</Alert>}
        <div className="row">
          <button type="submit" className="btn btn--solid" disabled={busy || form.value.trim() === ''}>
            {busy ? 'Speichert …' : 'Freigabe anlegen'}
          </button>
        </div>
      </form>
    </section>
  );
}

const EMPTY_SHELL_FORM = { kind: 'docker', name: '', categoryId: '', users: '', defaultUser: '', note: '' };

function shellScopeLabel(target: ShellTargetEntry): string {
  return target.category === null ? 'Global (alle Kategorien)' : 'Kategorie „' + target.category.name + '“';
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
      setNotice('Ausführungsort angelegt. Er steht ab sofort im Job-Editor zur Wahl.');
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
      setNotice('Ausführungsort entfernt. Jobs, die ihn nutzen, schlagen ab dem nächsten Lauf fehl, bis ein anderer gewählt ist.');
    } catch (caught) {
      setFormError(errorMessage(caught));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card" aria-labelledby="settings-shell-targets">
      <h2 id="settings-shell-targets">Ausführungsorte</h2>
      <p className="card__lead">
        Shell-Jobs laufen nie in Meridian selbst, sondern nur an einem hier freigegebenen Ort: in einem Docker-Container oder als Host-Profil. Eine Freigabe gilt global oder für genau eine Kategorie.
      </p>
      <Alert tone="warn">
        Eine Freigabe erlaubt Administratoren mit dem Recht „Shell-Jobs bearbeiten“ beliebige Befehle an diesem Ort. Der Container muss zusätzlich in MERIDIAN_SHELL_CONTAINERS stehen. Gib nur Orte frei, die dafür gedacht sind, und wähle
        möglichst eine Kategorie.
      </Alert>
      <p className="hint">
        <strong>root nur ausdrücklich:</strong> Trage root (oder die Benutzer-ID 0) nur ein, wenn es unbedingt nötig ist. Ohne diesen Eintrag kann kein Job als root laufen. Wird eine Kategorie gelöscht, entfallen ihre Freigaben, sie werden nie global.
      </p>

      {loadError !== null && <Alert tone="err">{loadError}</Alert>}
      {notice !== null && <Alert tone="ok">{notice}</Alert>}
      {targets === null && loadError === null && <p className="hint">Lädt …</p>}
      {targets !== null && (
        <div className="table-wrap">
          <table className="table table--stack">
            <caption className="visually-hidden">Freigegebene Ausführungsorte</caption>
            <thead>
              <tr>
                <th scope="col">Art</th>
                <th scope="col">Name</th>
                <th scope="col">Gilt für</th>
                <th scope="col">Benutzer (Standard)</th>
                <th scope="col">Notiz</th>
                <th scope="col">Angelegt</th>
                <th scope="col">
                  <span className="visually-hidden">Aktion</span>
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
                      <span className="hint">fest (meridian-run)</span>
                    ) : (
                      <>
                        <span className="mono">{target.users.join(', ')}</span>
                        {target.default_user !== null && <span className="hint"> (Standard: {target.default_user})</span>}
                        {target.allows_root && <span className="badge badge--danger"> root erlaubt</span>}
                      </>
                    )}
                  </td>
                  <td data-label="Notiz">{target.note === '' ? <span className="hint">–</span> : target.note}</td>
                  <td data-label="Angelegt">
                    {formatTime(target.created_at)}
                    {target.created_by !== null && <span className="hint"> · {target.created_by.display_name}</span>}
                  </td>
                  <td>
                    <button type="button" className="btn btn--ghost" disabled={busy} aria-label={'Ausführungsort ' + target.name + ' bearbeiten'} onClick={() => startEdit(target)}>
                      Bearbeiten
                    </button>{' '}
                    <Confirm
                      label="Entfernen"
                      accessibleName={'Ausführungsort ' + target.name + ' entfernen'}
                      question="Ausführungsort entfernen?"
                      confirmLabel="Ja, entfernen"
                      busy={busy}
                      onConfirm={() => void remove(target)}
                    />
                  </td>
                </tr>
              ))}
              {targets.length === 0 && (
                <tr>
                  <td colSpan={7} className="hint">
                    Keine Ausführungsorte: Shell-Jobs lassen sich nicht anlegen.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      <form className="form targets__form" onSubmit={(event) => void save(event)} aria-labelledby="settings-shell-targets-add">
        <h3 id="settings-shell-targets-add">{editing === null ? 'Ausführungsort hinzufügen' : 'Ausführungsort bearbeiten'}</h3>
        {editing !== null && (
          <p className="hint">
            Die Art lässt sich nicht ändern. Jobs, die auf den bisherigen Namen, Geltungsbereich oder Benutzer zeigen, werden ab dem nächsten Lauf abgelehnt, bis sie angepasst sind.
          </p>
        )}
        {formError !== null && <Alert tone="err">{formError}</Alert>}
        <div className="grid-2">
          <FormField label="Art" error={errors.kind}>
            {(aria) => (
              <select {...aria} className="input" name="shell-kind" value={form.kind} disabled={editing !== null} onChange={(e) => set('kind', e.target.value)}>
                <option value="docker">Docker-Container</option>
                <option value="host">Host-Profil</option>
              </select>
            )}
          </FormField>
          <FormField
            label={docker ? 'Name des Containers' : 'Name des Host-Profils'}
            error={errors.name}
            hint={docker ? 'Genau der Containername, z. B. nextcloud.' : 'z. B. default'}
          >
            {(aria) => <input {...aria} className="input input--mono" name="shell-name" required autoComplete="off" autoCapitalize="off" spellCheck={false} value={form.name} onChange={(e) => set('name', e.target.value)} />}
          </FormField>
        </div>
        <div className="grid-2">
          <FormField label="Gilt für" error={errors.category_id} hint="„Global“ erlaubt den Ort für Jobs in allen Kategorien.">
            {(aria) => (
              <select {...aria} className="input" name="shell-category" value={form.categoryId} onChange={(e) => set('categoryId', e.target.value)}>
                <option value="">Global (alle Kategorien)</option>
                {categories.map((category) => (
                  <option key={category.id} value={String(category.id)}>
                    Kategorie „{category.name}“
                  </option>
                ))}
              </select>
            )}
          </FormField>
          {docker ? (
            <FormField label="Standardbenutzer" error={errors.default_user} hint="Muss in der Liste der erlaubten Benutzer stehen, z. B. www-data.">
              {(aria) => (
                <input {...aria} className="input input--mono" name="shell-default-user" autoComplete="off" autoCapitalize="off" spellCheck={false} value={form.defaultUser} onChange={(e) => set('defaultUser', e.target.value)} />
              )}
            </FormField>
          ) : (
            <div className="field">
              <span className="field__label">Benutzer</span>
              <span className="hint">Host-Profile haben keine Benutzerliste; sie laufen als fester Benutzer meridian-run.</span>
            </div>
          )}
        </div>
        {docker && (
          <FormField label="Erlaubte Benutzer" error={errors.users} hint="Durch Komma oder Leerzeichen getrennt, 1 bis 10 Einträge, Kleinbuchstaben oder numerische ID (z. B. www-data 1001).">
            {(aria) => <input {...aria} className="input input--mono" name="shell-users" autoComplete="off" autoCapitalize="off" spellCheck={false} value={form.users} onChange={(e) => set('users', e.target.value)} />}
          </FormField>
        )}
        {rootChosen && (
          <Alert tone="err">
            <strong>root ist eingetragen.</strong> Jobs an diesem Ort können dann als root laufen und im Container alles ändern. Trage root nur ausdrücklich und nur ein, wenn es nötig ist.
          </Alert>
        )}
        <FormField label="Notiz" error={errors.note} hint="Wofür ist der Ort freigegeben? (optional)">
          {(aria) => <input {...aria} className="input" name="shell-note" maxLength={200} autoComplete="off" value={form.note} onChange={(e) => set('note', e.target.value)} />}
        </FormField>
        {errors.body !== undefined && <Alert tone="err">{errors.body}</Alert>}
        <div className="row">
          <button type="submit" className="btn btn--solid" disabled={busy || form.name.trim() === ''}>
            {busy ? 'Speichert …' : editing === null ? 'Ausführungsort anlegen' : 'Änderung speichern'}
          </button>
          {editing !== null && (
            <button type="button" className="btn btn--ghost" disabled={busy} onClick={cancelEdit}>
              Abbrechen
            </button>
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
    return <Alert tone="info">Für die Einstellungen fehlt dir das Recht. Sie sind Administratoren vorbehalten.</Alert>;
  }
  return (
    <div className="settings">
      {settings && <HttpSettings csrf={profile.csrf_token} />}
      {settings && <ShellSettings csrf={profile.csrf_token} />}
      {settings && <EditorSettings csrf={profile.csrf_token} />}
      {targets && <InternalTargets csrf={profile.csrf_token} />}
      {shellTargets && <ShellTargets csrf={profile.csrf_token} />}
    </div>
  );
}
