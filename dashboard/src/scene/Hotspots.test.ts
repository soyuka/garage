import { describe, it, expect } from 'vitest';
import { groupByRegion, worstSignal } from './Hotspots';
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
