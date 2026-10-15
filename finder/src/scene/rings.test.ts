import { completeRings, type Seg } from './rings';

const at = (r: number, y: number, a: number): [number, number, number] => [r * Math.cos(a), y, r * Math.sin(a)];

function dashedRing(r: number, y: number, n: number): Seg[] {
  const out: Seg[] = [];
  for (let i = 0; i < n; i += 2) out.push([at(r, y, (i / n) * 2 * Math.PI), at(r, y, ((i + 1) / n) * 2 * Math.PI)]);
  return out;
}

describe('completeRings', () => {
  it('closes a dashed horizontal crease into a full circle of the same radius and height', () => {
    const vertical: Seg = [[30, 0, 0], [30, 20, 0]];
    const out = completeRings([...dashedRing(30, 10, 48), vertical], 64);
    expect(out).toContainEqual(vertical);
    const ring = out.filter((s) => s !== vertical);
    expect(ring).toHaveLength(64);
    for (const [a, b] of ring) {
      expect(a[1]).toBeCloseTo(10, 5);
      expect(Math.hypot(a[0], a[2])).toBeCloseTo(30, 5);
      expect(Math.hypot(b[0], b[2])).toBeCloseTo(30, 5);
    }
  });

  it('leaves a short arc alone: too little of the circle to be a ring', () => {
    const arc = dashedRing(30, 10, 48).slice(0, 3);
    expect(completeRings(arc, 64)).toEqual(arc);
  });

  it('keeps two rings at different heights apart', () => {
    const out = completeRings([...dashedRing(30, 10, 48), ...dashedRing(28, 2, 48)], 32);
    expect(out).toHaveLength(64);
    expect(new Set(out.map(([a]) => a[1].toFixed(3)))).toEqual(new Set(['10.000', '2.000']));
  });
});
