import { describe, it, expect } from 'vitest';
import { groupByRegion, worstSignal, pinGroups, pinSignal } from './Hotspots';
import type { ForecastDue } from '../types';

// Pure-logic guards for the Hotspots leaf: one pin PER OCCUPIED region (services sharing a region must
// fold into a single pin), and each pin wears its region's WORST signal (red > amber > green).

function due(type: string, over: Partial<ForecastDue> = {}): ForecastDue {
  return {
    type,
    action: null,
    component: null,
    rule: 'schedule',
    lastPerformedAt: '2024-11-15',
    dueAt: null,
    projectedDueAt: null,
    overdue: false,
    daysUntilDue: 365,
    usage: null,
    ...over,
  };
}

describe('groupByRegion — one entry per occupied body region, head-to-tail order', () => {
  it('folds services sharing a region into a single entry', () => {
    const groups = groupByRegion([due('oil_change'), due('timing_belt')]);
    expect(groups).toHaveLength(1);
    expect(groups[0].region).toBe('hood');
    expect(groups[0].dues.map((d) => d.type)).toEqual(['oil_change', 'timing_belt']);
  });

  it('omits regions with no due (no empty pins)', () => {
    const groups = groupByRegion([due('oil_change')]);
    expect(groups.map((g) => g.region)).toEqual(['hood']);
  });

  it('emits occupied regions in BODY_REGIONS head-to-tail order regardless of input order', () => {
    const groups = groupByRegion([due('technical_inspection'), due('tyres'), due('oil_change')]);
    expect(groups.map((g) => g.region)).toEqual(['hood', 'windshield', 'frontAxle']);
  });

  it('routes an unmapped type to the hood pin', () => {
    const groups = groupByRegion([due('zorglub')]);
    expect(groups.map((g) => g.region)).toEqual(['hood']);
  });

  it('returns no entries for an empty forecast', () => {
    expect(groupByRegion([])).toEqual([]);
  });
});

describe('worstSignal — red beats amber beats green over a region', () => {
  it('an overdue due in the region wins red', () => {
    expect(worstSignal([due('oil_change'), due('timing_belt', { overdue: true })])).toBe('red');
  });

  it('amber (due within 30 days) wins over green', () => {
    expect(worstSignal([due('oil_change'), due('timing_belt', { daysUntilDue: 12 })])).toBe('amber');
  });

  it('all-clear stays green', () => {
    expect(worstSignal([due('oil_change'), due('timing_belt')])).toBe('green');
  });

  it('an empty region is green (never invents urgency)', () => {
    expect(worstSignal([])).toBe('green');
  });
});

describe('pinGroups — the pins shown = due regions ∪ spotlit regions', () => {
  it('matches groupByRegion when nothing is spotlit', () => {
    const forecast = [due('oil_change'), due('tyres')];
    expect(pinGroups(forecast).map((g) => g.region)).toEqual(['hood', 'frontAxle']);
    expect(pinGroups(forecast).every((g) => !g.forced)).toBe(true);
  });

  it('adds a forced pin for a spotlit region with no due (head-to-tail order)', () => {
    const groups = pinGroups([due('oil_change')], ['bay']);
    expect(groups.map((g) => g.region)).toEqual(['hood', 'bay']);
    const bay = groups.find((g) => g.region === 'bay')!;
    expect(bay.forced).toBe(true);
    expect(bay.dues).toEqual([]);
  });

  it('marks an existing due region forced when it is also spotlit (no duplicate pin)', () => {
    const groups = pinGroups([due('oil_change')], ['hood']);
    expect(groups.map((g) => g.region)).toEqual(['hood']);
    expect(groups[0].forced).toBe(true);
    expect(groups[0].dues.map((d) => d.type)).toEqual(['oil_change']);
  });
});

describe('pinSignal — a forced (spotlit) pin reads red, else its worst due signal', () => {
  it('forces red regardless of due signal', () => {
    expect(pinSignal({ region: 'bay', dues: [], forced: true })).toBe('red');
    expect(pinSignal({ region: 'hood', dues: [due('oil_change')], forced: true })).toBe('red');
  });
  it('falls back to the region worst signal when not forced', () => {
    expect(pinSignal({ region: 'hood', dues: [due('oil_change', { overdue: true })], forced: false })).toBe('red');
    expect(pinSignal({ region: 'hood', dues: [due('oil_change')], forced: false })).toBe('green');
  });
});
