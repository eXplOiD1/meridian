export interface RoleInfo {
  role: string;
  permissions: string[];
  /** null = alle Kategorien (ausdrücklich), [] = nichts */
  categories: string[] | null;
}

export interface Profile {
  user: { id: number; username: string; display_name: string };
  roles: RoleInfo[];
  totp_enabled: boolean;
  /** Einmalpasswort noch nicht gewechselt: Der Server gibt dann keine Rechte, nur den Passwortwechsel. */
  password_change_required?: boolean;
  csrf_token: string;
}

export interface AuditEntry {
  id: number;
  user_id: number | null;
  username: string | null;
  action: string;
  target: string | null;
  created_at: string;
}

export interface AuditPage {
  entries: AuditEntry[];
  next_before_id: number | null;
}

export type JobType = 'http' | 'shell';
export type RunStatus = 'queued' | 'running' | 'ok' | 'failed' | 'timeout' | 'aborted' | 'skipped';
export type RunTrigger = 'schedule' | 'manual' | 'retry' | 'test';
export type OverlapPolicy = 'skip' | 'parallel' | 'queue';
export type StoreResponse = 'inherit' | 'on' | 'off';
export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'HEAD';

export interface CategoryRef {
  id: number;
  name: string;
}

export interface LastRun {
  id: number;
  status: RunStatus;
  trigger: RunTrigger;
  finished_at: string | null;
  duration_ms: number | null;
  http_status: number | null;
}

export interface JobSummary {
  id: number;
  name: string;
  type: JobType;
  category: CategoryRef | null;
  owner: { id: number; display_name: string } | null;
  cron: string;
  timezone: string;
  next_run_at: string | null;
  is_enabled: boolean;
  overlap_policy: OverlapPolicy;
  retry_count: number;
  retry_delay_seconds: number;
  catch_up: boolean;
  last_run: LastRun | null;
  running: boolean;
  can: { edit: boolean; run: boolean };
  created_at: string;
  updated_at: string;
  /** Fehlt bei Shell-Jobs. */
  http?: { target: string } | null;
  /** Nur bei Shell-Jobs: Ausführungsort (Art + Name), nie Skript oder Umgebung. */
  shell?: { target: ShellTargetRef } | null;
}

export interface ShellTargetRef {
  kind: 'docker' | 'host';
  name: string;
}

export interface ShellDetail {
  target: ShellTargetRef;
  interpreter: 'sh' | 'bash';
  user: string | null;
  workdir: string | null;
  timeout_seconds: number;
  /** Skript und Umgebung kommen nie zurück: nur „gesetzt“ und die Anzahl. */
  has_script: boolean;
  has_env: boolean;
  env_count: number;
}

export interface HttpDetail {
  method: HttpMethod;
  timeout_seconds: number;
  expected_status: string;
  max_redirects: number;
  store_response: StoreResponse;
  target: string;
  /** Für alle Rollen gesetzt: Es gibt eine gespeicherte Anfrage. */
  has_request?: boolean;
  /**
   * Die folgenden Felder liefert der Server nur mit can.edit; ohne dieses Recht fehlen sie ganz.
   * Ein Fehlen heißt „nicht sichtbar“, nie „keine Header“ oder „kein Body“.
   */
  /** Beim Speichern der Anfrage erzeugte, maskierte Anzeige (nur Text, nie als Eingabewert oder Link). */
  display_url?: string;
  has_url?: boolean;
  has_headers?: boolean;
  header_count?: number;
  has_body?: boolean;
}

export interface JobDetail extends Omit<JobSummary, 'http' | 'shell'> {
  /** Fehlt bei Shell-Jobs. */
  http?: HttpDetail | null;
  shell?: ShellDetail | null;
}

export interface JobList {
  jobs: JobSummary[];
  truncated: boolean;
}

export interface Run {
  id: number;
  job_id: number;
  trigger: RunTrigger;
  status: RunStatus;
  attempt: number;
  scheduled_for: string | null;
  started_at: string | null;
  finished_at: string | null;
  duration_ms: number | null;
  http_status: number | null;
  note: string | null;
  /** Nur im Lauf-Detail. Immer als Text darstellen. */
  output?: string | null;
  started_by: { id: number; display_name: string } | null;
  exit_code?: number | null;
  cancel_requested_at?: string | null;
  cancelled_by?: { id: number; display_name: string } | null;
  output_bytes?: number | null;
  /** true, solange das Live-Log des Laufs noch vorhanden ist. */
  live?: boolean;
}

