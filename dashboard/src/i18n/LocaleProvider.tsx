import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import { applyLocale, bootLocale, currentLocale, type Locale, setCurrentLocale, writeStoredLocale } from './locale';
import { messages, type Messages } from './messages';

// React's half of the locale. The decisions all live in locale.ts (pure, node-testable); this is the
// context plumbing plus the three side effects a change has to carry out together — persist it, stamp
// <html lang>, and update the module-level value the fetch wrappers read.
//
// Shaped like useVoicePreference() in noyau/useVoice.ts: a `[value, set]` pair, the repo's house form
// for a persisted operator preference.

type LocaleControl = readonly [Locale, (next: Locale) => void];

const LocaleContext = createContext<LocaleControl | null>(null);

export function LocaleProvider({ children }: { children: ReactNode }): React.JSX.Element {
  // bootLocale() already ran in main.tsx before first paint; re-reading it here is idempotent and
  // keeps the provider honest when it is mounted on its own (the dev playgrounds, a test).
  const [locale, setLocale] = useState<Locale>(() => bootLocale());

  const change = useCallback((next: Locale) => {
    setLocale(next);
    writeStoredLocale(next);
    applyLocale(next);
    setCurrentLocale(next);
  }, []);

  const value = useMemo<LocaleControl>(() => [locale, change] as const, [locale, change]);

  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>;
}

/**
 * The current locale and a setter. Falls back to the booted value rather than throwing when no
 * provider is above: a dev playground rendering one component in isolation should still read, and a
 * missing provider must never be what blanks the screen.
 */
export function useLocale(): LocaleControl {
  const ctx = useContext(LocaleContext);
  // No side effect here: main.tsx booted the value already, so this just reads it. Stamping <html>
  // from inside every consumer's state initializer would be a write on mount, dozens of times over.
  const [fallback, setFallback] = useState<Locale>(currentLocale);

  const change = useCallback((next: Locale) => {
    setFallback(next);
    writeStoredLocale(next);
    applyLocale(next);
    setCurrentLocale(next);
  }, []);

  return ctx ?? ([fallback, change] as const);
}

/** The catalog for the current locale. `const t = useT()` then `t.signal.late`. */
export function useT(): Messages {
  const [locale] = useLocale();

  return messages(locale);
}
