import { describe, it, expect } from 'vitest';
import { buildVisits, ladder, VISIT_WINDOW_DAYS } from './visits';
import type { ForecastDue, PlannedTask } from '../types';

// The visit plan: the rail's items regrouped the way a garage actually schedules them — by TRIP TO THE
// LIFT, not one row per service. Two services falling within VISIT_WINDOW_DAYS of each other are one
// visit; decided-but-undated work groups by body region instead, because that is what shares a teardown.

function due(type: string, over: Partial<ForecastDue> = {}): ForecastDue {
  return {
    type, action: null, component: null, rule: 'schedule', lastPerformedAt: '2024-11-15',
    dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: 365, usage: null, ...over,
  };
}
const task = (type: string, status: string, id = `/t/${type}`, label?: string): PlannedTask =>
  ({ id, type, parts: [], status, ...(label ? { label } : {}) });

describe('buildVisits — horizons', () => {
  it('an overdue service lands in `now`', () => {
    const plan = buildVisits([due('oil_change', { overdue: true, dueAt: '2026-01-05' })]);
    expect(plan.now.flatMap((v) => v.items.map((i) => i.type))).toEqual(['oil_change']);
    expect(plan.next).toBeNull();
    expect(plan.later).toEqual([]);
  });

  it('the soonest dated cluster is `next`, the rest are `later`', () => {
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-03-21', daysUntilDue: 217 }),
      due('oil_change', { dueAt: '2027-07-06', daysUntilDue: 324 }),
      due('spark_plug', { dueAt: '2030-04-28', daysUntilDue: 1352 }),
    ]);
    expect(plan.next?.items.map((i) => i.type)).toEqual(['inspection']);
    expect(plan.later.map((v) => v.items.map((i) => i.type))).toEqual([['oil_change'], ['spark_plug']]);
  });

  it('undated decided work lands in `now`, never in the forecast horizons', () => {
    const plan = buildVisits([], [], [task('engine_mount', 'open')]);
    expect(plan.now.flatMap((v) => v.items.map((i) => i.type))).toEqual(['engine_mount']);
    expect(plan.next).toBeNull();
  });
});

describe('buildVisits — clustering', () => {
  it('services inside the window are ONE visit, dated at the earliest', () => {
    // The real Mazda6 case: the contrôle technique and the fuel filter fall 12 days apart. Today they
    // read as two unrelated rows; they are one trip.
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-03-21', daysUntilDue: 217 }),
      due('fuel_filter', { projectedDueAt: '2027-04-02', daysUntilDue: 230 }),
    ]);
    expect(plan.later).toEqual([]);
    expect(plan.next?.items.map((i) => i.type)).toEqual(['inspection', 'fuel_filter']);
    expect(plan.next?.date).toBe('2027-03-21');
  });

  it('services beyond the window are separate visits', () => {
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-03-21' }),
      due('oil_change', { dueAt: '2027-07-06' }),
    ]);
    expect(plan.next?.items.map((i) => i.type)).toEqual(['inspection']);
    expect(plan.later.map((v) => v.items.map((i) => i.type))).toEqual([['oil_change']]);
  });

  it('clusters chain off the PREVIOUS member, not the cluster start', () => {
    // 40 + 40 days: each hop is inside the window, so all three ride the same lift booking. Anchoring on
    // the cluster's first date would have split the third off at 80 days for no reason a mechanic knows.
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-01-01' }),
      due('oil_change', { dueAt: '2027-02-10' }),
      due('oil_filter', { dueAt: '2027-03-22' }),
    ]);
    expect(plan.next?.items).toHaveLength(3);
    expect(plan.later).toEqual([]);
  });

  it('projectedDueAt stands in when the time axis has no date', () => {
    const plan = buildVisits([due('fuel_filter', { dueAt: null, projectedDueAt: '2027-04-02' })]);
    expect(plan.next?.date).toBe('2027-04-02');
  });

  it('the window is the documented constant', () => {
    expect(VISIT_WINDOW_DAYS).toBe(60);
  });
});

describe('buildVisits — undated work groups by body region', () => {
  it('three engine-bay jobs are ONE visit', () => {
    // Sonde de température + two engine mounts: all read off the hood, one teardown, one booking.
    const plan = buildVisits([], [], [
      task('coolant_temperature_sensor', 'open', '/t/sonde'),
      task('engine_mount', 'open', '/t/mount-rear', 'support moteur arrière'),
      task('engine_mount', 'open', '/t/mount-left', 'support moteur gauche'),
    ]);
    expect(plan.now).toHaveLength(1);
    expect(plan.now[0].region).toBe('hood');
    expect(plan.now[0].items).toHaveLength(3);
  });

  it('different regions stay different visits', () => {
    const plan = buildVisits([], [], [
      task('engine_mount', 'open', '/t/mount'),
      task('brake_pads', 'open', '/t/pads'),
    ]);
    expect(plan.now.map((v) => v.region)).toEqual(['hood', 'frontAxle']);
  });
});

describe('buildVisits — what stays out', () => {
  it('coverage gaps pass through untouched, never clustered', () => {
    const plan = buildVisits([], ['timing_belt'], []);
    expect(plan.gaps.map((i) => i.type)).toEqual(['timing_belt']);
    expect(plan.now).toEqual([]);
    expect(plan.next).toBeNull();
  });

  it('a done task is not work — it never opens a visit', () => {
    const plan = buildVisits([due('oil_change', { dueAt: '2027-07-06' })], [], [task('oil_change', 'done')]);
    expect(plan.now).toEqual([]);
    expect(plan.next).toBeNull();
    expect(plan.later).toEqual([]);
  });

  it('an empty forecast produces an empty plan, not an empty visit', () => {
    const plan = buildVisits([], [], []);
    expect(plan).toEqual({ now: [], next: null, later: [], gaps: [] });
  });
});

describe('ladder — distance encodes time', () => {
  const v = (date: string) => ({ id: `visit:${date}`, date, items: [] as never[] });

  it('the first rung sits at the top with no gap', () => {
    expect(ladder([v('2027-03-21'), v('2028-04-30')])[0].gap).toBe(0);
  });

  it('a longer calendar gap makes a taller rung gap', () => {
    // 2027-07-06 → 2028-04-30 is ~10 months; 2028-04-30 → 2030-04-28 is ~24. The second must read
    // as further, or the ladder is a list with extra steps.
    const rungs = ladder([v('2027-07-06'), v('2028-04-30'), v('2030-04-28')]);
    expect(rungs[2].gap).toBeGreaterThan(rungs[1].gap);
  });

  it('gaps are normalised 0..1 against the widest hop', () => {
    const rungs = ladder([v('2027-01-01'), v('2027-02-01'), v('2027-07-01')]);
    expect(Math.max(...rungs.map((r) => r.gap))).toBe(1);
    expect(rungs.every((r) => r.gap >= 0 && r.gap <= 1)).toBe(true);
  });

  it('a single visit is a single rung, no division by zero', () => {
    expect(ladder([v('2027-03-21')])).toEqual([{ visit: v('2027-03-21'), gap: 0 }]);
  });

  it('an undated visit never breaks the scale', () => {
    const rungs = ladder([v('2027-01-01'), { id: 'now:hood', date: null, items: [] as never[] }]);
    expect(rungs[1].gap).toBe(0);
  });
});
