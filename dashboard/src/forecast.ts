import { serviceLabel } from './labels';
import type { ForecastDue, ForecastUsage, NextDue } from './types';

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

/** A forecast item ready to render — the binding axis's copy, a health bar, a status tone, and tags. */
export interface DueView {
  title: string;
  /** Which axis is binding (the worst-health one): the calendar (`time`) or usage (engine-hours/km). */
  axis: 'usage' | 'time';
  /** The headline line for the binding axis: how late, how much left, or a date. */
  lead: string;
  /** True when `lead` is a rate-derived estimate (projectedDueAt) — render it softer. */
  estimate: boolean;
  /** Binding-axis health: 1 = freshly done, 0 = due, negative = overdue. Null when no axis is
   *  measurable (a bare estimate or a blank). */
  health: number | null;
  /** Bar width 0–100 = clamped health (full when fresh). A 3% floor keeps an overdue bar a visible
   *  red sliver. Null when there is no bar. */
  fill: number | null;
  /** Continuous green→amber→red colour interpolated from `health`. Null when there is no bar. */
  color: string | null;
  /** Maps onto StatusPill: overdue→red, otherwise soon→amber. */
  status: 'overdue' | 'soon';
  /** Quiet labels: currently just "en retard". */
  tags: string[];
}

/** One service axis reduced to the only two numbers the bar needs: fractional life left and whether
 *  it has elapsed. `lead` carries the axis-specific headline copy. */
interface AxisState {
  axis: 'usage' | 'time';
  /** Fraction of the cadence still ahead: 1 at a fresh service, 0 at due, negative once overdue. */
  health: number;
  overdue: boolean;
  lead: string;
}

/** Whole days from `a` to `b` (negative if `b` precedes `a`). */
function daysBetween(a: string, b: string): number {
  return Math.round((new Date(b).getTime() - new Date(a).getTime()) / 86_400_000);
}

/** Absolute buffer that already reads "fully green" regardless of how long the cadence is — a
 *  comfortable horizon. Without this, a long cadence (5-yr coolant, 40 000 km filter) would redden at
 *  a third spent even with a year / 9 000 km still ahead. Tunable. */
const COMFORT_DAYS = 365;
const COMFORT_USAGE: Record<ForecastUsage['unit'], number> = { km: 8000, mi: 5000, hours: 50 };

/**
 * Health an axis reads on the bar: the GREATER of the fraction of cadence left and the absolute
 * buffer left (capped at 1). So an item is green when it is either freshly serviced (high fraction)
 * OR still far from due in absolute terms (big buffer) — it only reddens once both are small. Overdue
 * (remaining ≤ 0) returns the signed fraction so the magnitude still orders the worst axis first.
 */
function axisHealth(remaining: number, cadence: number, comfort: number): number {
  if (remaining <= 0) return remaining / cadence;
  return Math.max(remaining / cadence, Math.min(1, remaining / comfort));
}

/** The bar's three colour anchors, matching the --mpa status tokens (ok / warn / alarm). */
const OK: [number, number, number] = [52, 211, 153]; // #34D399
const WARN: [number, number, number] = [251, 191, 36]; // #FBBF24
const ALARM: [number, number, number] = [244, 63, 94]; // #F43F5E

/** Continuous health→colour ramp: alarm at ≤0, warn at 0.5, ok at ≥1, linearly interpolated between. */
function healthColor(health: number): string {
  const mix = (a: [number, number, number], b: [number, number, number], t: number): string => {
    const c = a.map((v, i) => Math.round(v + (b[i] - v) * t));
    return `rgb(${c[0]}, ${c[1]}, ${c[2]})`;
  };
  if (health <= 0) return mix(ALARM, ALARM, 0);
  if (health >= 1) return mix(OK, OK, 0);
  return health >= 0.5 ? mix(WARN, OK, (health - 0.5) / 0.5) : mix(ALARM, WARN, health / 0.5);
}

/** The calendar axis: life left is the remaining days over the full last→due span. Present only with
 *  both a firm `dueAt` and the baseline date the span is measured from. */
function timeAxis(d: ForecastDue): AxisState | null {
  if (!d.dueAt || !d.lastPerformedAt || d.daysUntilDue == null) return null;
  const span = daysBetween(d.lastPerformedAt, d.dueAt);
  if (span <= 0) return null;
  const overdue = d.daysUntilDue <= 0;
  const lead = overdue ? `en retard de ${-d.daysUntilDue} j` : `prévu le ${fullDate(d.dueAt)}`;
  return { axis: 'time', health: axisHealth(d.daysUntilDue, span, COMFORT_DAYS), overdue, lead };
}

/** The usage axis: life left is the remaining hours/km over the cadence (due−last). */
function usageAxis(d: ForecastDue): AxisState | null {
  if (!d.usage) return null;
  const cadence = d.usage.due - d.usage.last;
  if (cadence <= 0) return null;
  const u = unitLabel(d.usage.unit);
  const overdue = d.usage.remaining <= 0;
  const lead = overdue
    ? `${group(Math.abs(d.usage.remaining))} ${u} de retard`
    : `${group(d.usage.remaining)} ${u} restantes`;
  const health = axisHealth(d.usage.remaining, cadence, COMFORT_USAGE[d.usage.unit]);
  return { axis: 'usage', health, overdue, lead };
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
 * Project one forecast due item onto display copy. A service runs on up to two cadences (calendar +
 * usage); the BINDING axis is the one with the least life left (time wins ties — the calendar matters
 * most). That axis drives the headline and a health bar: full and green when freshly serviced, draining
 * toward an empty red sliver as it comes due. When no axis is measurable, a `projectedDueAt` estimate
 * ("~ déc. 2026") or a bare "—" is shown with no bar. Overdue items take the red status and tag.
 */
export function formatDue(d: ForecastDue): DueView {
  const tags: string[] = [];
  if (d.overdue) tags.push('en retard');
  const status: DueView['status'] = d.overdue ? 'overdue' : 'soon';

  // Worst-health axis binds; on a tie the calendar (time) is kept as the more important one.
  const axes = [timeAxis(d), usageAxis(d)].filter((a): a is AxisState => a != null);
  const binding = axes.reduce<AxisState | null>((worst, a) => {
    if (!worst) return a;
    if (a.health < worst.health) return a;
    if (a.health === worst.health && a.axis === 'time') return a;
    return worst;
  }, null);

  if (binding) {
    const fill = binding.health <= 0 ? 3 : Math.min(100, Math.round(binding.health * 100));
    return {
      title: dueTitle(d),
      axis: binding.axis,
      lead: binding.lead,
      estimate: false,
      health: binding.health,
      fill,
      color: healthColor(binding.health),
      status,
      tags,
    };
  }

  // No measurable axis: a softer usage-rate projection if we have one, else a bare dash. No bar.
  const lead = d.projectedDueAt ? `~ ${monthYear(d.projectedDueAt)}` : '—';
  return {
    title: dueTitle(d),
    axis: 'time',
    lead,
    estimate: d.projectedDueAt != null,
    health: null,
    fill: null,
    color: null,
    status,
    tags,
  };
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
