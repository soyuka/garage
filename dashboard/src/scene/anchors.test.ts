import { describe, it, expect } from 'vitest';
import { regionForType, regionAnchors, RIM, BODY_REGIONS, type BodyRegion } from './anchors';

// The shared geometry seam every 3D leaf (Hotspots, BodyGlow, RimGauge) reads. Pin positions derive
// from CarProxy DIMS so the blueprint car and its annotations never drift apart.

describe('regionForType — service type → body region (where you read it off the car)', () => {
  it('engine services read off the hood', () => {
    expect(regionForType('oil_change')).toBe('hood');
    expect(regionForType('timing_belt')).toBe('hood');
  });
  it('braking + tyres read off the front axle', () => {
    expect(regionForType('brake_pads')).toBe('frontAxle');
    expect(regionForType('brake_discs')).toBe('frontAxle');
    expect(regionForType('tyres')).toBe('frontAxle');
  });
  it('the periodic inspection reads off the windshield badge', () => {
    expect(regionForType('technical_inspection')).toBe('windshield');
  });
  it('filtration reads off the engine bay', () => {
    expect(regionForType('air_filter')).toBe('bay');
    expect(regionForType('cabin_filter')).toBe('bay');
  });
  it('a chain reads off the rear axle (moto)', () => {
    expect(regionForType('chain')).toBe('rearAxle');
  });
  it('an unmapped type falls back to the hood, never throws', () => {
    expect(regionForType('zorglub')).toBe('hood');
  });
});

describe('regionAnchors — per-body-class 3D pin positions derived from CarProxy DIMS', () => {
  const CLASSES = ['hatchback', 'berline', 'suv', 'utilitaire', 'moto'] as const;
  it('places every region above the floor for every body class', () => {
    for (const bc of CLASSES) {
      const a = regionAnchors(bc);
      for (const r of BODY_REGIONS) {
        expect(a[r], `${bc}.${r} missing`).toBeDefined();
        const [x, y, z] = a[r];
        expect(Number.isFinite(x) && Number.isFinite(y) && Number.isFinite(z), `${bc}.${r} non-finite`).toBe(true);
        expect(y, `${bc}.${r} below floor`).toBeGreaterThan(0);
      }
    }
  });
  it('orients the front (hood + front axle) ahead of the rear axle on the x-axis', () => {
    const a = regionAnchors('berline');
    expect(a.hood[0]).toBeGreaterThan(a.rearAxle[0]);
    expect(a.frontAxle[0]).toBeGreaterThan(a.rearAxle[0]);
  });
});

describe('RIM — turntable rim-gauge annulus constants', () => {
  it('is a thin flat ring just above the disc', () => {
    expect(RIM.outer).toBeGreaterThan(RIM.inner);
    expect(RIM.y).toBeGreaterThan(0);
    expect(RIM.segments).toBeGreaterThan(8);
  });
});

// type-level guard: BODY_REGIONS enumerates exactly the BodyRegion union
const _exhaustive: Record<BodyRegion, true> = { hood: true, windshield: true, frontAxle: true, rearAxle: true, bay: true };
describe('BODY_REGIONS', () => {
  it('enumerates every region key', () => {
    expect(new Set(BODY_REGIONS)).toEqual(new Set(Object.keys(_exhaustive)));
  });
});
