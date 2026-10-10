import { useEffect, useState } from 'react';
import type { Dispatch, SetStateAction } from 'react';
import { Alert } from './Alert';
import { FormField } from './FormField';
import { request } from '../lib/api';
import { errorMessage } from '../lib/errors';
import type { ShellDetail, ShellTargetOption } from '../types';

/** Nicht geheime Felder eines Shell-Jobs. */
export interface ShellForm {
  /** „art:name“, z. B. „docker:nextcloud“ oder „host:default“ (Art enthält nie einen Doppelpunkt). */
  target: string;
  interpreter: 'sh' | 'bash';
  /** Leer = Standardbenutzer des Ausführungsorts. */
  user: string;
  workdir: string;
}

export interface EnvRow {
  key: number;
  name: string;
  value: string;
}

/**
 * Skript und Umgebung: nach dem Speichern aus dem Zustand gelöscht. Vorbefüllt nur bei Einstellung
 * `jobs.reveal_for_edit` (Job-Editor lädt `GET /api/jobs/{id}/source`), sonst nur schreibend.
 */
export interface ScriptDraft {
  source: string;
  env: EnvRow[];
}

export const EMPTY_SCRIPT: ScriptDraft = { source: '', env: [] };

export function targetKey(kind: string, name: string): string {
  return kind + ':' + name;
}

export function splitTarget(key: string): { kind: 'docker' | 'host'; name: string } | null {
  const at = key.indexOf(':');
  const kind = key.slice(0, at);
  if (at < 1 || (kind !== 'docker' && kind !== 'host')) {
    return null;
  }
  return { kind, name: key.slice(at + 1) };
}

export function shellTargetLabel(kind: string, name: string): string {
  return kind === 'docker' ? 'Container ' + name : 'Host · ' + name;
}

export function shellFormFromJob(shell: ShellDetail | null | undefined): ShellForm {
  if (shell === null || shell === undefined) {
    return { target: '', interpreter: 'sh', user: '', workdir: '' };
  }
  return { target: targetKey(shell.target.kind, shell.target.name), interpreter: shell.interpreter, user: shell.user ?? '', workdir: shell.workdir ?? '' };
}

export function isRootUser(user: string | null): boolean {
  return user !== null && (user === 'root' || /^0(:|$)/.test(user));
}

/** Ausführungsorte, die für die gewählte Kategorie nutzbar sind (neu laden bei Kategoriewechsel). */
export function useShellTargets(enabled: boolean, categoryId: string): { targets: ShellTargetOption[] | null; error: string | null } {
  const [state, setState] = useState<{ targets: ShellTargetOption[] | null; error: string | null }>({ targets: null, error: null });
  useEffect(() => {
    if (!enabled) {
      return undefined;
    }
    let cancelled = false;
    setState((current) => ({ ...current, error: null }));
    const query = categoryId === '' ? '' : '?category_id=' + encodeURIComponent(categoryId);
    request<{ targets: ShellTargetOption[] }>('GET', '/api/shell/targets' + query)
      .then((data) => {
        if (!cancelled) {
          setState({ targets: data.targets, error: null });
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setState({ targets: [], error: errorMessage(caught) });
        }
      });
    return () => {
      cancelled = true;
    };
  }, [enabled, categoryId]);
  return state;
}

interface Props {
  form: ShellForm;
  onChange: (patch: Partial<ShellForm>) => void;
  targets: ShellTargetOption[] | null;
  targetsError: string | null;
  errors: Record<string, string>;
  /** Gespeicherter Job (Bearbeiten) oder null (Anlegen). */
  saved: ShellDetail | null;
  replacing: boolean;
  draft: ScriptDraft;
  setDraft: Dispatch<SetStateAction<ScriptDraft>>;
  nextKey: () => number;
  /** true: Skript und Umgebung sind mit den gespeicherten Werten vorbefüllt (Werte sichtbar). */
  revealed?: boolean;
  onStartReplacing: () => void;
  onCancelReplacing: () => void;
}

