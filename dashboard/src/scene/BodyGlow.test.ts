import { describe, it, expect } from 'vitest';
import { sickGlows } from './BodyGlow';
import type { ForecastDue } from '../types';

// sickGlows is the honesty-critical selection: only amber/red dues glow (green = untouched steel-blue),
// one halo per region carrying the worst signal, anchored on the same CarProxy DIMS the blueprint draws.

function due(type: string, over: ForecastDue['overdue'], days: number | null): ForecastDue {
  return {
    type,
    action: null,
    component: null,
    rule: 'schedule',
    lastPerformedAt: null,
    dueAt: null,
    projectedDueAt: null,
    overdue: over,
    daysUntilDue: days,
    usage: null,
  };
}

describe('sickGlows — which regions of the car glow, and how badly', () => {
  it('skips green dues entirely (a healthy car has no glow)', () => {
    const out = sickGlows('berline', [due('oil_change', false, 400)]);
    expect(out).toEqual([]);
  });

  it('glows amber for a due-soon service', () => {
    const out = sickGlows('berline', [due('oil_change', false, 12)]);
    expect(out).toHaveLength(1);
    expect(out[0].region).toBe('hood');
    expect(out[0].signal).toBe('amber');
  });

  it('glows red for an overdue service', () => {
    const out = sickGlows('berline', [due('brake_pads', true, null)]);
    expect(out).toHaveLength(1);
    expect(out[0].region).toBe('frontAxle');
    expect(out[0].signal).toBe('red');
  });

  it('de-dupes several sick dues sharing a region into ONE halo', () => {
    const out = sickGlows('berline', [due('oil_change', false, 10), due('timing_belt', false, 5)]);
    expect(out).toHaveLength(1);
    expect(out[0].region).toBe('hood');
  });

  it('keeps the WORST signal when red and amber share a region', () => {
    const out = sickGlows('berline', [due('oil_change', false, 10), due('coolant', true, null)]);
    expect(out).toHaveLength(1);
    expect(out[0].region).toBe('hood');
    expect(out[0].signal).toBe('red');
  });

  it('emits a separate halo per sick region', () => {
    const out = sickGlows('berline', [due('oil_change', false, 10), due('brake_pads', true, null)]);
    expect(out).toHaveLength(2);
    const byRegion = Object.fromEntries(out.map((g) => [g.region, g.signal]));
    expect(byRegion.hood).toBe('amber');
    expect(byRegion.frontAxle).toBe('red');
  });

  it('positions each halo on the matching CarProxy anchor', () => {
    const out = sickGlows('berline', [due('brake_pads', true, null)]);
    const [x, y, z] = out[0].pos;
    expect(Number.isFinite(x) && Number.isFinite(y) && Number.isFinite(z)).toBe(true);
    expect(y).toBeGreaterThan(0);
  });

  it('handles every body class without throwing', () => {
    for (const bc of ['hatchback', 'berline', 'suv', 'utilitaire', 'moto'] as const) {
      const out = sickGlows(bc, [due('oil_change', true, null)]);
      expect(out).toHaveLength(1);
      expect(out[0].signal).toBe('red');
    }
  });

  it('returns nothing for an empty forecast', () => {
    expect(sickGlows('berline', [])).toEqual([]);
  });

  it('force-glows a highlighted region red even with no due (the spotlight)', () => {
    const out = sickGlows('berline', [], ['bay']);
    expect(out).toHaveLength(1);
    expect(out[0].region).toBe('bay');
    expect(out[0].signal).toBe('red');
  });

  it('upgrades a highlighted region to red even when it is only amber-due', () => {
    const out = sickGlows('berline', [due('oil_change', false, 12)], ['hood']);
    expect(out).toHaveLength(1);
    expect(out[0].region).toBe('hood');
    expect(out[0].signal).toBe('red');
  });

  it('leaves non-highlighted sick regions as they are', () => {
    const out = sickGlows('berline', [due('oil_change', false, 12), due('brake_pads', true, null)], ['bay']);
    const byRegion = Object.fromEntries(out.map((g) => [g.region, g.signal]));
    expect(byRegion.hood).toBe('amber');
    expect(byRegion.frontAxle).toBe('red');
    expect(byRegion.bay).toBe('red');
  });
});