export interface RunPage {
  runs: Run[];
  next_before_id: number | null;
}

export interface JobLimits {
  max_timeout_seconds: number;
  shell_max_timeout_seconds?: number;
  response_storage: 'off' | 'on' | 'never';
}

export type SettingKey = 'http.max_timeout_seconds' | 'http.response_storage' | 'http.display_path' | 'http.display_host' | 'shell.max_timeout_seconds' | 'jobs.reveal_for_edit';

/**
 * Antwort von `GET /api/jobs/{id}/source` (nur bei Einstellung `jobs.reveal_for_edit = on` und Bearbeitungsrecht):
 * gespeicherte Werte im Klartext. Nur im Zustand des Editors halten, nie in Browser-Speicher oder URL.
 */
export type JobSource =
  | { job_id: number; type: 'http'; url: string; headers: { name: string; value: string }[]; body: string | null }
  | { job_id: number; type: 'shell'; script: string; env: { name: string; value: string }[] };

export interface SettingEntry {
  key: string;
  value: number | string;
  default: number | string;
  source: 'default' | 'stored';
  updated_at: string | null;
  updated_by: { display_name: string } | null;
}

export interface SettingChange {
  setting: SettingEntry;
  tightened_jobs: number;
}

export interface InternalTarget {
  id: number;
  kind: 'cidr' | 'host';
  value: string;
  /** 0 = alle Ports */
  port: number;
  category: CategoryRef | null;
  note: string;
  created_at: string;
  created_by: { display_name: string } | null;
}

export type UserStatus = 'active' | 'inactive' | 'deleted';

export interface AssignmentView {
  role_id: number;
  role: string;
  all_categories: boolean;
  categories: CategoryRef[];
  /** false = Zuweisung ohne Kategorie (z. B. gelöscht): gewährt nichts. */
  effective: boolean;
}

export interface UserRow {
  id: number;
  username: string;
  display_name: string;
  status: UserStatus;
  totp_enabled: boolean;
  password_change_required: boolean;
  locked: boolean;
  last_login_at: string | null;
  created_at: string;
  session_count: number;
  assignments: AssignmentView[];
}

/** Eine Zuweisung, wie sie gesendet wird. */
export interface AssignmentInput {
  role_id: number;
  all_categories: boolean;
  category_ids: number[];
}

export interface RoleDef {
  id: number;
  name: string;
  builtin: boolean;
  permissions: string[];
}

export interface PermissionDef {
  key: string;
  dangerous: boolean;
  scoped: boolean;
}

export interface RoleCatalog {
  roles: RoleDef[];
  permissions: PermissionDef[];
}

export interface OneTimePasswordReply {
  initial_password: string;
  expires_at: string;
}

export interface CategoryUsage {
  id: number;
  name: string;
  jobs: number;
  assignments: number;
  assignments_ineffective: number;
  internal_targets: number;
  deletable: boolean;
}

export interface SessionRow {
  id: number;
  created_at: string;
  last_seen_at: string;
  expires_at: string;
  current: boolean;
  user_agent: string | null;
  client_ip: string | null;
}

/** Auswahl im Job-Editor (GET /api/shell/targets). */
export interface ShellTargetOption {
  kind: 'docker' | 'host';
  name: string;
  users: string[];
  default_user: string | null;
  allows_root: boolean;
}

/** Verwaltete Freigabe (GET /api/settings/shell-targets). */
export interface ShellTargetEntry {
  id: number;
  kind: 'docker' | 'host';
  name: string;
  category: CategoryRef | null;
  users: string[];
  default_user: string | null;
  allows_root: boolean;
  note: string;
  created_at: string;
  created_by: { display_name: string } | null;
}

export interface LogChunk {
  seq: number;
  stream: 'out' | 'err' | 'sys';
  text: string;
}

export interface LogPage {
  chunks: LogChunk[];
  status: RunStatus;
  done: boolean;
}
