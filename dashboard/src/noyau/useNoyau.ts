import { useCallback, useEffect, useReducer } from 'react';
import { useLocale } from '../i18n/LocaleProvider';
import { DWELL } from '../tokens/motion';
import type { Locale } from '../i18n/locale';
import { initialNoyau, reduceNoyau, type NoyauEvent, type NoyauState } from './state';

// React binding for the pure frame→state machine. Supplies the monotonic clock (performance.now) the
// reducer needs, and auto-fires `settle` after the done animation so the orb returns to veille. In
// Stage C this same dispatch is fed by Mercure session frames instead of the mock stream.
export function useNoyau(): { state: NoyauState; dispatch: (ev: NoyauEvent) => void } {
  // Carried on the action rather than closed over, so the reducer stays a pure function of its
  // arguments — a reducer reading ambient state is the thing React's docs warn about.
  const [locale] = useLocale();
  const [state, raw] = useReducer(
    (s: NoyauState, a: { ev: NoyauEvent; at: number; locale: Locale }) => reduceNoyau(s, a.ev, a.at, a.locale),
    initialNoyau,
  );
  const dispatch = useCallback((ev: NoyauEvent) => raw({ ev, at: performance.now(), locale }), [locale]);

  useEffect(() => {
    if (state.phase !== 'done') return;
    const t = window.setTimeout(() => dispatch({ type: 'settle' }), DWELL.settle);
    return () => window.clearTimeout(t);
  }, [state.phase, dispatch]);

  return { state, dispatch };
}
