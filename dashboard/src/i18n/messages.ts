import { en } from './en';
import { fr } from './fr';
import type { Locale } from './locale';

/**
 * The catalog shape, taken from the French one — which makes fr.ts the single declaration of both
 * what the app says and which keys exist. `en.ts` is annotated `Messages`, so tsc enforces the two
 * stay identical in shape.
 *
 * Deliberately NOT a `t('some.key')` string lookup: a typed object means a mistyped key is a compile
 * error rather than a runtime miss, and there is no fallback path to get wrong.
 */
export type Messages = typeof fr;

// en.ts imports `Messages` from here, but as a TYPE — that import erases at build time, so there is
// no runtime cycle and both catalogs can be plain static imports.
const CATALOG: Record<Locale, Messages> = { fr, en };

export function messages(locale: Locale): Messages {
  return CATALOG[locale];
}
