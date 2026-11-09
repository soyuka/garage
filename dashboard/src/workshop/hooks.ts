import { useCallback, useEffect, useState } from 'react';
import type { NoyauState } from '../noyau/state';
import { runningAction, type CommandStatus } from './runningAction';
import { readFlag, writeFlag } from './prefs';
import { bootTheme, persistTheme, toggleTheme, type Theme } from './theme';

export function useTheme(): [Theme, () => void] {
  const [theme, setTheme] = useState<Theme>(() => bootTheme());
  const flip = useCallback(() => {
    setTheme((current) => {
      const next = toggleTheme(current);
      persistTheme(next);
      return next;
    });
  }, []);
  return [theme, flip];
}

/** The dock's read of the noyau: whether a turn is running and whether it can be cancelled from this tab. */
export function useCommandStatus(state: NoyauState): { status: CommandStatus } {
  return { status: runningAction(state) };
}

/** The phone layout's own breakpoint, read in script where the markup (not only the style) changes. */
export const PHONE = '(max-width: 719px)';

export function useMedia(query: string): boolean {
  const [match, setMatch] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches);
  useEffect(() => {
    const mq = window.matchMedia(query);
    const on = () => setMatch(mq.matches);
    on();
    mq.addEventListener('change', on);
    return () => mq.removeEventListener('change', on);
  }, [query]);
  return match;
}

function localStore(): Storage | null {
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

/** A remembered on/off preference (`prefs.ts`), tolerant of a storage that is missing or throws. */
export function useFlag(key: string, fallback: boolean): [boolean, (value: boolean) => void] {
  const [value, setValue] = useState(() => {
    const store = localStore();
    return store ? readFlag(store, key, fallback) : fallback;
  });
  const set = useCallback(
    (next: boolean) => {
      const store = localStore();
      if (store) writeFlag(store, key, next);
      setValue(next);
    },
    [key],
  );
  return [value, set];
}
