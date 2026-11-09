import { describe, it, expect } from 'vitest';
import { crossOverdue, firstCrossable, firstNewlyOverdue } from './crossing';
import { FIXTURES } from '../fixtures/vehicles';
import type { ForecastDue } from '../types';

const due = (over: Partial<ForecastDue>): ForecastDue => ({
  type: 'oil_change', action: 'change', component: null, rule: 'schedule',
  lastPerformedAt: null, dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: null, usage: null, ...over,
});

// The §9 "service passant en rouge" crossing, on fixtures: a domain event tips the soonest due past
// due. Pure transform (the scene drives the Noyau separately). MUST stay immutable — FIXTURES is
// a shared module singleton, so a mutation would corrupt every other scenario + the parc.

describe('firstCrossable — soonest non-overdue due, usage-axis preferred', () => {
  it('finds the usage-axis non-overdue due', () => {
    expect(firstCrossable(FIXTURES.healthy!.forecast.due)).toBe(0); // oil_change, usage axis, not overdue
  });
  it('returns -1 when nothing is crossable (empty forecast)', () => {
    expect(firstCrossable(FIXTURES.unknown!.forecast.due)).toBe(-1);
  });
});

describe('firstNewlyOverdue — a service that crossed into overdue between two forecasts (live §9)', () => {
  it('returns the type that flipped not-overdue → overdue', () => {
    const prev = [due({ type: 'oil_change', overdue: false }), due({ type: 'air_filter', overdue: false })];
    const next = [due({ type: 'oil_change', overdue: true }), due({ type: 'air_filter', overdue: false })];
    expect(firstNewlyOverdue(prev, next)).toBe('oil_change');
  });
  it('ignores a service already overdue in the prior forecast', () => {
    const prev = [due({ type: 'oil_change', overdue: true })];
    const next = [due({ type: 'oil_change', overdue: true })];
    expect(firstNewlyOverdue(prev, next)).toBeNull();
  });
  it('detects a newly-overdue type that had no prior row (absent → overdue)', () => {
    const prev = [due({ type: 'oil_change', overdue: false })];
    const next = [due({ type: 'oil_change', overdue: false }), due({ type: 'brake_fluid', overdue: true })];
    expect(firstNewlyOverdue(prev, next)).toBe('brake_fluid');
  });
  it('returns null when nothing newly crossed', () => {
    const prev = [due({ type: 'oil_change', overdue: false })];
    const next = [due({ type: 'oil_change', overdue: false })];
    expect(firstNewlyOverdue(prev, next)).toBeNull();
  });
});

describe('crossOverdue — tips the soonest due past due + recomputes red health', () => {
  it('flips the targeted due to overdue with a small negative usage remaining', () => {
    const crossed = crossOverdue(FIXTURES.healthy!);
    expect(crossed.forecast.due[0].overdue).toBe(true);
    expect(crossed.forecast.due[0].usage!.remaining).toBeLessThan(0);
  });
  it('turns the overall health red with an overdue count', () => {
    const crossed = crossOverdue(FIXTURES.healthy!);
    expect(crossed.vehicle.health!.status).toBe('red');
    expect(crossed.vehicle.health!.overdue).toBeGreaterThanOrEqual(1);
  });
  it('updates nextDue consistently (overdue + a NEGATIVE usageRemaining, never overdue+positive)', () => {
    const crossed = crossOverdue(FIXTURES.healthy!);
    expect(crossed.vehicle.nextDue!.overdue).toBe(true);
    // the collection summary must agree with the item forecast row — no overdue-with-positive-remaining
    expect(crossed.vehicle.nextDue!.usageRemaining).toBeLessThan(0);
    expect(crossed.vehicle.nextDue!.usageRemaining).toBe(crossed.forecast.due[0].usage!.remaining);
  });
  it('does NOT mutate the shared fixture singleton', () => {
    crossOverdue(FIXTURES.healthy!);
    expect(FIXTURES.healthy!.forecast.due[0].overdue).toBe(false);
    expect(FIXTURES.healthy!.vehicle.health!.status).toBe('green');
  });
  it('is a no-op (same reference) when nothing is crossable', () => {
    expect(crossOverdue(FIXTURES.unknown!)).toBe(FIXTURES.unknown!);
  });
});
