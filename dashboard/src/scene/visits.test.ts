import { describe, it, expect } from 'vitest';
import { buildVisits, laterFrom, ladder, rowClock, visitClock, visitRegions, visitSignal, VISIT_WINDOW_DAYS } from './visits';
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
    const plan = buildVisits([due('oil_change', { overdue: true, dueAt: '2026-01-05' })], 'fr');
    expect(plan.now.flatMap((v) => v.items.map((i) => i.type))).toEqual(['oil_change']);
    expect(plan.next).toBeNull();
    expect(plan.later).toEqual([]);
  });

  it('the soonest dated cluster is `next`, the rest are `later`', () => {
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-03-21', daysUntilDue: 217 }),
      due('oil_change', { dueAt: '2027-07-06', daysUntilDue: 324 }),
      due('spark_plug', { dueAt: '2030-04-28', daysUntilDue: 1352 }),
    ], 'fr');
    expect(plan.next?.items.map((i) => i.type)).toEqual(['inspection']);
    expect(plan.later.map((v) => v.items.map((i) => i.type))).toEqual([['oil_change'], ['spark_plug']]);
  });

  it('undated decided work lands in `now`, never in the forecast horizons', () => {
    const plan = buildVisits([], 'fr', [], [task('engine_mount', 'open')]);
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
    ], 'fr');
    expect(plan.later).toEqual([]);
    expect(plan.next?.items.map((i) => i.type)).toEqual(['inspection', 'fuel_filter']);
    expect(plan.next?.date).toBe('2027-03-21');
  });

  it('services beyond the window are separate visits', () => {
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-03-21' }),
      due('oil_change', { dueAt: '2027-07-06' }),
    ], 'fr');
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
    ], 'fr');
    expect(plan.next?.items).toHaveLength(3);
    expect(plan.later).toEqual([]);
  });

  it('projectedDueAt stands in when the time axis has no date', () => {
    const plan = buildVisits([due('fuel_filter', { dueAt: null, projectedDueAt: '2027-04-02' })], 'fr');
    expect(plan.next?.date).toBe('2027-04-02');
  });

  it('the window is the documented constant', () => {
    expect(VISIT_WINDOW_DAYS).toBe(60);
  });
});

describe('buildVisits — undated work groups by body region', () => {
  it('three engine-bay jobs are ONE visit', () => {
    // Sonde de température + two engine mounts: all read off the hood, one teardown, one booking.
    const plan = buildVisits([], 'fr', [], [
      task('coolant_temperature_sensor', 'open', '/t/sonde'),
      task('engine_mount', 'open', '/t/mount-rear', 'support moteur arrière'),
      task('engine_mount', 'open', '/t/mount-left', 'support moteur gauche'),
    ]);
    expect(plan.now).toHaveLength(1);
    expect(plan.now[0].region).toBe('hood');
    expect(plan.now[0].items).toHaveLength(3);
  });

  it('different regions stay different visits', () => {
    const plan = buildVisits([], 'fr', [], [
      task('engine_mount', 'open', '/t/mount'),
      task('brake_pads', 'open', '/t/pads'),
    ]);
    expect(plan.now.map((v) => v.region)).toEqual(['hood', 'frontAxle']);
  });
});

describe('buildVisits — what stays out', () => {
  it('coverage gaps pass through untouched, never clustered', () => {
    const plan = buildVisits([], 'fr', ['timing_belt'], []);
    expect(plan.gaps.map((i) => i.type)).toEqual(['timing_belt']);
    expect(plan.now).toEqual([]);
    expect(plan.next).toBeNull();
  });

  it('a done task is not work — it never opens a visit', () => {
    const plan = buildVisits([due('oil_change', { dueAt: '2027-07-06' })], 'fr', [], [task('oil_change', 'done')]);
    expect(plan.now).toEqual([]);
    expect(plan.next).toBeNull();
    expect(plan.later).toEqual([]);
  });

  it('an empty forecast produces an empty plan, not an empty visit', () => {
    const plan = buildVisits([], 'fr', [], []);
    expect(plan).toEqual({ now: [], next: null, later: [], gaps: [] });
  });
});

