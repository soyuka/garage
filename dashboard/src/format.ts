// French formatting — the single source for how Atelier renders telemetry (DESIGN-VISION §5 + the
// honesty non-negotiables). Thin-space (U+202F) groups thousands; a no-break space (U+00A0) binds a
// value to its unit so they never wrap apart. No false precision: forecasts round to months/weeks
// and health rounds to the nearest 5 — EXCEPT overdue, which is precise (precision matters when late).

export const THIN = ' '; // narrow no-break space — French thousands separator
export const NBSP = ' '; // no-break space — binds number to unit

/** Group thousands with a thin space: 142000 → "142 000". */
export function groupThousands(value: number): string {
  const neg = value < 0;
  const digits = Math.abs(Math.trunc(value)).toString();
  let out = '';
  for (let i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 === 0) out += THIN;
    out += digits[i];
  }
  return neg ? `-${out}` : out;
}

/** A usage reading with its unit: (142000, "km") → "142 000 km". */
export function formatReading(value: number, unit?: string | null): string {
  const n = groupThousands(value);
  return unit ? `${n}${NBSP}${unit}` : n;
}

const MONTHS_FR = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

/** French abbreviated date: "2026-01-15" → "15 janv. 2026". Returns "" for null/empty. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  if (!m) return '';
  const [, year, month, day] = m;
  return `${Number(day)}${NBSP}${MONTHS_FR[Number(month) - 1]}${NBSP}${year}`;
}

/** Round a health score to the nearest 5 (no false precision). */
export function roundHealth(score: number): number {
  return Math.round(score / 5) * 5;
}

/** Health % rounded to the nearest 5, with a no-break space: 72.3 → "70 %". */
export function formatHealthPct(score: number): string {
  return `${roundHealth(score)}${NBSP}%`;
}

/**
 * A forecast horizon with NO false precision (§5): a few days → "dans quelques jours"; under ~6 weeks
 * → "dans environ N semaines"; else "dans environ N mois".
 */
export function formatHorizon(days: number): string {
  if (days <= 0) return "aujourd'hui";
  if (days < 14) return 'dans quelques jours';
  // N>=2 is guaranteed by these thresholds (round(14/7)=2, round(44/30)=1→ wait 45 floor → round(45/30)=2),
  // so the plural 'semaines'/'mois' is always grammatically correct. Keep N>=2 if these bounds change.
  if (days < 45) return `dans environ ${Math.round(days / 7)}${NBSP}semaines`;
  return `dans environ ${Math.round(days / 30)}${NBSP}mois`;
}

/** French money from integer minor units (cents): 8900 → "89,00 €" (comma decimal, NBSP before €). */
export function formatEuros(cents: number): string {
  const total = Math.round(cents);
  const sign = total < 0 ? '-' : '';
  const abs = Math.abs(total);
  const frac = (abs % 100).toString().padStart(2, '0');
  return `${sign}${groupThousands(Math.trunc(abs / 100))},${frac}${NBSP}€`;
}

/** A "days ago" count for the cardinal readout: 393 → "393 j". Past fact → precise, grouped. */
export function formatDaysAgo(days: number): string {
  return `${groupThousands(days)}${NBSP}j`;
}

/** Precise overdue amount (§5 exception): (3200, "km") → "DÉPASSÉ de 3 200 km". */
export function formatOverdue(value: number, unit?: string | null): string {
  return `DÉPASSÉ de ${formatReading(Math.abs(value), unit)}`;
}
