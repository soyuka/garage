// How the Atelier renders telemetry — the single
// source, in both languages. Thin-space (U+202F) groups thousands in French, a comma in English; a
// no-break space (U+00A0) binds a value to its unit in BOTH, because that rule is about not wrapping,
// not about French. No false precision: forecasts round to months/weeks and health rounds to the
// nearest 5 — EXCEPT overdue, which is precise (precision matters when late).
//
// `locale` is a REQUIRED argument on purpose. Defaulting it to 'fr' would let every existing call
// site keep compiling while silently staying French; required, the compiler names them all.

import type { Locale } from './i18n/locale';

// Written as escapes, not as literal characters: these are invisible in an editor and a tool that
// rewrites this file can flatten them to ASCII spaces without anything looking wrong (it happened).
export const THIN = '\u202f'; // narrow no-break space — French thousands separator
export const NBSP = '\u00a0'; // no-break space — binds number to unit

const GROUP: Record<Locale, string> = { fr: THIN, en: ',' };
const DECIMAL: Record<Locale, string> = { fr: ',', en: '.' };

/** Group thousands: 142000 → "142 000" (fr) / "142,000" (en). */
export function groupThousands(value: number, locale: Locale): string {
  const sep = GROUP[locale];
  const neg = value < 0;
  const digits = Math.abs(Math.trunc(value)).toString();
  let out = '';
  for (let i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 === 0) out += sep;
    out += digits[i];
  }
  return neg ? `-${out}` : out;
}

/** A usage reading with its unit: (142000, "km") → "142 000 km". */
export function formatReading(value: number, unit: string | null | undefined, locale: Locale): string {
  const n = groupThousands(value, locale);
  return unit ? `${n}${NBSP}${unit}` : n;
}

/** A decimal amount: 4.3 → "4,3" (fr) / "4.3" (en); 5 → "5" (up to 3 decimals, trimmed). */
export function formatAmount(value: number, locale: Locale): string {
  const neg = value < 0;
  const abs = Math.abs(value);
  const int = Math.trunc(abs);
  const intStr = groupThousands(int, locale);
  const frac = abs - int;
  if (frac < 5e-4) return neg ? `-${intStr}` : intStr; // whole (within a 3-decimal rounding margin)
  const fracStr = frac.toFixed(3).slice(2).replace(/0+$/, '');
  return `${neg ? '-' : ''}${intStr}${DECIMAL[locale]}${fracStr}`;
}

/** Horsepower is `ch` (chevaux) on a French carte grise and `hp` in English. */
const HP_UNIT: Record<Locale, string> = { fr: 'ch', en: 'hp' };

/** Engine power, both axes middot-joined: (75, 102) → "75 kW · 102 ch". Empty when neither is known. */
export function formatPower(kw: number | null | undefined, hp: number | null | undefined, locale: Locale): string {
  const parts: string[] = [];
  if (kw != null) parts.push(`${groupThousands(kw, locale)}${NBSP}kW`);
  if (hp != null) parts.push(`${groupThousands(hp, locale)}${NBSP}${HP_UNIT[locale]}`);
  return parts.join(' · ');
}

// Explicit tables rather than Intl.DateTimeFormat, deliberately: `toLocaleString` is banned across
// this codebase (tasks.ts, schedule.ts) and vitest runs in a node environment where ICU data is not
// something to depend on. A twelve-entry array is cheaper than that risk.
const MONTHS: Record<Locale, readonly string[]> = {
  fr: ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'],
  en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
};

/** Abbreviated date: "2026-01-15" → "15 janv. 2026" / "15 Jan 2026". Returns "" for null/empty. */
export function formatDate(iso: string | null | undefined, locale: Locale): string {
  if (!iso) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  if (!m) return '';
  const [, year, month, day] = m;
  return `${Number(day)}${NBSP}${MONTHS[locale][Number(month) - 1]}${NBSP}${year}`;
}

/** Round a health score to the nearest 5 (no false precision). Locale-free. */
export function roundHealth(score: number): number {
  return Math.round(score / 5) * 5;
}

/** Health %: "70 %" (fr — French sets a space before %) / "70%" (en). */
export function formatHealthPct(score: number, locale: Locale): string {
  return 'fr' === locale ? `${roundHealth(score)}${NBSP}%` : `${roundHealth(score)}%`;
}

const HORIZON: Record<Locale, { today: string; days: string; weeks: (n: string) => string; months: (n: string) => string }> = {
  fr: {
    today: "aujourd'hui",
    days: 'dans quelques jours',
    weeks: (n) => `dans environ ${n}${NBSP}semaines`,
    months: (n) => `dans environ ${n}${NBSP}mois`,
  },
  en: {
    today: 'today',
    days: 'in a few days',
    weeks: (n) => `in about ${n}${NBSP}weeks`,
    months: (n) => `in about ${n}${NBSP}months`,
  },
};

/**
 * A forecast horizon with NO false precision (§5): a few days → "dans quelques jours"; under ~6 weeks
 * → "dans environ N semaines"; else "dans environ N mois".
 *
 * N>=2 is guaranteed by these thresholds (round(14/7)=2, round(45/30)=2), so both languages get a
 * plural that is always correct and neither needs a plural rule here. Keep N>=2 if the bounds change.
 */
export function formatHorizon(days: number, locale: Locale): string {
  const words = HORIZON[locale];
  if (days <= 0) return words.today;
  if (days < 14) return words.days;
  if (days < 45) return words.weeks(String(Math.round(days / 7)));
  return words.months(String(Math.round(days / 30)));
}

/**
 * Money from integer minor units (cents): 8900 → "89,00 €" / "€89.00".
 *
 * Still EUROS in English — the currency belongs to the garage, not to the reader; only the notation
 * moves (symbol first, dot decimal), which is the en-GB/en-US convention.
 */
export function formatEuros(cents: number, locale: Locale): string {
  const total = Math.round(cents);
  const sign = total < 0 ? '-' : '';
  const abs = Math.abs(total);
  const frac = (abs % 100).toString().padStart(2, '0');
  const int = groupThousands(Math.trunc(abs / 100), locale);

  return 'fr' === locale ? `${sign}${int},${frac}${NBSP}€` : `${sign}€${int}.${frac}`;
}

const OVERDUE: Record<Locale, (amount: string) => string> = {
  fr: (amount) => `DÉPASSÉ de ${amount}`,
  en: (amount) => `OVERDUE by ${amount}`,
};

/** Precise overdue amount (§5 exception): (3200, "km") → "DÉPASSÉ de 3 200 km". */
export function formatOverdue(value: number, unit: string | null | undefined, locale: Locale): string {
  return OVERDUE[locale](formatReading(Math.abs(value), unit, locale));
}

const BYTE_UNITS: Record<Locale, readonly string[]> = { fr: ['o', 'ko', 'Mo', 'Go'], en: ['B', 'kB', 'MB', 'GB'] };

/** A file size in decimal (SI) units, one decimal below 10: 1536000 → "1,5 Mo" / "1.5 MB". */
export function formatBytes(bytes: number, locale: Locale): string {
  const units = BYTE_UNITS[locale];
  let value = Math.max(0, bytes);
  let i = 0;
  while (value >= 1000 && i < units.length - 1) {
    value /= 1000;
    i++;
  }
  const shown = i === 0 || value >= 10 ? Math.round(value).toString() : value.toFixed(1).replace(/\.0$/, '').replace('.', DECIMAL[locale]);

  return `${shown}${NBSP}${units[i]}`;
}
