const ACTIONS: Record<string, { label: string; tone: 'ok' | 'warn' | 'err' | 'info' }> = {
  'auth.login': { label: 'Angemeldet', tone: 'ok' },
  'auth.logout': { label: 'Abgemeldet', tone: 'info' },
  'auth.login_failed': { label: 'Anmeldung fehlgeschlagen', tone: 'err' },
  'auth.login_locked': { label: 'Konto gesperrt', tone: 'err' },
  'auth.unlocked': { label: 'Sperre aufgehoben', tone: 'warn' },
  'auth.2fa_setup_started': { label: '2FA-Einrichtung gestartet', tone: 'info' },
  'auth.2fa_enabled': { label: '2FA aktiviert', tone: 'ok' },
  'auth.2fa_disabled': { label: '2FA abgeschaltet', tone: 'warn' },
  'auth.2fa_failed': { label: '2FA-Code falsch', tone: 'err' },
  'auth.2fa_enable_failed': { label: '2FA-Aktivierung fehlgeschlagen', tone: 'err' },
  'auth.2fa_disable_failed': { label: '2FA-Abschaltung fehlgeschlagen', tone: 'err' },
  'auth.recovery_code_used': { label: 'Wiederherstellungscode benutzt', tone: 'warn' },
  'settings.changed': { label: 'Einstellung geändert', tone: 'warn' },
  'network.internal_target_added': { label: 'Interne Freigabe angelegt', tone: 'warn' },
  'network.internal_target_removed': { label: 'Interne Freigabe entfernt', tone: 'info' },
  'user.created': { label: 'Benutzer angelegt', tone: 'info' },
};

export function describeAction(action: string): { label: string; tone: 'ok' | 'warn' | 'err' | 'info' } {
  return ACTIONS[action] ?? { label: action, tone: 'info' };
}

const dateFormat = new Intl.DateTimeFormat('de-DE', {
  weekday: 'short',
  day: '2-digit',
  month: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
});

export function formatTime(iso: string): string {
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? iso : dateFormat.format(date);
}

/** Geheimnis in Vierergruppen, damit es sich in eine App abtippen lässt. */
export function groupSecret(secret: string): string {
  return secret.replace(/(.{4})/g, '$1 ').trim();
}

export function retryHint(seconds: number | null): string {
  if (seconds === null) {
    return '';
  }
  return seconds >= 60 ? ' Nächster Versuch in etwa ' + String(Math.ceil(seconds / 60)) + ' Minuten.' : ' Nächster Versuch in ' + String(seconds) + ' Sekunden.';
}

const shortFormat = new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
const timeFormat = new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit' });

/** Zeitpunkt ohne Sekunden in der Zeitzone des Browsers. */
export function formatShort(iso: string | null): string {
  if (iso === null) {
    return '–';
  }
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? iso : shortFormat.format(date);
}

/** Uhrzeit für die Übersicht; ein anderer Tag bekommt das Datum dazu. */
export function formatNext(iso: string | null): string {
  if (iso === null) {
    return '–';
  }
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return iso;
  }
  return date.toDateString() === new Date().toDateString() ? timeFormat.format(date) : shortFormat.format(date);
}
