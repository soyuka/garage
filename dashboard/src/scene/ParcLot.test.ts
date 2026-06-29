import { describe, it, expect } from 'vitest';
import type { NextDue, VehicleSummary } from '../types';
import { NBSP, THIN } from '../format';
import { parcSignal, byRecency, horizonFor } from './ParcLot';

// The parc listing carries only `nextDue` (the collection omits `health`), so the card signal is
// re-derived from nextDue alone — contract-faithful. These pin that derivation + the recency sort.

function vehicle(over: Partial<VehicleSummary>): VehicleSummary {
  return {
    '@id': '/garage/vehicles/01TEST',
    make: 'Renault',
    model: 'Clio',
    vehicleType: 'car',
    source: 'user',
    factCount: 0,
    addedAt: '2025-01-01',
    ...over,
  };
}

function nextDue(over: Partial<NextDue>): NextDue {
  return {
    type: 'oil_change',
    action: 'change',
    label: 'Vidange',
    rule: 'schedule',
    overdue: false,
    dueAt: null,
    projectedDueAt: null,
    daysUntilDue: null,
    usageRemaining: null,
    usageUnit: null,
    ...over,
  };
}

describe('parcSignal — card signal derived from nextDue only', () => {
  it('overdue → red', () => {
    expect(parcSignal(vehicle({ nextDue: nextDue({ overdue: true, daysUntilDue: -80 }) }))).toBe('red');
  });
  it('within 30 days → amber', () => {
    expect(parcSignal(vehicle({ nextDue: nextDue({ daysUntilDue: 16 }) }))).toBe('amber');
    expect(parcSignal(vehicle({ nextDue: nextDue({ daysUntilDue: 30 }) }))).toBe('amber');
  });
  it('beyond 30 days → green', () => {
    expect(parcSignal(vehicle({ nextDue: nextDue({ daysUntilDue: 155 }) }))).toBe('green');
  });
  it('a due with no day count is still tracked → green', () => {
    expect(parcSignal(vehicle({ nextDue: nextDue({ daysUntilDue: null }) }))).toBe('green');
  });
  it('overdue dominates even with a positive day count', () => {
    expect(parcSignal(vehicle({ nextDue: nextDue({ overdue: true, daysUntilDue: 5 }) }))).toBe('red');
  });
  it('null nextDue → unknown (NOT healthy)', () => {
    expect(parcSignal(vehicle({ nextDue: null }))).toBe('unknown');
    expect(parcSignal(vehicle({ nextDue: undefined }))).toBe('unknown');
  });
});

describe('horizonFor — the next-due one-liner (honest overdue axis)', () => {
  it('overdue on the usage axis (negative remaining) → km overshoot', () => {
    expect(horizonFor(nextDue({ overdue: true, usageRemaining: -3200, usageUnit: 'km', daysUntilDue: -80 }))).toBe(`DÉPASSÉ de 3${THIN}200${NBSP}km`);
  });
  it('overdue by TIME with km still remaining → day overshoot, never the positive remaining (live MAZDA case)', () => {
    const h = horizonFor(nextDue({ overdue: true, usageRemaining: 6548, usageUnit: 'km', daysUntilDue: -113 }));
    expect(h).toBe(`DÉPASSÉ de 113${NBSP}jours`);
    expect(h).not.toContain('6');
  });
  it('overdue with no negative axis → bare DÉPASSÉ', () => {
    expect(horizonFor(nextDue({ overdue: true, usageRemaining: 200, usageUnit: 'km', daysUntilDue: null }))).toBe('DÉPASSÉ');
  });
  it('upcoming with a day count → rounded horizon', () => {
    expect(horizonFor(nextDue({ daysUntilDue: 155 }))).toBe(`dans environ 5${NBSP}mois`);
  });
});

describe('byRecency — most-recent-first by addedAt', () => {
  it('sorts newest addedAt first, leaving the input untouched', () => {
    const input = [
      vehicle({ '@id': '/garage/vehicles/A', addedAt: '2025-09-30' }),
      vehicle({ '@id': '/garage/vehicles/B', addedAt: '2026-06-20' }),
      vehicle({ '@id': '/garage/vehicles/C', addedAt: '2025-11-02' }),
    ];
    const out = byRecency(input);
    expect(out.map((v) => v['@id'])).toEqual(['/garage/vehicles/B', '/garage/vehicles/C', '/garage/vehicles/A']);
    expect(input.map((v) => v['@id'])).toEqual(['/garage/vehicles/A', '/garage/vehicles/B', '/garage/vehicles/C']);
  });
  it('is stable for equal addedAt (keeps source order)', () => {
    const input = [
      vehicle({ '@id': '/garage/vehicles/X', addedAt: '2025-01-01' }),
      vehicle({ '@id': '/garage/vehicles/Y', addedAt: '2025-01-01' }),
    ];
    expect(byRecency(input).map((v) => v['@id'])).toEqual(['/garage/vehicles/X', '/garage/vehicles/Y']);
  });
});
