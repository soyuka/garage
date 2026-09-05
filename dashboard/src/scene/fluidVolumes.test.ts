import { describe, it, expect } from 'vitest';
import { fluidVolumesLayout, RADIUS, LITRE_HEIGHT, H_MAX, FLOOR_HEIGHT, ROW_RADIUS, GAP } from './fluidVolumes';
import { HOME, toSpherical } from './cameraRig';
import type { SpecNode } from '../types';

function node(key: string, volumeLitres?: number | null): SpecNode {
  return { key, label: key, value: '', verified: true, volumeLitres };
}

function homeAzimuth(): number {
  return toSpherical([HOME.position[0] - HOME.target[0], HOME.position[1] - HOME.target[1], HOME.position[2] - HOME.target[2]]).azimuth;
}

describe('fluidVolumesLayout — empty and skipped nodes', () => {
  it('returns [] for no nodes', () => {
    expect(fluidVolumesLayout([])).toEqual([]);
  });

  it('drops an info-only node (volumeLitres undefined) entirely — no body at all', () => {
    expect(fluidVolumesLayout([node('grade', undefined)])).toEqual([]);
  });

  it('keeps a mass node (volumeLitres null) but a confirmed-volume node too, in order', () => {
    const cyls = fluidVolumesLayout([node('grade', undefined), node('mass', null), node('vol', 5)]);
    expect(cyls.map((c) => c.key)).toEqual(['mass', 'vol']);
  });
});

describe('fluidVolumesLayout — height and radius', () => {
  it('sets height = litres × LITRE_HEIGHT for a confirmed volume below the cap', () => {
    const [c] = fluidVolumesLayout([node('oil', 4.3)]);
    expect(c.height).toBeCloseTo(4.3 * LITRE_HEIGHT);
    expect(c.radius).toBeCloseTo(RADIUS);
    expect(c.hasVolume).toBe(true);
    expect(c.litres).toBe(4.3);
  });

  it('an ml volume already converted upstream just reads its litres value straight', () => {
    const [c] = fluidVolumesLayout([node('washer', 0.25)]);
    expect(c.height).toBeCloseTo(0.25 * LITRE_HEIGHT);
    expect(c.radius).toBeCloseTo(RADIUS);
  });

  it('a mass node stands wire-only at FLOOR_HEIGHT/RADIUS, hasVolume:false', () => {
    const [c] = fluidVolumesLayout([node('ac', null)]);
    expect(c.height).toBe(FLOOR_HEIGHT);
    expect(c.radius).toBe(RADIUS);
    expect(c.hasVolume).toBe(false);
    expect(c.litres).toBeNull();
  });

  it('a 64 L tank caps at H_MAX and grows its radius to conserve volume', () => {
    const [c] = fluidVolumesLayout([node('tank', 64)]);
    expect(c.height).toBeCloseTo(H_MAX);
    expect(c.radius).toBeCloseTo(RADIUS * Math.sqrt((64 * LITRE_HEIGHT) / H_MAX));
  });

  it('a 0.8 L reservoir stays well under the cap — height 0.112, radius unchanged', () => {
    const [c] = fluidVolumesLayout([node('steering', 0.8)]);
    expect(c.height).toBeCloseTo(0.112);
    expect(c.radius).toBeCloseTo(0.15);
  });

  it('conserves π r² h proportional to litres whether or not the cap bites', () => {
    const [tank, reservoir] = fluidVolumesLayout([node('tank', 64), node('steering', 0.8)]);
    const volumeOf = (c: { radius: number; height: number }) => c.radius * c.radius * c.height;
    expect(volumeOf(tank) / volumeOf(reservoir)).toBeCloseTo(64 / 0.8);
  });
});

describe('fluidVolumesLayout — row placement', () => {
  it('centres a single node at radius ROW_RADIUS on the home azimuth', () => {
    const [c] = fluidVolumesLayout([node('oil', 4.3)]);
    const az = homeAzimuth();
    expect(c.position[0]).toBeCloseTo(ROW_RADIUS * Math.sin(az));
    expect(c.position[1]).toBeCloseTo(0.02);
    expect(c.position[2]).toBeCloseTo(ROW_RADIUS * Math.cos(az));
  });

  it('places uncapped (equal-radius) nodes GAP+2×RADIUS apart, centred, at azimuth 0', () => {
    const cyls = fluidVolumesLayout([node('a', 1), node('b', 2), node('c', 3)], { azimuth: 0 });
    // right vector at az=0 is [1,0,0]: offsets land purely on x, z stays at ROW_RADIUS.
    const step = GAP + 2 * RADIUS;
    const [a, b, c0] = cyls;
    expect(a.position[0]).toBeCloseTo(-step);
    expect(b.position[0]).toBeCloseTo(0);
    expect(c0.position[0]).toBeCloseTo(step);
    for (const c of cyls) {
      expect(c.position[2]).toBeCloseTo(ROW_RADIUS);
      expect(c.position[1]).toBeCloseTo(0.02);
    }
  });

  it('centres a mixed-radii row: left edge of the first at -total/2, right edge of the last at +total/2', () => {
    const cyls = fluidVolumesLayout([node('tank', 64), node('steering', 0.8)], { azimuth: 0 });
    const [tank, steering] = cyls;
    const totalWidth = 2 * tank.radius + 2 * steering.radius + GAP;
    expect(tank.position[0] - tank.radius).toBeCloseTo(-totalWidth / 2);
    expect(steering.position[0] + steering.radius).toBeCloseTo(totalWidth / 2);
    // and they actually touch with exactly GAP between edges
    expect(steering.position[0] - steering.radius - (tank.position[0] + tank.radius)).toBeCloseTo(GAP);
  });

  it('indexes in row order, 0-based', () => {
    const cyls = fluidVolumesLayout([node('a', 1), node('b', null), node('c', 2)]);
    expect(cyls.map((c) => c.index)).toEqual([0, 1, 2]);
  });

  it('honours an explicit row radius (distance from the car)', () => {
    const [c] = fluidVolumesLayout([node('oil', 4.3)], { azimuth: 0, radius: 1 });
    expect(c.position[2]).toBeCloseTo(1);
  });
});

// RADIUS is the cylinder's own (uncapped) radius, asserted here so the constant stays pinned to spec.
describe('fluidVolumes constants', () => {
  it('matches the spec', () => {
    expect(RADIUS).toBe(0.15);
    expect(LITRE_HEIGHT).toBe(0.14);
    expect(H_MAX).toBe(1.4);
    expect(FLOOR_HEIGHT).toBe(0.12);
    expect(ROW_RADIUS).toBe(2.3);
    expect(GAP).toBe(0.12);
  });
});
