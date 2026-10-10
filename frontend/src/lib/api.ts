/**
 * Dünner Client für die Meridian-API. Die Sitzung liegt ausschließlich im HttpOnly-Cookie,
 * hier steht nur das CSRF-Token im Arbeitsspeicher (nie in localStorage, sessionStorage oder der URL).
 */

/** Wird ausgelöst, wenn der Server eine bestehende Sitzung nicht mehr annimmt (abgelaufen, beendet). */
export const SESSION_ENDED_EVENT = 'meridian:session-ended';

/** Der Server verlangt zuerst ein eigenes Passwort (Pflichtwechsel): Profil neu laden, dann zeigt die App nur diesen Schritt. */
export const PASSWORD_CHANGE_EVENT = 'meridian:password-change-required';

export class ApiError extends Error {
  readonly status: number;
  readonly retryAfter: number | null;
  readonly totpRequired: boolean;
  /** Feldfehler einer 422-Antwort: Feldpfad => feste Meldung des Servers (enthält nie Eingabewerte). */
  readonly fields: Record<string, string>;
  /** 503 bei der Anmeldung: Proxy-Header ohne MERIDIAN_TRUSTED_PROXIES. Konfigurationsfehler, kein Grund für erneute Versuche. */
  readonly trustedProxiesRequired: boolean;

  constructor(
    message: string,
    status: number,
    retryAfter: number | null,
    totpRequired: boolean,
    fields: Record<string, string> = {},
    trustedProxiesRequired = false,
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.retryAfter = retryAfter;
    this.totpRequired = totpRequired;
    this.fields = fields;
    this.trustedProxiesRequired = trustedProxiesRequired;
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function fieldErrors(data: unknown): Record<string, string> {
  const out: Record<string, string> = {};
  if (isRecord(data) && isRecord(data.fields)) {
    for (const [key, value] of Object.entries(data.fields)) {
      if (typeof value === 'string') {
        out[key] = value;
      }
    }
  }
  return out;
}

export async function request<T>(method: 'GET' | 'POST' | 'PUT' | 'DELETE', path: string, options: { body?: unknown; csrf?: string } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  if (options.csrf !== undefined) {
    headers['X-CSRF-Token'] = options.csrf;
  }

  let response: Response;
  try {
    response = await fetch(path, {
      method,
      credentials: 'same-origin',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
  } catch {
    throw new ApiError('Der Server ist nicht erreichbar. Netzwerk und Adresse prüfen.', 0, null, false);
  }

  let data: unknown = null;
  try {
    data = await response.json();
  } catch {
    data = null;
  }

  if (!response.ok) {
    // 401 bei Anmeldung und Profilabfrage ist normal (falsches Passwort, nicht angemeldet); überall sonst ist die Sitzung weg.
    if (response.status === 401 && path !== '/api/auth/login' && path !== '/api/auth/me') {
      window.dispatchEvent(new Event(SESSION_ENDED_EVENT));
    }
    if (response.status === 403 && isRecord(data) && data.password_change_required === true) {
      window.dispatchEvent(new Event(PASSWORD_CHANGE_EVENT));
    }
    const message = isRecord(data) && typeof data.error === 'string' ? data.error : 'Unerwarteter Fehler (' + String(response.status) + ').';
    const retry = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);
    throw new ApiError(
      message,
      response.status,
      Number.isFinite(retry) ? retry : null,
      isRecord(data) && data.totp_required === true,
      fieldErrors(data),
      isRecord(data) && data.trusted_proxies_required === true,
    );
  }

  return data as T;
}
