import { useEffect, useRef, useState, type ReactNode } from 'react';
import { flushSync } from 'react-dom';

// Keeps something mounted for its exit animation after it is turned off.
export function useLinger(on: boolean, ms: number): { mounted: boolean; leaving: boolean } {
  const [mounted, setMounted] = useState(on);
  useEffect(() => {
    if (on) return setMounted(true);
    const t = window.setTimeout(() => setMounted(false), ms);
    return () => window.clearTimeout(t);
  }, [on, ms]);
  return { mounted: on || mounted, leaving: !on && mounted };
}

export const EXIT_MS = 180;

// Enters with `rise-in`, leaves with the last children it showed, faded out under `data-leaving`.
export function Presence({ show, children }: { show: boolean; children: ReactNode }) {
  const last = useRef<ReactNode>(children);
  if (show) last.current = children;
  const { mounted, leaving } = useLinger(show, EXIT_MS);
  if (!mounted) return null;
  return (
    <div className="presence" data-leaving={leaving || undefined} aria-hidden={leaving || undefined}>
      {show ? children : last.current}
    </div>
  );
}

type StartViewTransition = (update: () => void) => unknown;

// Page-level changes (home ↔ workspace, route to route) morph through the View Transitions API: the
// composer is one named element, the rest cross-fades. Without the API, or under reduced motion, the
// change is immediate.
export function viewTransition(update: () => void): void {
  const start = (document as Document & { startViewTransition?: StartViewTransition }).startViewTransition;
  if (!start || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return update();
  start.call(document, () => flushSync(update));
}
