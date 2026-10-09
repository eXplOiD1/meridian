import { isHostHidden, targetLabel } from '../lib/jobs';

/** Ziel eines HTTP-Jobs als reiner Text (nie als Link). Bei verborgenem Host mit kurzem Hinweis auf die Einstellung. */
export function TargetText({ target }: { target: string }) {
  return (
    <>
      {targetLabel(target)}
      {isHostHidden(target) && <span className="hint hint--inline"> · Host verborgen (Einstellung)</span>}
    </>
  );
}
