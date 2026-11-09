import { describe, expect, it } from 'vitest';
import { drawProgress, fitBoxDistance, fitCentered, fitDistance, fitPointsDistance, fitTransform, focusMask, orderSegments, staggerDelay, drawKey, markDrawn, shouldDraw } from './edgeDraw';

describe('drawProgress', () => {
  it('eases out exponentially from 0 to exactly 1', () => {
    expect(drawProgress(0, 800)).toBe(0);
    expect(drawProgress(800, 800)).toBe(1);
    expect(drawProgress(2000, 800)).toBe(1);
    const half = drawProgress(400, 800);
    expect(half).toBeGreaterThan(0.9);
    expect(half).toBeLessThan(1);
  });

  it('is monotonic', () => {
    let last = -1;
    for (let t = 0; t <= 800; t += 50) {
      const p = drawProgress(t, 800);
      expect(p).toBeGreaterThanOrEqual(last);
      last = p;
    }
  });

  it('waits out a delay at 0', () => {
    expect(drawProgress(100, 800, 200)).toBe(0);
    expect(drawProgress(1000, 800, 200)).toBe(1);
  });

  it('is instant when reduced motion asks for it', () => {
    expect(drawProgress(0, 0)).toBe(1);
  });
});

describe('staggerDelay', () => {
  it('staggers lightly and caps', () => {
    expect(staggerDelay(0)).toBe(0);
    expect(staggerDelay(1)).toBe(90);
    expect(staggerDelay(20)).toBe(450);
  });
});

// Two segments: one high (y = 5), one low (y = 1).
const SEGS = new Float32Array([0, 5, 0, 1, 5, 0, 0, 1, 0, 1, 1, 0]);

describe('orderSegments', () => {
  it('draws bottom to top when no origin is given', () => {
    expect(Array.from(orderSegments(SEGS, null))).toEqual([0, 1, 0, 1, 1, 0, 0, 5, 0, 1, 5, 0]);
  });

  it('draws outward from an origin', () => {
    expect(Array.from(orderSegments(SEGS, [0.5, 5, 0]))).toEqual([0, 5, 0, 1, 5, 0, 0, 1, 0, 1, 1, 0]);
  });

  it('keeps every segment intact', () => {
    expect(orderSegments(SEGS, null).length).toBe(SEGS.length);
  });
});

describe('fitTransform', () => {
  const box = { min: [2, 1, -1] as [number, number, number], max: [6, 3, 1] as [number, number, number] };

  it('floors a vehicle and scales its length to the target, centred on x/z', () => {
    expect(fitTransform(box, { mode: 'floor', size: 4.4 })).toEqual({ scale: 1.1, offset: [-4, -1, 0] });
  });

  it('centres a part on all axes and scales its largest side to the target', () => {
    expect(fitTransform(box, { mode: 'centre', size: 1 })).toEqual({ scale: 0.25, offset: [-4, -2, 0] });
  });
});

describe('focusMask', () => {
  it('marks the segments whose midpoint sits inside the focus sphere', () => {
    expect(Array.from(focusMask(SEGS, { point: [0.5, 1, 0], radius: 1 }))).toEqual([0, 1]);
  });

  it('marks nothing without a focus', () => {
    expect(Array.from(focusMask(SEGS, null))).toEqual([0, 0]);
  });
});

describe('fitDistance', () => {
  it('backs off until the bounding sphere fits the tighter field of view', () => {
    expect(fitDistance({ length: 4, height: 0, width: 0 }, 90, 1, 1)).toBeCloseTo(2 / Math.sin(Math.PI / 4), 6);
  });

  it('needs more distance in a narrow frame than in a wide one', () => {
    const box = { length: 4.4, height: 1.7, width: 1.8 };
    expect(fitDistance(box, 24, 0.8, 1.1)).toBeGreaterThan(fitDistance(box, 24, 2.3, 1.1));
  });

  it('scales with the margin', () => {
    const box = { length: 4.4, height: 1.7, width: 1.8 };
    expect(fitDistance(box, 24, 2, 1.2)).toBeCloseTo(fitDistance(box, 24, 2, 1) * 1.2, 6);
  });
});

describe('fitBoxDistance', () => {
  it('fits a box seen head-on exactly to the tighter field', () => {
    // A 2×2 face at z = ±0.5 seen along +z with a 90° vertical field: the near face needs d − 0.5 = 1.
    expect(fitBoxDistance({ length: 2, height: 2, width: 1 }, [0, 0, 1], 90, 1, 1)).toBeCloseTo(1.5, 6);
  });

  it('frames a wide car closer in a wide frame than the bounding sphere would', () => {
    const box = { length: 4.4, height: 1.7, width: 1.8 };
    const dir: [number, number, number] = [-0.62, 0.25, 0.74];
    expect(fitBoxDistance(box, dir, 24, 2.3, 1)).toBeLessThan(fitDistance(box, 24, 2.3, 1));
  });

  it('never lets a corner leave the frame', () => {
    const box = { length: 4.4, height: 1.7, width: 1.8 };
    const dir: [number, number, number] = [-0.62, 0.25, 0.74];
    expect(fitBoxDistance(box, dir, 24, 0.8, 1)).toBeGreaterThan(fitBoxDistance(box, dir, 24, 2.3, 1));
  });
});

