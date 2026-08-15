import { BODY_REGIONS, regionForType, type BodyRegion } from './anchors';
import { buildRail, type RailItem } from './rail';
import type { ForecastDue, PlannedTask } from '../types';

// The visit plan — the rail's items regrouped by TRIP TO THE LIFT rather than one row per service.
//
// The log side has always modelled a visit correctly: a MaintenanceRecord is ONE visit carrying
// items[]. The plan side did not mirror it, so a vidange and an oil filter falling on the same date
// read as two jobs instead of one booking, and seven planned services read as seven obligations. This
// module is that mirror, kept as pure data over `buildRail` so the severity sort and the task binding
// stay exactly where they were.
//
// Two grouping axes, because undated and dated work are scheduled by different things:
//   - DATED work clusters by calendar proximity — what puts the car on the lift on the same morning.
//   - UNDATED decided work (an open task with no forecast) clusters by BODY REGION — what shares a
//     teardown. Three engine-bay jobs are one visit; a mount and a brake job are two.

/** Days between two services for them to still count as one trip. */
export const VISIT_WINDOW_DAYS = 60;

export interface Visit {
  id: string;
  /** YYYY-MM-DD of the earliest member, or null for undated region work. */
  date: string | null;
  /** Set only on region-grouped (undated / late) visits — the teardown they share. */
  region?: BodyRegion;
  items: RailItem[];
}

export interface VisitPlan {
  /** Late or decided-and-undated work, grouped by the region it is read off. */
  now: Visit[];
  /** The soonest dated cluster — the next time this car is booked in. */
  next: Visit | null;
  /** Every later cluster, soonest-first. The UI shows a count, not a list. */
  later: Visit[];
  /** Coverage gaps ("non suivi") — never clustered, never a visit. */
  gaps: RailItem[];
}

/** The calendar date a forecast item lands on: the time axis, else the usage axis projected onto it. */
function dateOf(due: ForecastDue | undefined): string | null {
  return due?.dueAt ?? due?.projectedDueAt ?? null;
}

function daysBetween(a: string, b: string): number {
  return Math.abs(Date.parse(`${b}T00:00:00Z`) - Date.parse(`${a}T00:00:00Z`)) / 86_400_000;
}

/** Region-grouped visits in head-to-tail reading order, so the plan never reshuffles between renders. */
function byRegion(items: RailItem[]): Visit[] {
  const buckets = new Map<BodyRegion, RailItem[]>();
  for (const item of items) {
    const region = regionForType(item.type);
    const bucket = buckets.get(region);
    if (bucket) bucket.push(item);
    else buckets.set(region, [item]);
  }
  return BODY_REGIONS.filter((r) => buckets.has(r)).map((region) => {
    const members = buckets.get(region) as RailItem[];
    const dates = members.map((i) => dateOf(i.due)).filter((d): d is string => d !== null).sort();
    return { id: `now:${region}`, date: dates[0] ?? null, region, items: members };
  });
}

/**
 * Chain dated items into clusters. Each hop is measured against the PREVIOUS member, not the cluster's
 * first date: 1 jan / 10 feb / 22 mar is one booking, and anchoring on the start would have split the
 * third off at 80 days for a reason no mechanic would recognise.
 */
function cluster(items: RailItem[]): Visit[] {
  const dated = items
    .map((item) => ({ item, date: dateOf(item.due) as string }))
    .sort((a, b) => a.date.localeCompare(b.date));

  const out: Visit[] = [];
  let current: RailItem[] = [];
  let anchor = '';
  for (const { item, date } of dated) {
    if (current.length > 0 && daysBetween(anchor, date) > VISIT_WINDOW_DAYS) {
      out.push({ id: `visit:${dateOf(current[0].due)}`, date: dateOf(current[0].due), items: current });
      current = [];
    }
    current.push(item);
    anchor = date;
  }
  if (current.length > 0) out.push({ id: `visit:${dateOf(current[0].due)}`, date: dateOf(current[0].due), items: current });
  return out;
}

export interface Rung {
  visit: Visit;
  /** 0..1 of the widest hop in the set — the SHARE of vertical space before this rung. */
  gap: number;
}

/**
 * Lay visits out as a time ladder: the space before a rung is proportional to the real calendar gap
 * that precedes it, normalised against the widest hop in the set. Distance is the only thing that makes
 * a horizon legible as a shape instead of a date to be read — evenly spaced rows are a list wearing a
 * timeline's clothes. Undated visits contribute no hop, so a dateless entry cannot collapse the scale.
 */
export function ladder(visits: Visit[]): Rung[] {
  const hops = visits.map((v, i) => {
    const prev = i > 0 ? visits[i - 1].date : null;
    return prev && v.date ? daysBetween(prev, v.date) : 0;
  });
  const widest = Math.max(0, ...hops);
  return visits.map((visit, i) => ({ visit, gap: widest > 0 ? hops[i] / widest : 0 }));
}

export function buildVisits(due: ForecastDue[], gaps: string[] = [], tasks: PlannedTask[] = []): VisitPlan {
  const rail = buildRail(due, gaps, tasks);

  // `done` is not work. It stays out of every horizon — the carnet is where a finished service belongs.
  const work = [...rail.todo, ...rail.ongoing, ...rail.planned].filter((i) => !i.done);

  // Late work is `now` whatever its date says, and dateless decided work is `now` because the operator
  // already chose it. Everything else is a prediction with a calendar date, so it clusters.
  const immediate = work.filter((i) => i.due?.overdue || dateOf(i.due) === null);
  const scheduled = work.filter((i) => !i.due?.overdue && dateOf(i.due) !== null);

  const clusters = cluster(scheduled);
  return {
    now: byRegion(immediate),
    next: clusters[0] ?? null,
    later: clusters.slice(1),
    gaps: rail.gaps,
  };
}
