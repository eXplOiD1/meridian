/* Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
 * SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
 */
import { useSyncExternalStore } from 'react';
import { EN } from './en';

/**
 * Mehrsprachigkeit ohne Zusatzpaket (ADR 0016). Quelltext ist Deutsch: `t('Deutscher Text')` liefert bei „en“ die englische
 * Fassung aus `en.ts` und fällt sonst (oder bei fehlendem Eintrag) auf den deutschen Text zurück. Platzhalter: `{name}`.
 * Die Sprache gehört dem Benutzer (Server: `users.language`); vor der Anmeldung gilt die zuletzt gewählte oder die des Browsers.
 */
export type Lang = 'de' | 'en';

const STORAGE_KEY = 'meridian.lang';
const listeners = new Set<() => void>();

function initial(): Lang {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (stored === 'de' || stored === 'en') {
      return stored;
    }
  } catch {
    // Speicher gesperrt: egal, dann gilt die Browsersprache.
  }
  return typeof navigator !== 'undefined' && navigator.language.toLowerCase().startsWith('en') ? 'en' : 'de';
}

let current: Lang = initial();

export function getLang(): Lang {
  return current;
}

/** Setzt die Sprache (nur Anzeige); Speichern auf dem Server macht der Aufrufer. */
export function setLang(lang: Lang): void {
  if (lang === current) {
    return;
  }
  current = lang;
  try {
    window.localStorage.setItem(STORAGE_KEY, lang);
  } catch {
    // ohne Speicher gilt die Sprache nur bis zum Neuladen
  }
  document.documentElement.lang = lang;
  listeners.forEach((listener) => listener());
}

export function t(text: string, params?: Record<string, string | number>): string {
  const translated = current === 'en' ? (EN[text] ?? text) : text;
  if (params === undefined) {
    return translated;
  }
  return translated.replace(/\{(\w+)\}/g, (whole, name: string) => (name in params ? String(params[name]) : whole));
}

/** Locale für Datum und Zahlen. */
export function locale(): string {
  return current === 'en' ? 'en-GB' : 'de-DE';
}

/** Hängt eine Komponente an die Sprache; ändert sie sich, wird neu gezeichnet. */
export function useLang(): Lang {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    getLang,
    getLang,
  );
}