describe('fitPointsDistance', () => {
  const box = { length: 4.4, height: 1.7, width: 1.8 };
  const dir: [number, number, number] = [-0.62, 0.25, 0.74];
  const corners = new Float32Array(
    [-1, 1].flatMap((sx) => [-1, 1].flatMap((sy) => [-1, 1].flatMap((sz) => [(sx * box.length) / 2, (sy * box.height) / 2, (sz * box.width) / 2]))),
  );

  it('equals the box fit when the points are the box corners', () => {
    expect(fitPointsDistance(corners, [0, 0, 0], dir, 24, 2.3, 1)).toBeCloseTo(fitBoxDistance(box, dir, 24, 2.3, 1), 6);
  });

  it('frames a rounded silhouette closer than its bounding box', () => {
    const rounded = corners.map((v) => v * 0.8);
    expect(fitPointsDistance(rounded, [0, 0, 0], dir, 24, 2.3, 1)).toBeLessThan(fitBoxDistance(box, dir, 24, 2.3, 1));
  });
});

describe('fitCentered', () => {
  const dir: [number, number, number] = [-0.62, 0.25, 0.74];
  // An off-centre, lopsided cloud: a long low box with a tall stub at one end.
  const pts = new Float32Array([
    ...[-1, 1].flatMap((sx) => [0, 1.2].flatMap((y) => [-1, 1].flatMap((sz) => [sx * 2.2 + 0.5, y, sz * 0.9]))),
    2.4, 1.9, 0.4,
  ]);

  function project(target: [number, number, number], distance: number, aspect: number) {
    const n = Math.hypot(...dir);
    const z = dir.map((v) => v / n);
    const xr = [z[2], 0, -z[0]];
    const xn = Math.hypot(...xr);
    const x = xr.map((v) => v / xn);
    const y = [z[1] * x[2] - z[2] * x[1], z[2] * x[0] - z[0] * x[2], z[0] * x[1] - z[1] * x[0]];
    const cam = target.map((v, i) => v + z[i] * distance);
    const tanV = Math.tan((24 / 2) * (Math.PI / 180));
    const out: Array<[number, number]> = [];
    for (let i = 0; i < pts.length; i += 3) {
      const r = [pts[i] - cam[0], pts[i + 1] - cam[1], pts[i + 2] - cam[2]];
      const depth = -(r[0] * z[0] + r[1] * z[1] + r[2] * z[2]);
      out.push([(r[0] * x[0] + r[1] * x[1] + r[2] * x[2]) / (depth * tanV * aspect), (r[0] * y[0] + r[1] * y[1] + r[2] * y[2]) / (depth * tanV)]);
    }
    return out;
  }

  for (const aspect of [0.7, 1.4, 2.3]) {
    it(`centres the projected silhouette and keeps the margin at aspect ${aspect}`, () => {
      const { target, distance } = fitCentered(pts, dir, 24, aspect, 1.2);
      const ndc = project(target, distance, aspect);
      const xs = ndc.map((p) => p[0]);
      const ys = ndc.map((p) => p[1]);
      expect((Math.max(...xs) + Math.min(...xs)) / 2).toBeCloseTo(0, 2);
      expect((Math.max(...ys) + Math.min(...ys)) / 2).toBeCloseTo(0, 2);
      expect(Math.max(...xs.map(Math.abs), ...ys.map(Math.abs))).toBeLessThanOrEqual(1 / 1.2 + 0.01);
    });
  }
});

describe('draw-on once per session', () => {
  it('draws a mesh the first time it appears, then shows it complete', () => {
    const key = drawKey('/car-a.glb', { mode: 'floor', size: 4.4 });
    expect(shouldDraw(key)).toBe(true);
    expect(shouldDraw(key)).toBe(true);
    markDrawn(key);
    expect(shouldDraw(key)).toBe(false);
  });

  it('keys on the mesh and its fit', () => {
    markDrawn(drawKey('/car-b.glb', { mode: 'floor', size: 4.4 }));
    expect(shouldDraw(drawKey('/car-b.glb', { mode: 'centre', size: 1 }))).toBe(true);
    expect(shouldDraw(drawKey('/car-c.glb', { mode: 'floor', size: 4.4 }))).toBe(true);
  });
});
