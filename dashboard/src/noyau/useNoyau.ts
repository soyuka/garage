import { useCallback, useEffect, useReducer } from 'react';
import { initialNoyau, reduceNoyau, type NoyauEvent, type NoyauState } from './state';

// React binding for the pure frame→state machine. Supplies the monotonic clock (performance.now) the
// reducer needs, and auto-fires `settle` after the done animation so the orb returns to veille. In
// Stage C this same dispatch is fed by Mercure session frames instead of the mock stream.
export function useNoyau(): { state: NoyauState; dispatch: (ev: NoyauEvent) => void } {
  const [state, raw] = useReducer(
    (s: NoyauState, a: { ev: NoyauEvent; at: number }) => reduceNoyau(s, a.ev, a.at),
    initialNoyau,
  );
  const dispatch = useCallback((ev: NoyauEvent) => raw({ ev, at: performance.now() }), []);

  useEffect(() => {
    if (state.phase !== 'done') return;
    const t = window.setTimeout(() => dispatch({ type: 'settle' }), 1600);
    return () => window.clearTimeout(t);
  }, [state.phase, dispatch]);

  return { state, dispatch };
}
