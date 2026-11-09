/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { ApiError } from './api';
import { retryHint } from './format';
import { t } from '../i18n';

/** Fehlermeldung des Servers als Text (nie als HTML); 429 mit Wartehinweis. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message + (error.status === 429 ? retryHint(error.retryAfter) : '');
  }
  return t('Unerwarteter Fehler.');
}

/** Feldfehler einer 422-Antwort (leer bei allem anderen). */
export function fieldErrors(error: unknown): Record<string, string> {
  return error instanceof ApiError ? error.fields : {};
}

/** Dieselbe Eingabe-Schreibweise wie der Server (Anzeigename: nichts zurechtschneiden, nur Rand-Leerzeichen melden). */
export function isStatus(error: unknown, status: number): boolean {
  return error instanceof ApiError && error.status === status;
}

/** Fehler beim Speichern: bei 422 die Feldfehler und ein Sammelhinweis, sonst die Meldung des Servers. */
export function reportSaveError(
  error: unknown,
  setErrors: (errors: Record<string, string>) => void,
  setFormError: (message: string | null) => void,
): void {
  if (isStatus(error, 422)) {
    setErrors(fieldErrors(error));
    setFormError(t('Bitte die markierten Felder korrigieren.'));
  } else {
    setFormError(errorMessage(error));
  }
}

/** Führt eine Aktion mit Ladeanzeige aus, lädt danach neu und zeigt einen Fehler als Meldung des Formulars. */
export async function runAction(
  run: () => Promise<void>,
  reload: () => Promise<void>,
  setBusy: (busy: boolean) => void,
  setFormError: (message: string | null) => void,
): Promise<void> {
  setBusy(true);
  setFormError(null);
  try {
    await run();
    await reload();
  } catch (caught) {
    setFormError(errorMessage(caught));
  } finally {
    setBusy(false);
  }
}
