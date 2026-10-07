import { useEffect, useState } from 'react';

/**
 * Minimaler Router über den URL-Hash (#/audit). So braucht der Server keine Weiterleitungen für
 * Unterseiten, und es gibt keine zusätzliche Abhängigkeit.
 */
export type Route = '' | 'audit' | 'konto';

const KNOWN: Route[] = ['', 'audit', 'konto'];

function read(): Route {
  const raw = window.location.hash.replace(/^#\/?/, '');
  return (KNOWN as string[]).includes(raw) ? (raw as Route) : '';
}

export function navigate(route: Route): void {
  window.location.hash = route === '' ? '#/' : '#/' + route;
}

export function useHashRoute(): Route {
  const [route, setRoute] = useState<Route>(read);
  useEffect(() => {
    const onChange = (): void => setRoute(read());
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  return route;
}
