import { describe, it, expect } from 'vitest';
import { daysSince, dueDescriptor, dueSignal, latestPerformedAt, systemHealthPct } from './derive';
import type { ForecastDue } from './types';

const THIN = ' ';
const NBSP = ' ';

function due(over: Partial<ForecastDue>): ForecastDue {
  return {
    type: 'oil_change',
    action: 'change',
    component: null,
    rule: 'schedule',
    lastPerformedAt: null,
    dueAt: null,
    projectedDueAt: null,
    overdue: false,
    daysUntilDue: null,
    usage: null,
    ...over,
  };
}

describe('systemHealthPct', () => {
  it('is 0 when overdue', () => {
    expect(systemHealthPct(due({ overdue: true }))).toBe(0);
  });
  it('derives from the usage axis (remaining / interval), rounded to 5', () => {
    // interval 10 000, half used → ~50%
    expect(systemHealthPct(due({ usage: { last: 100000, due: 110000, remaining: 5000, unit: 'km' } }))).toBe(50);
    // nearly due → rounds to 5, not 3
    expect(systemHealthPct(due({ usage: { last: 100000, due: 110000, remaining: 300, unit: 'km' } }))).toBe(5);
    // fresh → 100
    expect(systemHealthPct(due({ usage: { last: 100000, due: 110000, remaining: 10000, unit: 'km' } }))).toBe(100);
  });
  it('is null on a time-only axis (no honest % derivable)', () => {
    expect(systemHealthPct(due({ daysUntilDue: 90 }))).toBeNull();
  });
});

describe('dueSignal (§2 semantics)', () => {
  it('overdue → red', () => {
    expect(dueSignal(due({ overdue: true }))).toBe('red');
  });
  it('within 30 days → amber', () => {
    expect(dueSignal(due({ daysUntilDue: 20 }))).toBe('amber');
  });
  it('within 10% of the usage interval → amber', () => {
    expect(dueSignal(due({ usage: { last: 100000, due: 110000, remaining: 800, unit: 'km' } }))).toBe('amber');
  });
  it('comfortably ahead → green', () => {
    expect(dueSignal(due({ daysUntilDue: 200, usage: { last: 100000, due: 110000, remaining: 6000, unit: 'km' } }))).toBe('green');
  });
});

describe('dueDescriptor (honest, render-ready — same values for panel + flat list)', () => {
  it('overdue → precise amount, no horizon rounding, until is null', () => {
    const d = dueDescriptor(due({ overdue: true, daysUntilDue: -80, usage: { last: 128800, due: 138800, remaining: -3200, unit: 'km' } }));
    expect(d.signal).toBe('red');
    expect(d.overdue).toBe(true);
    expect(d.horizon).toBe(`DÉPASSÉ de 3${THIN}200${NBSP}km`);
    expect(d.until).toBeNull();
    expect(d.healthPct).toBe(0);
  });
  it('overdue by TIME with km still remaining → day overshoot, never the positive remaining (live MAZDA case)', () => {
    // overdue=true on the calendar axis, but the usage axis still has 6 548 km left — must NOT read
    // "DÉPASSÉ de 6 548 km" (that figure is remaining, not an overshoot).
    const d = dueDescriptor(due({ overdue: true, daysUntilDue: -113, usage: { last: 0, due: 10000, remaining: 6548, unit: 'km' } }));
    expect(d.horizon).toBe(`DÉPASSÉ de 113${NBSP}jours`);
    expect(d.horizon).not.toContain('6');
  });
  it('overdue with neither a negative-usage nor a negative-day axis → bare DÉPASSÉ (no false precision)', () => {
    const d = dueDescriptor(due({ overdue: true, daysUntilDue: null, usage: { last: 0, due: 10000, remaining: 200, unit: 'km' } }));
    expect(d.horizon).toBe('DÉPASSÉ');
  });
  it('upcoming → rounded horizon + full-precision remaining', () => {
    const d = dueDescriptor(due({ daysUntilDue: 155, usage: { last: 136000, due: 148000, remaining: 6000, unit: 'km' } }));
    expect(d.overdue).toBe(false);
    expect(d.horizon).toBe(`dans environ 5${NBSP}mois`);
    expect(d.until).toBe(`6${THIN}000${NBSP}km`);
  });
  it('a time-only axis exposes NO precise "until" (no false day-count §5)', () => {
    const d = dueDescriptor(due({ daysUntilDue: 75, dueAt: '2026-09-12' }));
    expect(d.until).toBeNull();
    expect(d.horizon).toBe(`dans environ 3${NBSP}mois`); // rounded, never "75 j"
  });
});

describe('daysSince — whole days between an ISO date and an explicit "today" (cardinal readout)', () => {
  it('counts whole days within a month', () => {
    expect(daysSince('2026-06-01', '2026-06-29')).toBe(28);
  });
  it('spans a full year', () => {
    expect(daysSince('2025-06-29', '2026-06-29')).toBe(365);
  });
  it('is null for null / empty / unparseable input', () => {
    expect(daysSince(null, '2026-06-29')).toBeNull();
    expect(daysSince('', '2026-06-29')).toBeNull();
    expect(daysSince('pas-une-date', '2026-06-29')).toBeNull();
  });
});

describe('latestPerformedAt — most recent baseline across forecast dues', () => {
  it('returns the newest lastPerformedAt, ignoring nulls', () => {
    expect(
      latestPerformedAt([
        due({ lastPerformedAt: '2024-10-01' }),
        due({ lastPerformedAt: '2025-06-01' }),
        due({ lastPerformedAt: null }),
      ]),
    ).toBe('2025-06-01');
  });
  it('is null when nothing has a logged baseline', () => {
    expect(latestPerformedAt([due({}), due({})])).toBeNull();
    expect(latestPerformedAt([])).toBeNull();
  });
});