/** Die Karten „Ausführungsort“ und „Skript“ des Job-Editors für Shell-Jobs. */
export function ShellSections({ form, onChange, targets, targetsError, errors, saved, replacing, draft, setDraft, nextKey, revealed = false, onStartReplacing, onCancelReplacing }: Props) {
  const list = targets ?? [];
  const selected = list.find((option) => targetKey(option.kind, option.name) === form.target) ?? null;
  const parsed = splitTarget(form.target);
  const missing = targets !== null && form.target !== '' && selected === null;
  const isDocker = (selected?.kind ?? parsed?.kind) === 'docker';
  const effectiveUser = form.user !== '' ? form.user : (selected?.default_user ?? null);
  const root = isDocker && isRootUser(effectiveUser);
  const envErrors = Object.entries(errors).filter(([key]) => key.startsWith('script.env'));

  // Beim Wechsel des Ausführungsorts: Benutzer zurück auf den Standard, wenn er dort nicht erlaubt ist.
  useEffect(() => {
    if (selected !== null && form.user !== '' && !selected.users.includes(form.user)) {
      onChange({ user: '' });
    }
    // onChange ist stabil genug; nur auf Auswahl und Benutzer reagieren.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected?.kind, selected?.name, form.user]);

  return (
    <>
      <section className="card" aria-label="Ausführungsort">
        <h2>Ausführungsort</h2>
        {targetsError !== null && <Alert tone="err">{targetsError}</Alert>}
        {targets !== null && targets.length === 0 && targetsError === null && (
          <Alert tone="warn">Noch kein Ausführungsort freigegeben (Einstellungen → Ausführungsorte).</Alert>
        )}
        <div className="grid-2">
          <FormField label="Ausführungsort" error={errors['shell.target']} hint="Nur Orte, die für die gewählte Kategorie freigegeben sind.">
            {(aria) => (
              <select {...aria} className="input" name="shell-target" value={form.target} onChange={(e) => onChange({ target: e.target.value, user: '' })}>
                {form.target === '' && (
                  <option value="" disabled>
                    Bitte wählen …
                  </option>
                )}
                {list.map((option) => (
                  <option key={targetKey(option.kind, option.name)} value={targetKey(option.kind, option.name)}>
                    {shellTargetLabel(option.kind, option.name)}
                  </option>
                ))}
                {missing && parsed !== null && <option value={form.target}>{shellTargetLabel(parsed.kind, parsed.name)} (für diese Kategorie nicht freigegeben)</option>}
              </select>
            )}
          </FormField>
          <FormField label="Interpreter" error={errors['shell.interpreter']}>
            {(aria) => (
              <select {...aria} className="input input--short" name="interpreter" value={form.interpreter} onChange={(e) => onChange({ interpreter: e.target.value === 'bash' ? 'bash' : 'sh' })}>
                <option value="sh">sh</option>
                <option value="bash">bash</option>
              </select>
            )}
          </FormField>
        </div>
        {missing && <Alert tone="warn">Dieser Ausführungsort ist für die gewählte Kategorie nicht freigegeben. Wähle einen anderen oder gib ihn in den Einstellungen frei.</Alert>}

        {(selected !== null || parsed !== null) && (
          <div className="grid-2">
            {isDocker ? (
              <FormField
                label="Benutzer im Container"
                error={errors['shell.user']}
                hint={selected !== null && selected.default_user !== null ? 'Leer = Standard des Ausführungsorts (' + selected.default_user + ').' : 'Nur freigegebene Benutzer.'}
              >
                {(aria) => (
                  <select {...aria} className="input" name="shell-user" value={form.user} onChange={(e) => onChange({ user: e.target.value })}>
                    <option value="">Standard{selected?.default_user != null ? ' (' + selected.default_user + ')' : ''}</option>
                    {(selected?.users ?? []).map((user) => (
                      <option key={user} value={user}>
                        {user}
                        {isRootUser(user) ? ' (root)' : ''}
                      </option>
                    ))}
                    {form.user !== '' && selected !== null && !selected.users.includes(form.user) && <option value={form.user}>{form.user}</option>}
                  </select>
                )}
              </FormField>
            ) : (
              <div className="field">
                <span className="field__label">Benutzer</span>
                <span className="hint">Host-Profile laufen als fester Benutzer ohne Anmeldung (meridian-run).</span>
              </div>
            )}
            {isDocker && (
              <FormField label="Arbeitsverzeichnis" error={errors['shell.workdir']} hint="Absoluter Pfad im Container, z. B. /var/www. Leer = Standard des Containers.">
                {(aria) => (
                  <input
                    {...aria}
                    className="input input--mono-plain"
                    name="workdir"
                    autoComplete="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    placeholder="/var/www"
                    value={form.workdir}
                    onChange={(e) => onChange({ workdir: e.target.value })}
                  />
                )}
              </FormField>
            )}
          </div>
        )}
        {root && (
          <Alert tone="err">
            <strong>Achtung: Das Skript läuft als root.</strong> Ein Fehler oder ein falsches Skript kann den gesamten Container verändern. Wähle nur dann root, wenn es unbedingt nötig ist.
          </Alert>
        )}
        {selected !== null && selected.allows_root && !root && isDocker && (
          <p className="hint">Dieser Ausführungsort erlaubt auch root. Es gilt nur, was oben ausgewählt ist.</p>
        )}
      </section>

      <section className="card" aria-label="Skript">
        <h2>Skript</h2>
        {saved !== null && !replacing && (
          <div className="stored">
            <div className="stored__label">Gespeichertes Skript</div>
            <div className="stored__facts">
              <span>{saved.has_script ? 'Skript gesetzt (Inhalt verborgen)' : 'Kein Skript gesetzt'}</span>
              <span>{saved.has_env ? 'Umgebungsvariablen: ' + String(saved.env_count) + ' (Namen und Werte verborgen)' : 'Umgebungsvariablen: keine'}</span>
            </div>
            <p className="hint">Das Skript ist nach dem Speichern nicht mehr lesbar. Bewahre es zusätzlich in deiner Versionsverwaltung auf.</p>
            {errors.script !== undefined && <div className="field__error">{errors.script}</div>}
            <div className="row">
              <button type="button" className="btn btn--ghost" onClick={onStartReplacing}>
                Skript ersetzen
              </button>
            </div>
          </div>
        )}

        {replacing && (
          <div className="replace">
            {saved !== null && <Alert tone="info">Skript und Umgebungsvariablen werden zusammen ersetzt. Felder, die du leer lässt, sind danach leer.</Alert>}
            <FormField
              label="Skript"
              error={errors['script.source']}
              hint={revealed ? 'Wird verschlüsselt gespeichert. Bewahre es zusätzlich in deiner Versionsverwaltung auf.' : 'Wird verschlüsselt gespeichert und nie wieder angezeigt. Bewahre es zusätzlich in deiner Versionsverwaltung auf.'}
            >
              {(aria) => (
                <textarea
                  {...aria}
                  className="input input--area input--script"
                  name="script-source"
                  rows={10}
                  autoComplete="off"
                  autoCapitalize="off"
                  spellCheck={false}
                  wrap="off"
                  placeholder={'#!/bin/sh\nset -eu\n…'}
                  value={draft.source}
                  onChange={(e) => setDraft((current) => ({ ...current, source: e.target.value }))}
                />
              )}
            </FormField>

            <fieldset className="headers">
              <legend className="field__label">Umgebungsvariablen</legend>
              <p className="hint">
                Geheimnisse gehören in Umgebungsvariablen (mindestens 4 Zeichen, sonst kann Meridian sie in der Ausgabe nicht verbergen). Werte werden verschlüsselt gespeichert und nie wieder angezeigt.
              </p>
              {draft.env.map((row, index) => (
                <div key={row.key} className="row headers__row">
                  <input
                    className="input input--mono-plain"
                    aria-label={'Variable ' + String(index + 1) + ' Name'}
                    placeholder="NAME"
                    autoComplete="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    value={row.name}
                    onChange={(e) => setDraft((current) => ({ ...current, env: current.env.map((r) => (r.key === row.key ? { ...r, name: e.target.value } : r)) }))}
                  />
                  <input
                    className="input input--mono-plain"
                    type={revealed ? 'text' : 'password'}
                    aria-label={'Variable ' + String(index + 1) + ' Wert'}
                    placeholder="Wert"
                    autoComplete="new-password"
                    spellCheck={false}
                    value={row.value}
                    onChange={(e) => setDraft((current) => ({ ...current, env: current.env.map((r) => (r.key === row.key ? { ...r, value: e.target.value } : r)) }))}
                  />
                  <button type="button" className="btn btn--ghost btn--small" onClick={() => setDraft((current) => ({ ...current, env: current.env.filter((r) => r.key !== row.key) }))}>
                    Entfernen
                  </button>
                </div>
              ))}
              {envErrors.map(([key, message]) => (
                <div key={key} className="field__error">
                  {message}
                </div>
              ))}
              <button type="button" className="btn btn--ghost btn--small" onClick={() => setDraft((current) => ({ ...current, env: [...current.env, { key: nextKey(), name: '', value: '' }] }))}>
                Variable hinzufügen
              </button>
            </fieldset>

            {saved !== null && (
              <div className="row">
                <button type="button" className="btn btn--ghost" onClick={onCancelReplacing}>
                  Abbrechen (gespeichertes Skript behalten)
                </button>
              </div>
            )}
          </div>
        )}
      </section>
    </>
  );
}
