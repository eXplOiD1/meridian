import { ApiError } from './api';
import { retryHint } from './format';

/** Fehlermeldung des Servers als Text (nie als HTML); 429 mit Wartehinweis. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message + (error.status === 429 ? retryHint(error.retryAfter) : '');
  }
  return 'Unerwarteter Fehler.';
}
