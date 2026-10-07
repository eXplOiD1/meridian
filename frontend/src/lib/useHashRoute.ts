import { useEffect, useState } from 'react';

/**
 * Minimaler Router über den URL-Hash (#/jobs/12). So braucht der Server keine Weiterleitungen für
 * Unterseiten, und es gibt keine zusätzliche Abhängigkeit. Ids sind Text (positive Ganzzahl), nie Teil von Pfaden
 * zum Server ohne vorherige Prüfung.
 */
export type Route =
  | { name: 'home' }
  | { name: 'audit' }
  | { name: 'konto' }
  | { name: 'jobs' }
  | { name: 'job-new' }
  | { name: 'job'; id: string }
  | { name: 'job-edit'; id: string };

const ID = /^[1-9][0-9]{0,17}$/;

export function parseRoute(hash: string): Route {
  const parts = hash.replace(/^#\/?/, '').split('/').filter((part) => part !== '');
  const [first, second, third] = parts;
  if (parts.length === 0) {
    return { name: 'home' };
  }
  if (parts.length === 1 && first === 'audit') {
    return { name: 'audit' };
  }
  if (parts.length === 1 && first === 'konto') {
    return { name: 'konto' };
  }
  if (first === 'jobs') {
    if (parts.length === 1) {
      return { name: 'jobs' };
    }
    if (parts.length === 2 && second === 'neu') {
      return { name: 'job-new' };
    }
    if (second !== undefined && ID.test(second)) {
      if (parts.length === 2) {
        return { name: 'job', id: second };
      }
      if (parts.length === 3 && third === 'bearbeiten') {
        return { name: 'job-edit', id: second };
      }
    }
  }
  return { name: 'home' };
}

/** Ziel als Pfad ohne führenden Hash, z. B. '', 'jobs', 'jobs/12/bearbeiten'. */
export function navigate(path: string): void {
  window.location.hash = path === '' ? '#/' : '#/' + path;
}

export function hrefOf(path: string): string {
  return path === '' ? '#/' : '#/' + path;
}

export function useHashRoute(): Route {
  const [route, setRoute] = useState<Route>(() => parseRoute(window.location.hash));
  useEffect(() => {
    const onChange = (): void => setRoute(parseRoute(window.location.hash));
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  return route;
}
