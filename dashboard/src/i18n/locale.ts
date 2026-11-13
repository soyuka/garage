// Which language the Atelier speaks. Pure core; the DOM write and the storage read are one line each
// at the edge — the same shape as tokens/stage.ts, and for the same reason: everything that decides
// anything is testable in a node environment.
//
// English is the last-resort default (owner decision 2026-11-13, docs/jury-release.md § 4): a first
// visit with no `?lang=`, no stored choice and no recognised browser language lands in English. French
// stays fully supported and wins from any of those three sources.

export type Locale = 'fr' | 'en';

export const LOCALES = ['fr', 'en'] as const satisfies readonly Locale[];

export const DEFAULT_LOCALE: Locale = 'en';

/** Reserved beside `mpa.stage`, `mpa.recency` and `mpa.audition` — one namespace for the operator's kit. */
export const LOCALE_KEY = 'mpa.locale';

export function isLocale(value: unknown): value is Locale {
  return 'string' === typeof value && (LOCALES as readonly string[]).includes(value);
}

/**
 * Three sources, in priority order, because they answer different needs: `?lang=` is how a human
 * checks the other language in one keystroke mid-rehearsal, storage is the operator's own standing
 * choice, and the browser list is the only honest guess left for a first visit.
 *
 * Anything unrecognised at any level is SKIPPED, never fatal — a hand-edited storage entry or a
 * `?lang=klingon` must fall through to the next source, not render an empty catalog.
 */
export function resolveLocale(search: string, stored: string | null, languages: readonly string[]): Locale {
  const param = new URLSearchParams(search).get('lang');
  if (isLocale(param)) return param;

  if (isLocale(stored)) return stored;

  for (const tag of languages) {
    // Match on the primary subtag only: `en-GB`, `en-US` and `en` are one catalog to us.
    const primary = tag.toLowerCase().split('-')[0];
    if (isLocale(primary)) return primary;
  }

  return DEFAULT_LOCALE;
}

/**
 * The query string `?lang=` is read from. Hash routes put it after the hash (`#/dev/workshop?lang=fr`),
 * where `location.search` never sees it; the real query string wins when it names a `lang` itself.
 */
export function localeSearch(search: string, hash: string): string {
  if (new URLSearchParams(search).has('lang')) return search;
  const i = hash.indexOf('?');
  return i < 0 ? search : hash.slice(i);
}

export function readStoredLocale(win: Window = window): string | null {
  try {
    return win.localStorage.getItem(LOCALE_KEY);
  } catch {
    return null; // private mode / storage denied → the browser list decides, never a crash
  }
}

export function writeStoredLocale(locale: Locale, win: Window = window): void {
  try {
    win.localStorage.setItem(LOCALE_KEY, locale);
  } catch {
    /* the preference simply does not persist */
  }
}

/**
 * Stamp `lang` on <html>. Not cosmetic: it is what a screen reader reads the page's pronunciation
 * from, and index.html can only carry the pre-hydration default.
 */
export function applyLocale(locale: Locale, win: Window = window): void {
  win.document.documentElement.lang = locale;
}

// The locale the operator is actually looking at, readable from outside React. Mirrors `getToken()`
// in auth.ts and exists for the same reason: the fetch wrapper is not a component and must not have
// to be handed the locale by one — yet it has to send the live value, `?lang=` override included.
// The provider is the only writer; everything else reads.
let current: Locale = DEFAULT_LOCALE;

export function currentLocale(): Locale {
  return current;
}

export function setCurrentLocale(locale: Locale): void {
  current = locale;
}

/** Read every source and settle on one. Safe before first paint. */
export function bootLocale(win: Window = window): Locale {
  const locale = resolveLocale(localeSearch(win.location.search, win.location.hash), readStoredLocale(win), win.navigator.languages ?? []);
  applyLocale(locale, win);
  setCurrentLocale(locale);

  return locale;
}
