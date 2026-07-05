import { describe, it, expect } from 'vitest';
import { regionForType, regionAnchors, regionsForKeys, regionForFrame, BODY_REGIONS, type BodyRegion } from './anchors';
import type { MercureFrame } from '../types';

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

describe('regionsForKeys — which car regions a spotlight lights (service-type keys only)', () => {
  it('maps service-type keys to their regions, deduped in head-to-tail order', () => {
    expect(regionsForKeys(['oil_change', 'timing_belt'])).toEqual(['hood']);
    expect(regionsForKeys(['brake_pads', 'oil_change'])).toEqual(['hood', 'frontAxle']);
  });
  it('ignores fact-only keys (a pure spec question lights nothing — no false hood glow)', () => {
    expect(regionsForKeys(['oil_capacity', 'oil_type'])).toEqual([]);
  });
  it('is empty for no keys', () => {
    expect(regionsForKeys([])).toEqual([]);
  });
});

describe('regionForFrame — which car region an agent frame pulses (best-effort, service-type keys only)', () => {
  it('maps a focusView frame carrying a service-type key to that region', () => {
    const frame: MercureFrame = { type: 'focusView', panel: 'tasks', keys: ['brake_pads'] };
    expect(regionForFrame(frame)).toBe('frontAxle');
  });
  it('takes the first known region when several keys are present (head-to-tail order)', () => {
    const frame: MercureFrame = { type: 'focusView', panel: 'tasks', keys: ['brake_pads', 'oil_change'] };
    expect(regionForFrame(frame)).toBe('hood'); // hood precedes frontAxle in BODY_REGIONS order
  });
  it('does not pulse for a spec question (fact-only keys map to no region)', () => {
    expect(regionForFrame({ type: 'showFact', keys: ['oil_capacity', 'oil_type'] })).toBeNull();
  });
  it('does not pulse for a focusView with no keys', () => {
    expect(regionForFrame({ type: 'focusView', panel: 'tasks' })).toBeNull();
  });
  it('ignores frames that carry no service-type keys', () => {
    expect(regionForFrame({ type: 'status', message: "J'enregistre l'intervention…" })).toBeNull();
    expect(regionForFrame({ type: 'turn', phase: 'start' })).toBeNull();
    expect(regionForFrame({ type: 'toast', tone: 'info', message: 'ok' })).toBeNull();
  });
  it('ignores domain events (a garage write is not a targeted pulse)', () => {
    const frame = { kind: 'vehicle', event: 'updated', vehicle: 'x', iri: '/v/1', resource: { '@id': '/v/1' } } as unknown as MercureFrame;
    expect(regionForFrame(frame)).toBeNull();
  });
});

// type-level guard: BODY_REGIONS enumerates exactly the BodyRegion union
const _exhaustive: Record<BodyRegion, true> = { hood: true, windshield: true, frontAxle: true, rearAxle: true, bay: true };
describe('BODY_REGIONS', () => {
  it('enumerates every region key', () => {
    expect(new Set(BODY_REGIONS)).toEqual(new Set(Object.keys(_exhaustive)));
  });
});
