import type { Locale } from './i18n/locale';
import { messages } from './i18n/messages';
import { formatHorizon, formatOverdue, formatReading, roundHealth } from './format';
import type { ForecastDue, Signal } from './types';

// Tier-2 display derivations: turn the backend forecast into the per-system values the truth-panel
// and flat plan list show. Rebuilt fresh from the contract + vision §2 (never lifted from the old
// app). The truth-panel is authoritative, so these stay honest: no invented % when none is derivable.

/**
 * Per-system health %, derived from the usage axis (remaining / interval), rounded to the nearest 5.
 * 0 when overdue. `null` on a time-only axis — we show no false percentage we can't honestly derive.
 */
export function systemHealthPct(due: ForecastDue): number | null {
  if (due.overdue) return 0;
  const u = due.usage;
  if (u && u.due > u.last) {
    const interval = u.due - u.last;
    const pct = Math.max(0, Math.min(100, (u.remaining / interval) * 100));
    return roundHealth(pct);
  }
  return null;
}

/** Signal for one forecast item: red overdue · amber within 30 days OR 10% of interval · else green. */
export function dueSignal(due: ForecastDue): Signal {
  if (due.overdue) return 'red';
  const soonByDays = due.daysUntilDue != null && due.daysUntilDue <= 30;
  const u = due.usage;
  const soonByUsage = u != null && u.due > u.last && u.remaining / (u.due - u.last) <= 0.1;
  return soonByDays || soonByUsage ? 'amber' : 'green';
}

/** The honest, render-ready descriptor for one forecast item — the SAME values the truth-panel and
 *  the flat plan list both show, so they never disagree. Overdue is precise; the rest avoids false
 *  precision (rounded horizon). */
export interface DueDescriptor {
  signal: Signal;
  overdue: boolean;
  healthPct: number | null;
  /** "DÉPASSÉ de 3 200 km" when overdue, else "dans environ 2 mois". */
  horizon: string;
  /** remaining distance/time until due, full precision, e.g. "6 000 km" — null when overdue. */
  until: string | null;
}

/** The grayscale-legible TEXT tag for a row — the single source both the panel and the list use, so
 *  the signal is NEVER conveyed by color alone (§8). */
export function signalTag(overdue: boolean, signal: Signal, locale: Locale): { text: string; tone: 'late' | 'soon' | 'ok' } {
  const t = messages(locale);
  if (overdue) return { text: t.signal.late, tone: 'late' };
  if (signal === 'amber') return { text: t.signal.soon, tone: 'soon' };
  return { text: t.signal.ok, tone: 'ok' };
}

/** The newest `lastPerformedAt` across forecast dues (ISO sorts chronologically), or null if none. */
export function latestPerformedAt(due: ForecastDue[]): string | null {
  const dates = due.map((d) => d.lastPerformedAt).filter((d): d is string => !!d);
  return dates.length === 0 ? null : dates.reduce((m, d) => (d > m ? d : m));
}

export function dueDescriptor(due: ForecastDue, locale: Locale): DueDescriptor {
  const t = messages(locale);
  const signal = dueSignal(due);
  const healthPct = systemHealthPct(due);
  if (due.overdue) {
    // Show the axis that is ACTUALLY past due. A service can be overdue on the calendar while the
    // usage axis still has distance left (positive remaining) — formatting that positive remaining as
    // an overshoot would lie. Prefer a negative usage remaining (km/h overshoot), else a negative day
    // count, else a bare "DÉPASSÉ" (no false precision).
    let horizon: string;
    if (due.usage && due.usage.remaining < 0 && due.usage.unit) {
      horizon = formatOverdue(due.usage.remaining, due.usage.unit, locale);
    } else if (due.daysUntilDue != null && due.daysUntilDue < 0) {
      const days = Math.abs(due.daysUntilDue);
      horizon = formatOverdue(days, t.forecast.dayUnit(days), locale);
    } else {
      horizon = t.forecast.overdue;
    }
    return { signal, overdue: true, healthPct, horizon, until: null };
  }
  const horizon = due.daysUntilDue != null ? formatHorizon(due.daysUntilDue, locale) : '';
  // `until` is a full-precision remaining reading — honest ONLY on a usage axis (km/h). A time axis
  // has no honest precise "until" (a raw day count would be the false precision §5 forbids); the
  // rounded `horizon` carries the time forecast instead.
  const until = due.usage ? formatReading(due.usage.remaining, due.usage.unit, locale) : null;
  return { signal, overdue: false, healthPct, horizon, until };
}
