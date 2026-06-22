import { serviceLabel } from './labels';
import type { ForecastDue, NextDue } from './types';

/** French verb for each forecast action; unknown/absent actions contribute nothing to the title. */
const ACTION_LABELS: Record<NonNullable<ForecastDue['action']>, string> = {
  change: 'changer',
  replace: 'remplacer',
  clean: 'nettoyer',
  inspect: 'contrôler',
};

/** "hours" reads "h" in French; km/mi stay verbatim. */
function unitLabel(unit: string): string {
  return unit === 'hours' ? 'h' : unit;
}

/**
 * French thousands grouping (138000 → "138 000"), matching the card's tabular readings. Node's
 * Intl groups with a narrow no-break space (U+202F); normalise it to a plain space as state.ts does.
 */
function group(value: number): string {
  return value.toLocaleString('fr-FR').replace(/ | /g, ' ');
}

/** "déc. 2026" — the soft month-year form used for projected/estimated dates and the rail line. */
function monthYear(isoDate: string): string {
  const d = new Date(isoDate);
  if (Number.isNaN(d.getTime())) return isoDate;
  return d.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
}

/** "15 déc. 2026" — the firm day form for an authoritative due date. */
function fullDate(isoDate: string): string {
  const d = new Date(isoDate);
  if (Number.isNaN(d.getTime())) return isoDate;
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** A forecast item ready to render — axis-aware copy, a status tone, progress, and quiet tags. */
export interface DueView {
  title: string;
  /** Which axis drives the headline — usage (engine-hours/km) vs the calendar. */
  axis: 'usage' | 'time';
  /** The headline line: remaining usage, a firm date, or a softer estimate. */
  lead: string;
  /** A supporting line (the last reading), empty when none. */
  sub: string;
  /** Progress consumed against the cadence (0–100), null for time-axis items with no bar. */
  progress: number | null;
  /** True when `lead` is a rate-derived estimate (projectedDueAt) — render it softer. */
  estimate: boolean;
  /** Maps onto StatusPill: overdue→red, otherwise soon→amber. */
  status: 'overdue' | 'soon';
  /** Quiet labels: "en retard" and/or "générique" (a default, less-authoritative rule). */
  tags: string[];
}

/** The card title: "Vidange · changer", "Filtre à air · remplacer — cartouche" with a component. */
function dueTitle(d: ForecastDue): string {
  const base = serviceLabel(d.type);
  const action = d.action ? ACTION_LABELS[d.action] ?? d.action : null;
  let title = action ? `${base} · ${action}` : base;
  if (d.component) title += ` — ${d.component}`;
  return title;
}

/**
 * Project one forecast due item onto display copy. Usage-axis items (with `usage`) lead with the
 * remaining engine-hours/km and carry a progress bar (consumed vs the cadence due−last). Time-axis
 * items lead with a firm `dueAt` ("prévu le …") or, failing that, a softer `projectedDueAt` estimate
 * ("~ déc. 2026"). Overdue items take the red status and an "en retard" tag; a `default` rule earns a
 * quiet "générique" tag (general-knowledge interval, less authoritative than a real schedule).
 */
export function formatDue(d: ForecastDue): DueView {
  const tags: string[] = [];
  if (d.overdue) tags.push('en retard');
  if (d.rule === 'default') tags.push('générique');
  const status: DueView['status'] = d.overdue ? 'overdue' : 'soon';

  if (d.usage) {
    const u = unitLabel(d.usage.unit);
    const lead = d.usage.remaining < 0
      ? `${group(Math.abs(d.usage.remaining))} ${u} de retard`
      : `${group(d.usage.remaining)} ${u} restantes`;
    const cadence = d.usage.due - d.usage.last;
    const consumed = cadence - d.usage.remaining;
    const progress = cadence > 0 ? Math.max(0, Math.min(100, Math.round((consumed / cadence) * 100))) : null;
    return {
      title: dueTitle(d),
      axis: 'usage',
      lead,
      sub: `dernière : ${group(d.usage.last)} ${u}`,
      progress,
      estimate: false,
      status,
      tags,
    };
  }

  // Time axis: a firm due date wins; otherwise the usage-rate projection, shown as an estimate.
  let lead = '—';
  let estimate = false;
  if (d.dueAt) {
    lead = `prévu le ${fullDate(d.dueAt)}`;
  } else if (d.projectedDueAt) {
    lead = `~ ${monthYear(d.projectedDueAt)}`;
    estimate = true;
  }
  return { title: dueTitle(d), axis: 'time', lead, sub: '', progress: null, estimate, status, tags };
}

/** The compact rail line for a vehicle's next-due, plus whether it reads red. */
export interface NextDueLine {
  text: string;
  overdue: boolean;
}

/**
 * Format the listing's compact `nextDue` into the sidebar rail's one-line forecast — e.g.
 * "Vidange · 50 h", "Contrôle technique · en retard", "Courroie de distribution · ~ déc. 2026".
 * Returns null when nothing is predictable yet (the rail then renders no extra line).
 */
export function formatNextDue(next: NextDue | null | undefined): NextDueLine | null {
  if (!next) return null;
  const label = serviceLabel(next.type);
  let detail: string;
  if (next.overdue) {
    detail = 'en retard';
  } else if (next.usageRemaining != null && next.usageUnit) {
    detail = `${group(next.usageRemaining)} ${unitLabel(next.usageUnit)}`;
  } else if (next.dueAt) {
    detail = monthYear(next.dueAt);
  } else if (next.projectedDueAt) {
    detail = `~ ${monthYear(next.projectedDueAt)}`;
  } else {
    detail = 'à prévoir';
  }
  return { text: `${label} · ${detail}`, overdue: next.overdue };
}
