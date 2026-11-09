import { describe, it, expect } from 'vitest';
import {
  currentLocale,
  DEFAULT_LOCALE,
  isLocale,
  LOCALES,
  localeSearch,
  resolveLocale,
  setCurrentLocale,
} from './locale';

describe('the supported locales', () => {
  it('is fr + en, and French is the default', () => {
    expect(LOCALES).toEqual(['fr', 'en']);
    expect(DEFAULT_LOCALE).toBe('fr');
  });

  it('recognises only the two it can actually render', () => {
    expect(isLocale('fr')).toBe(true);
    expect(isLocale('en')).toBe(true);
    expect(isLocale('de')).toBe(false);
    expect(isLocale('')).toBe(false);
    expect(isLocale(null)).toBe(false);
    expect(isLocale('EN')).toBe(false); // the stored value is canonical lowercase, never a guess
  });
});

describe('resolveLocale — URL, then the stored choice, then the browser', () => {
  it('falls back to French when nothing says otherwise', () => {
    expect(resolveLocale('', null, [])).toBe('fr');
  });

  it('honours a stored choice over the browser', () => {
    expect(resolveLocale('', 'en', ['fr-FR'])).toBe('en');
    expect(resolveLocale('', 'fr', ['en-GB'])).toBe('fr');
  });

  it('seeds from the browser when nothing is stored yet', () => {
    expect(resolveLocale('', null, ['en-GB', 'fr'])).toBe('en');
    expect(resolveLocale('', null, ['fr-FR'])).toBe('fr');
  });

  it('matches the browser on the primary subtag, case-insensitively', () => {
    expect(resolveLocale('', null, ['EN-US'])).toBe('en');
    expect(resolveLocale('', null, ['en'])).toBe('en');
  });

  it('skips browser languages it cannot render and keeps looking', () => {
    expect(resolveLocale('', null, ['de-DE', 'es', 'en-GB'])).toBe('en');
    expect(resolveLocale('', null, ['de-DE', 'es'])).toBe('fr');
  });

  it('ignores a stored value that is not a locale we support', () => {
    // A hand-edited or stale storage entry must not brick the UI into a blank catalog.
    expect(resolveLocale('', 'klingon', ['en-GB'])).toBe('en');
    expect(resolveLocale('', 'klingon', [])).toBe('fr');
  });

  // Same argument as `?stage=` in tokens/stage.ts: one keystroke to check the other language during a
  // rehearsal, without clearing storage on a machine that is armed for the talk.
  it('lets ?lang= override both, in either direction', () => {
    expect(resolveLocale('?lang=en', 'fr', ['fr-FR'])).toBe('en');
    expect(resolveLocale('?lang=fr', 'en', ['en-GB'])).toBe('fr');
  });

  it('ignores an unsupported ?lang= rather than blanking the UI', () => {
    expect(resolveLocale('?lang=klingon', 'en', [])).toBe('en');
  });
});

// A module-level current value, mirroring getToken() in auth.ts: the fetch wrapper is not a React
// component and must not have to be handed the locale by one, but it still has to send the locale
// the operator is actually looking at — including a `?lang=` override, which never touches storage.
describe('the current locale, for non-React callers', () => {
  it('starts at the default', () => {
    expect(currentLocale()).toBe(DEFAULT_LOCALE);
  });

  it('follows the last value set', () => {
    setCurrentLocale('en');
    expect(currentLocale()).toBe('en');
    setCurrentLocale('fr');
    expect(currentLocale()).toBe('fr');
  });
});

describe('localeSearch — the query may sit after the hash on hash routes', () => {
  it('prefers the real query string', () => {
    expect(localeSearch('?lang=en', '#/dev/workshop?lang=fr')).toBe('?lang=en');
  });

  it('reads the query that follows the hash route', () => {
    expect(localeSearch('', '#/dev/workshop?lang=fr')).toBe('?lang=fr');
    expect(resolveLocale(localeSearch('', '#/dev/workshop?lang=en'), null, [])).toBe('en');
  });

  it('falls through to the hash when the real query carries no lang', () => {
    expect(localeSearch('?capture=1', '#/dev/workshop?lang=fr')).toBe('?lang=fr');
  });

  it('is empty when neither carries one', () => {
    expect(localeSearch('', '#/dev/workshop')).toBe('');
  });
});
