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
