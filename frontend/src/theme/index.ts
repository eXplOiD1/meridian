/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useSyncExternalStore } from 'react';

/**
 * Farbschema je Benutzer (ADR 0017): dunkel (Standard) oder hell. Vor der Anmeldung gilt die letzte Wahl dieses Browsers
 * (nur „dark“/„light“, kein Geheimnis), danach das Schema des Benutzers vom Server (`users.theme`).
 */
export type Theme = 'dark' | 'light';

const STORAGE_KEY = 'meridian.theme';
const listeners = new Set<() => void>();

function apply(theme: Theme): void {
  document.documentElement.dataset['theme'] = theme;
}

function initial(): Theme {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (stored === 'dark' || stored === 'light') {
      return stored;
    }
  } catch {
    // Speicher gesperrt: dunkel ist die Vorgabe.
  }
  return 'dark';
}

let current: Theme = initial();
apply(current);

export function getTheme(): Theme {
  return current;
}

/** Setzt das Schema (nur Anzeige); Speichern auf dem Server macht der Aufrufer. */
export function setTheme(theme: Theme): void {
  if (theme === current) {
    return;
  }
  current = theme;
  try {
    window.localStorage.setItem(STORAGE_KEY, theme);
  } catch {
    // ohne Speicher gilt das Schema nur bis zum Neuladen
  }
  apply(theme);
  listeners.forEach((listener) => listener());
}

export function useTheme(): Theme {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    getTheme,
    getTheme,
  );
}
