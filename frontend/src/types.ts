/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
export interface RoleInfo {
  role: string;
  permissions: string[];
  /** null = alle Kategorien (ausdrücklich), [] = nichts */
  categories: string[] | null;
}

export interface Profile {
  user: { id: number; username: string; display_name: string };
  roles: RoleInfo[];
  /** Oberflächensprache des Benutzers (ADR 0016). */
  language?: 'de' | 'en';
  /** Farbschema des Benutzers (ADR 0017). */
  theme?: 'dark' | 'light';
  job_editor_mode?: 'simple' | 'expert';
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
  /** Ende des Zeitplans (UTC) oder null. */
  expires_at: string | null;
  notify: JobNotify;
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
  min_interval_seconds?: number;
}

export type SettingKey = 'http.max_timeout_seconds' | 'http.response_storage' | 'http.display_path' | 'http.display_host' | 'shell.max_timeout_seconds' | 'jobs.reveal_for_edit' | 'app.public_url' | 'runs.retention_days' | 'jobs.min_interval_seconds' | 'auth.session_days';

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
  description: string | null;
  builtin: boolean;
  /** 'admin' | 'operator' | 'observer' bei Standardrollen, sonst null. */
  builtin_key: string | null;
  permissions: string[];
}

/** Rolle aus `GET /api/roles/manage`: zusätzlich Zuweisungen und was der Handelnde darf. */
export interface ManagedRole extends RoleDef {
  permissions_editable: boolean;
  deletable: boolean;
  assignments: number;
  restricted_assignments: number;
  active_users: number;
  held_by_me: boolean;
}

export interface PermissionDef {
  key: string;
  dangerous: boolean;
  scoped: boolean;
}

export interface RoleCatalog {
  roles: RoleDef[];
  permissions: PermissionDef[];
  max_assignments?: number;
}

export interface ManagedRoles {
  roles: ManagedRole[];
  permissions: PermissionDef[];
  max_roles: number;
}

export interface OneTimePasswordReply {
  initial_password: string;
  expires_at: string;
}

export interface CategoryUsage {
  recipients: string[];
  id: number;
  name: string;
  jobs: number;
  assignments: number;
  assignments_ineffective: number;
  internal_targets: number;
  watchers: number;
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

/** Antwort von `GET /api/overview` („Heute im Takt“). Abschnitte sind `[Index, erfolgreich, Fehler]`. */
export interface OverviewJob {
  kind?: 'job' | 'monitor';
  id: number;
  name: string;
  type: 'http' | 'shell';
  enabled: boolean;
  buckets: Array<[number, number, number]>;
  planned: number[];
  next_run_at: string | null;
}

export interface Overview {
  monitors?: OverviewJob[];
  window: { from: string; to: string; bucket_seconds: number; buckets: number; now_bucket: number };
  jobs: OverviewJob[];
  totals: { runs: number; failed: number; avg_duration_ms: number | null };
  attention: Array<{ job: { id: number; name: string }; kind: 'job' | 'monitor'; status: 'failed' | 'timeout' | 'slow' | 'down'; finished_at: string | null; running_seconds: number | null; usual_seconds: number | null }>;
}

export type ChannelKind = 'email' | 'ntfy' | 'webhook' | 'telegram' | 'discord' | 'slack' | 'pushover' | 'homeassistant' | 'browser';

/** Kanal der Benachrichtigungen (`/api/notifications/channels`). Das Geheimnis kommt nie zurück, nur `has_secret`. */
export interface NotificationChannel {
  id: number;
  name: string;
  type: ChannelKind;
  enabled: boolean;
  config: {
    language?: 'ui' | 'de' | 'en';
    host?: string;
    port?: number;
    encryption?: 'none' | 'starttls' | 'ssl';
    username?: string | null;
    from?: string;
    from_name?: string | null;
    to?: string[];
    server?: string;
    topic?: string;
    priority?: number;
    url?: string;
    chat_id?: string;
    service?: string;
    contact?: string;
  };
  has_secret: boolean;
  created_at: string;
  updated_at: string;
  last_delivery?: { kind: string; status: string; at: string; error: string | null } | null;
}

/** Eintrag der Auswahl im Job-Editor (`/api/notifications/channel-names`). */
export interface NotificationChannelName {
  id: number;
  name: string;
  type: ChannelKind;
  enabled: boolean;
}

export interface JobNotify {
  on_failure: boolean;
  failure_after: number;
  on_recovery: boolean;
  channels: 'all' | 'selected' | 'none';
  channel_ids: number[];
  disable_after: number | null;
  on_disable: boolean;
  tls_warn_days: number | null;
  runtime_warn_percent: number | null;
  recipients: string[];
}

/** Antwort von `GET /api/stats` (Läufe je Tag und je Job). */
export interface Stats {
  range: { from: string; to: string; days: number };
  retention_days: number;
  daily: Array<{ day: string; runs: number; ok: number; failed: number; avg_duration_ms: number | null }>;
  jobs: Array<{ id: number; name: string; runs: number; ok: number; failed: number; success_rate: number | null; avg_duration_ms: number | null; max_duration_ms: number }>;
}

/** Zugriffstoken für die REST-API (`/api/auth/tokens`). Hash und Klartext sind nie Teil der Liste. */
export interface ApiTokenEntry {
  id: number;
  name: string;
  read_only: boolean;
  expires_at: string | null;
  last_used_at: string | null;
  created_at: string;
}

export type MonitorState = 'waiting' | 'up' | 'down' | 'paused';

/** Herzschlag-Überwachung (`/api/monitors`). Das Ping-Token kommt nur beim Anlegen/Erneuern (einmal), nie in der Liste. */
export type MonitorKind = 'heartbeat' | 'http' | 'tcp' | 'ping';

export interface Monitor {
  max_response_ms?: number;
  id: number;
  kind: MonitorKind;
  /** Aktive Prüfung: Ursprung bzw. Host:Port (nie Pfad oder Query); bei verborgenem Host ein Platzhalter. */
  target: string | null;
  expected_status: string | null;
  fail_after: number | null;
  consecutive_failures: number | null;
  last_check_at: string | null;
  last_check_ok: boolean | null;
  last_latency_ms: number | null;
  last_check_note: string | null;
  name: string;
  category_id: number | null;
  category: string | null;
  interval_seconds: number;
  grace_seconds: number;
  enabled: boolean;
  state: MonitorState;
  state_since: string;
  last_ping_at: string | null;
  notify_on_down: boolean;
  notify_on_up: boolean;
  notify_channels: 'all' | 'selected' | 'none';
  channel_ids: number[];
  can_edit: boolean;
  created_at: string;
}

export interface StatusPageItem {
  type: 'job' | 'monitor';
  id: number;
  label: string;
  /** Interner Name (nur in der Verwaltung sichtbar, nie öffentlich). */
  name: string | null;
}

export interface StatusPage {
  language: 'de' | 'en';
  id: number;
  title: string;
  slug: string;
  path: string;
  category_id: number | null;
  category: string | null;
  enabled: boolean;
  items: StatusPageItem[];
  created_at: string;
}
