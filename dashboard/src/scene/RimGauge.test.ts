import { describe, it, expect } from 'vitest';
import { arcLayout } from './RimGauge';

describe('arcLayout — N equal rim slices with an angular gap between them', () => {
  it('returns one arc per tracked system', () => {
    expect(arcLayout(1)).toHaveLength(1);
    expect(arcLayout(3)).toHaveLength(3);
    expect(arcLayout(8)).toHaveLength(8);
  });

  it('returns no arcs for an empty forecast (caller renders the neutral ring instead)', () => {
    expect(arcLayout(0)).toEqual([]);
  });

  it('treats a non-positive count as empty, never throws', () => {
    expect(arcLayout(-2)).toEqual([]);
  });

  it('spaces slice starts evenly around the full circle', () => {
    const arcs = arcLayout(4);
    const slice = (Math.PI * 2) / 4;
    arcs.forEach((a, i) => {
      expect(a.thetaStart).toBeCloseTo(i * slice, 10);
    });
  });

  it('leaves a gap: each arc is shorter than its slice', () => {
    for (const n of [1, 2, 5, 12]) {
      const slice = (Math.PI * 2) / n;
      for (const a of arcLayout(n)) {
        expect(a.thetaLength).toBeLessThan(slice);
        expect(a.thetaLength).toBeGreaterThan(0);
      }
    }
  });

  it('uses the same fraction of each slice (88%) so gaps read evenly', () => {
    const arcs = arcLayout(6);
    const slice = (Math.PI * 2) / 6;
    for (const a of arcs) {
      expect(a.thetaLength).toBeCloseTo(slice * 0.88, 10);
    }
  });

  it('a single system fills (almost) the whole rim', () => {
    const [only] = arcLayout(1);
    expect(only.thetaStart).toBe(0);
    expect(only.thetaLength).toBeCloseTo(Math.PI * 2 * 0.88, 10);
  });
});
