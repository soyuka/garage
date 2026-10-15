export type Point = [number, number, number];
export type Seg = [Point, Point];

const Y_TOL = 0.25;
const R_TOL = 0.4;
const MIN_COVERAGE = 0.3;

interface Bucket {
  segs: Seg[];
  y: number;
  r: number;
  angle: number;
}

const radius = (p: Point): number => Math.hypot(p[0], p[2]);

// A crease mesh of a body of revolution (axis = Y through the origin) often marks a fillet boundary on
// only every other facet, which draws as a dashed ring. Horizontal crease segments that share a height
// and radius and cover enough of the circumference are replaced by one full circle.
export function completeRings(segments: readonly Seg[], resolution = 96): Seg[] {
  const kept: Seg[] = [];
  const buckets: Bucket[] = [];
  for (const s of segments) {
    const [a, b] = s;
    const ra = radius(a);
    const rb = radius(b);
    if (Math.abs(a[1] - b[1]) > Y_TOL || Math.abs(ra - rb) > R_TOL || ra < R_TOL) {
      kept.push(s);
      continue;
    }
    const y = (a[1] + b[1]) / 2;
    const r = (ra + rb) / 2;
    const span = Math.abs(Math.atan2(a[0] * b[2] - a[2] * b[0], a[0] * b[0] + a[2] * b[2]));
    const bucket = buckets.find((k) => Math.abs(k.y - y) <= Y_TOL && Math.abs(k.r - r) <= R_TOL);
    if (bucket) {
      bucket.segs.push(s);
      bucket.angle += span;
    } else buckets.push({ segs: [s], y, r, angle: span });
  }
  const out = [...kept];
  for (const k of buckets) {
    if (k.angle < MIN_COVERAGE * 2 * Math.PI) {
      out.push(...k.segs);
      continue;
    }
    for (let i = 0; i < resolution; i++) {
      const t0 = (i / resolution) * 2 * Math.PI;
      const t1 = ((i + 1) / resolution) * 2 * Math.PI;
      out.push([
        [k.r * Math.cos(t0), k.y, k.r * Math.sin(t0)],
        [k.r * Math.cos(t1), k.y, k.r * Math.sin(t1)],
      ]);
    }
  }
  return out;
}