describe('visitSignal — a card carries its worst news', () => {
  it('is the worst signal among the visit’s items', () => {
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-03-21', daysUntilDue: 217 }),
      due('fuel_filter', { projectedDueAt: '2027-04-02', daysUntilDue: 20 }),
    ], 'fr');
    // the fuel filter is 20 days out → amber; the visit reads amber even though the CT is green.
    expect(plan.next && visitSignal(plan.next)).toBe('amber');
  });

  it('an overdue member makes the whole visit red', () => {
    const plan = buildVisits([], 'fr', [], [task('engine_mount', 'open'), task('oil_change', 'open')]);
    const visit = { ...plan.now[0], items: plan.now[0].items.map((i, n) => (n === 0 ? { ...i, signal: 'red' as const } : i)) };
    expect(visitSignal(visit)).toBe('red');
  });

  it('an empty visit is green, never a false alarm', () => {
    expect(visitSignal({ id: 'x', date: null, items: [] })).toBe('green');
  });
});

describe('visitRegions — a card names a part of the car', () => {
  it('a region visit names its own region', () => {
    const plan = buildVisits([], 'fr', [], [task('engine_mount', 'open')]);
    expect(visitRegions(plan.now[0])).toEqual(['hood']);
  });

  it('a dated cluster names every region it touches, head-to-tail, deduped', () => {
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-03-21' }), // windshield
      due('fuel_filter', { dueAt: '2027-04-02' }), // bay
      due('cabin_filter', { dueAt: '2027-04-10' }), // bay again
    ], 'fr');
    expect(plan.next && visitRegions(plan.next)).toEqual(['windshield', 'bay']);
  });

  it('an empty visit aims at nothing (the head is not a control)', () => {
    expect(visitRegions({ id: 'x', date: null, items: [] })).toEqual([]);
  });
});

describe('visitClock / rowClock — one clock per visit, not one per row', () => {
  it('a dated cluster’s head owns the date AND the horizon', () => {
    const plan = buildVisits([due('inspection', { dueAt: '2027-03-21', daysUntilDue: 217 })], 'fr');
    const clock = visitClock(plan.next!);
    expect(clock.date).toBe('2027-03-21');
    expect(clock.horizon).toBeTruthy();
  });

  it('a member on the head’s own date says nothing more — the two-clock row is gone', () => {
    // The §5 defect: « dans environ 11 mois » AND « 6 juil. 2027 » on every one of two rows that are
    // one booking. The head says it once; the rows say nothing.
    const plan = buildVisits([
      due('oil_change', { dueAt: '2027-07-06', daysUntilDue: 324 }),
      due('oil_filter', { dueAt: '2027-07-06', daysUntilDue: 324 }),
    ], 'fr');
    const visit = plan.next!;
    expect(visit.items).toHaveLength(2);
    for (const item of visit.items) expect(rowClock(visit, item)).toEqual({ date: null, horizon: null });
  });

  it('a member that lands on a DIFFERENT day still says which day — compression never hides a date', () => {
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-03-21', daysUntilDue: 217 }),
      due('fuel_filter', { projectedDueAt: '2027-04-02', daysUntilDue: 230 }),
    ], 'fr');
    const visit = plan.next!;
    expect(rowClock(visit, visit.items[0]).date).toBeNull();
    expect(rowClock(visit, visit.items[1]).date).toBe('2027-04-02');
  });

  it('a region visit has NO head clock, so every row keeps its own overshoot', () => {
    const plan = buildVisits([due('oil_change', { overdue: true, dueAt: '2026-01-05', daysUntilDue: -40 })], 'fr');
    const visit = plan.now[0];
    expect(visitClock(visit)).toEqual({ date: null, horizon: null });
    expect(rowClock(visit, visit.items[0]).horizon).toContain('DÉPASSÉ');
  });
});

describe('laterFrom — far is far, and says how far', () => {
  it('names the year the tail starts', () => {
    const plan = buildVisits([
      due('inspection', { dueAt: '2027-03-21' }),
      due('oil_change', { dueAt: '2028-07-06' }),
      due('spark_plug', { dueAt: '2030-04-28' }),
    ], 'fr');
    expect(laterFrom(plan.later)).toBe('2028');
  });

  it('an undated tail names nothing rather than guessing', () => {
    expect(laterFrom([{ id: 'x', date: null, items: [] }])).toBeNull();
  });

  it('an empty tail names nothing', () => {
    expect(laterFrom([])).toBeNull();
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
