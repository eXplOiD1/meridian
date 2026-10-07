import { useEffect, useState } from 'react';
import { Alert } from '../components/Alert';
import { navigate } from '../lib/useHashRoute';
import type { Profile } from '../types';

/** Uhr der Abfahrtstafel: eigener kleiner Zustand, damit nur sie jede Sekunde neu gezeichnet wird. */
function BoardClock() {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const pad = (n: number): string => String(n).padStart(2, '0');
  return (
    <div className="board__clock" role="timer" aria-label={'Uhrzeit ' + pad(now.getHours()) + ':' + pad(now.getMinutes())}>
      <span aria-hidden="true">
        {pad(now.getHours())}
        <span className="board__colon blink">:</span>
        {pad(now.getMinutes())}
      </span>
    </div>
  );
}

export function Overview({ profile }: { profile: Profile }) {
  return (
    <>
      {!profile.totp_enabled && (
        <Alert tone="warn">
          Dein Konto ist noch nicht mit einem zweiten Faktor geschützt.{' '}
          <a
            href="#/konto"
            onClick={(event) => {
              event.preventDefault();
              navigate('konto');
            }}
          >
            Jetzt einrichten
          </a>
          .
        </Alert>
      )}
      <section className="board" aria-label="Nächste Abfahrten">
        <div className="board__head">
          <div>
            <div className="board__title">NÄCHSTE ABFAHRTEN</div>
            <div className="board__sub">Alle Jobs, sortiert nach dem nächsten Lauf</div>
          </div>
          <BoardClock />
        </div>
        <div className="board__empty">
          <strong>Noch keine Abfahrten</strong>
          <span>
            Sobald Jobs angelegt sind, erscheinen sie hier mit Zeit, Gleis, Ziel und Status. Der Scheduler und die Jobs kommen in den nächsten
            Ausbaustufen.
          </span>
        </div>
      </section>
    </>
  );
}
