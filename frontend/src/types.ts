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
  http: { target: string } | null;
}

export interface HttpDetail {
  method: HttpMethod;
  timeout_seconds: number;
  expected_status: string;
  max_redirects: number;
  store_response: StoreResponse;
  target: string;
  /** Beim Speichern der Anfrage erzeugte, maskierte Anzeige (nur Text, nie als Eingabewert oder Link). */
  display_url: string;
  has_url: boolean;
  has_headers: boolean;
  header_count: number;
  has_body: boolean;
}

export interface JobDetail extends Omit<JobSummary, 'http'> {
  http: HttpDetail | null;
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
}

export interface RunPage {
  runs: Run[];
  next_before_id: number | null;
}

export interface JobLimits {
  max_timeout_seconds: number;
  response_storage: 'off' | 'on' | 'never';
}

export type SettingKey = 'http.max_timeout_seconds' | 'http.response_storage' | 'http.display_path';

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
