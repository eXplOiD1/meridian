import { ApiError } from './api';
import { retryHint } from './format';

/** Fehlermeldung des Servers als Text (nie als HTML); 429 mit Wartehinweis. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message + (error.status === 429 ? retryHint(error.retryAfter) : '');
  }
  return 'Unerwarteter Fehler.';
}

/** Feldfehler einer 422-Antwort (leer bei allem anderen). */
export function fieldErrors(error: unknown): Record<string, string> {
  return error instanceof ApiError ? error.fields : {};
}

/** Dieselbe Eingabe-Schreibweise wie der Server (Anzeigename: nichts zurechtschneiden, nur Rand-Leerzeichen melden). */
export function isStatus(error: unknown, status: number): boolean {
  return error instanceof ApiError && error.status === status;
}
