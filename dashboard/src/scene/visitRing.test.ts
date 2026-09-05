import { describe, it, expect } from 'vitest';
import { visitRingLayout, RING_RADIUS, FLOOR_HEIGHT, MAX_HEIGHT } from './visitRing';
import { HOME, toSpherical } from './cameraRig';
import type { MaintenanceRecord } from '../types';

function record(id: string, performedAt: string, cents: number | null = null): MaintenanceRecord {
  return { id, items: [], performedAt, costCents: cents, source: 'user' };
}

function homeAzimuth(): number {
  return toSpherical([HOME.position[0] - HOME.target[0], HOME.position[1] - HOME.target[1], HOME.position[2] - HOME.target[2]]).azimuth;
}

describe('visitRingLayout — empty and single visit', () => {
  it('returns [] for no records', () => {
    expect(visitRingLayout([])).toEqual([]);
  });

  it('places a single visit exactly at the camera azimuth, at the floor height (no cost)', () => {
    const [slab] = visitRingLayout([record('a', '2026-01-01')]);
    expect(slab.angle).toBeCloseTo(homeAzimuth());
    expect(slab.height).toBe(FLOOR_HEIGHT);
    expect(slab.hasCost).toBe(false);
    expect(slab.costCents).toBeNull();
    expect(slab.position[0]).toBeCloseTo(RING_RADIUS * Math.sin(homeAzimuth()));
    expect(slab.position[1]).toBeCloseTo(0.02);
    expect(slab.position[2]).toBeCloseTo(RING_RADIUS * Math.cos(homeAzimuth()));
  });
});

describe('visitRingLayout — ordering', () => {
  it('sorts oldest→newest and lands the newest nearest the camera azimuth', () => {
    const records = [record('b', '2026-03-01'), record('a', '2026-01-01'), record('c', '2026-02-01')];
    const slabs = visitRingLayout(records);
    expect(slabs.map((s) => s.id)).toEqual(['a', 'c', 'b']);
    const az = homeAzimuth();
    // newest ('b') sits at az + span/2 (nearest the camera), oldest ('a') at az - span/2.
    expect(slabs[2].angle).toBeGreaterThan(az);
    expect(slabs[0].angle).toBeLessThan(az);
    expect(slabs[2].angle - az).toBeCloseTo(az - slabs[0].angle);
  });

  it('is stable on tied performedAt, ordering by id', () => {
    const records = [record('z', '2026-01-01'), record('a', '2026-01-01')];
    const slabs = visitRingLayout(records);
    expect(slabs.map((s) => s.id)).toEqual(['a', 'z']);
  });
});

describe('visitRingLayout — height normalisation', () => {
  it('normalises cost to [FLOOR_HEIGHT, MAX_HEIGHT] against the dearest visit', () => {
    const records = [record('a', '2026-01-01', 10_000), record('b', '2026-02-01', 20_000), record('c', '2026-03-01', 5_000)];
    const slabs = visitRingLayout(records);
    const byId = Object.fromEntries(slabs.map((s) => [s.id, s]));
    expect(byId.b.height).toBeCloseTo(MAX_HEIGHT);
    expect(byId.a.height).toBeCloseTo(FLOOR_HEIGHT + 0.5 * (MAX_HEIGHT - FLOOR_HEIGHT));
    expect(byId.c.height).toBeCloseTo(FLOOR_HEIGHT + 0.25 * (MAX_HEIGHT - FLOOR_HEIGHT));
    for (const s of slabs) expect(s.hasCost).toBe(true);
  });

  it('a visit with no cost at all is wire-only at the floor, even among costed visits', () => {
    const records = [record('a', '2026-01-01', 10_000), record('b', '2026-02-01', null)];
    const slabs = visitRingLayout(records);
    const b = slabs.find((s) => s.id === 'b')!;
    expect(b.height).toBe(FLOOR_HEIGHT);
    expect(b.hasCost).toBe(false);
  });

  it('prefers effectiveCostCents over costCents', () => {
    const records: MaintenanceRecord[] = [
      { id: 'a', items: [], performedAt: '2026-01-01', costCents: 1000, effectiveCostCents: 4000, source: 'user' },
    ];
    expect(visitRingLayout(records)[0].costCents).toBe(4000);
  });
});

describe('visitRingLayout — angular step clamp', () => {
  it('clamps the per-slab step so 20 visits still fit within maxSpan', () => {
    const records = Array.from({ length: 20 }, (_, i) => record(`v${i}`, `2026-01-${String(i + 1).padStart(2, '0')}`));
    const slabs = visitRingLayout(records);
    const span = slabs[slabs.length - 1].angle - slabs[0].angle;
    expect(span).toBeLessThanOrEqual((200 * Math.PI) / 180 + 1e-9);
    // 19 steps over the full span at the 14° cap would exceed 200° (266°), so the cap must have kicked in.
    const step = slabs[1].angle - slabs[0].angle;
    expect(step).toBeLessThanOrEqual((14 * Math.PI) / 180 + 1e-9);
  });

  it('caps the per-step angle at 14° for a small count that would otherwise exceed it', () => {
    const records = [record('a', '2026-01-01'), record('b', '2026-01-02')];
    const slabs = visitRingLayout(records, { maxSpan: (200 * Math.PI) / 180, maxStep: (14 * Math.PI) / 180 });
    const step = slabs[1].angle - slabs[0].angle;
    expect(step).toBeCloseTo((14 * Math.PI) / 180);
  });
});
