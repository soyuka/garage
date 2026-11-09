export type Point = [number, number, number];

export interface StrokeSlot {
  /** Fraction of the whole draw at which the stroke starts. */
  start: number;
  /** Fraction of the whole draw the stroke takes to complete. */
  span: number;
}

// Float32 edge buffers carry the same vertex with noise in the last bits; welding at 1e-4 of a unit
// (sub-millimetre on a 4.4-unit car, a hundredth of a part) joins them without merging distinct corners.
const WELD = 1e-4;

function key(x: number, y: number, z: number): string {
  return `${Math.round(x / WELD)},${Math.round(y / WELD)},${Math.round(z / WELD)}`;
}

/** Line segments (6 floats each) walked through their shared vertices into continuous polylines. */
export function chainSegments(positions: Float32Array): Point[][] {
  const count = Math.floor(positions.length / 6);
  const ends: Array<[string, string]> = [];
  const at = new Map<string, number[]>();
  const point = (s: number, e: 0 | 1): Point => {
    const o = s * 6 + e * 3;
    return [positions[o], positions[o + 1], positions[o + 2]];
  };
  for (let s = 0; s < count; s++) {
    const a = key(...point(s, 0));
    const b = key(...point(s, 1));
    ends.push([a, b]);
    for (const k of [a, b]) {
      const list = at.get(k);
      if (list) list.push(s);
      else at.set(k, [s]);
    }
  }
  const used = new Uint8Array(count);
  const next = (k: string): number => (at.get(k) ?? []).find((s) => !used[s]) ?? -1;
  const lines: Point[][] = [];
  for (let s = 0; s < count; s++) {
    if (used[s]) continue;
    used[s] = 1;
    const line: Point[] = [point(s, 0), point(s, 1)];
    let tail = ends[s][1];
    for (let n = next(tail); n >= 0; n = next(tail)) {
      used[n] = 1;
      const forward = ends[n][0] === tail;
      line.push(point(n, forward ? 1 : 0));
      tail = forward ? ends[n][1] : ends[n][0];
    }
    let head = ends[s][0];
    for (let n = next(head); n >= 0; n = next(head)) {
      used[n] = 1;
      const forward = ends[n][1] === head;
      line.unshift(point(n, forward ? 0 : 1));
      head = forward ? ends[n][0] : ends[n][1];
    }
    lines.push(line);
  }
  return lines;
}

/** Distance travelled along the polyline at each point, normalised to 0…1. */
export function arcFractions(points: Point[]): number[] {
  const acc = [0];
  for (let i = 1; i < points.length; i++) {
    const [ax, ay, az] = points[i - 1];
    const [bx, by, bz] = points[i];
    acc.push(acc[i - 1] + Math.hypot(bx - ax, by - ay, bz - az));
  }
  const total = acc[acc.length - 1];
  return total > 0 ? acc.map((d) => d / total) : acc.map(() => 0);
}

/**
 * Staggered start per stroke: ordered outward from `origin` (by the stroke's mean point), else bottom to
 * top, each stroke drawing over `span` of the whole window and the last one finishing at 1.
 */
export function scheduleStrokes(strokes: Point[][], origin: Point | null, span: number): StrokeSlot[] {
  if (strokes.length <= 1) return strokes.map(() => ({ start: 0, span: 1 }));
  const keys = strokes.map((pts) => {
    const n = pts.length;
    const m = pts.reduce((acc, p) => [acc[0] + p[0] / n, acc[1] + p[1] / n, acc[2] + p[2] / n], [0, 0, 0]);
    return origin ? Math.hypot(m[0] - origin[0], m[1] - origin[1], m[2] - origin[2]) : m[1];
  });
  const order = strokes.map((_, i) => i).sort((a, b) => keys[a] - keys[b] || a - b);
  const slots: StrokeSlot[] = new Array(strokes.length);
  order.forEach((s, rank) => (slots[s] = { start: (rank / (strokes.length - 1)) * (1 - span), span }));
  return slots;
}

/** How far along its own window a stroke is at overall progress `p`. */
export function strokeReveal(p: number, start: number, span: number): number {
  if (span <= 0) return p >= start ? 1 : 0;
  return Math.min(1, Math.max(0, (p - start) / span));
}

export interface StrokeBuffers {
  position: Float32Array;
  along: Float32Array;
  start: Float32Array;
  span: Float32Array;
}

/** Strokes flattened back into line-segment pairs, each vertex carrying its arc fraction and its stroke's slot. */
export function strokeBuffers(strokes: Point[][], slots: StrokeSlot[]): StrokeBuffers {
  const segs = strokes.reduce((n, s) => n + Math.max(0, s.length - 1), 0);
  const position = new Float32Array(segs * 6);
  const along = new Float32Array(segs * 2);
  const start = new Float32Array(segs * 2);
  const span = new Float32Array(segs * 2);
  let v = 0;
  strokes.forEach((pts, i) => {
    const t = arcFractions(pts);
    for (let k = 1; k < pts.length; k++) {
      for (const j of [k - 1, k]) {
        position.set(pts[j], v * 3);
        along[v] = t[j];
        start[v] = slots[i].start;
        span[v] = slots[i].span;
        v++;
      }
    }
  });
  return { position, along, start, span };
}

/** Strokes at least `minLength` long: the rest is hatching (wheel spokes, tread, grilles) that only adds noise. */
export function pruneStrokes(strokes: Point[][], minLength: number): Point[][] {
  return strokes.filter((pts) => {
    let length = 0;
    for (let i = 1; i < pts.length && length < minLength; i++) {
      length += Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1], pts[i][2] - pts[i - 1][2]);
    }
    return length >= minLength;
  });
}

export interface HullPlane {
  /** Outward unit normal. */
  n: Point;
  /** n·p + c is the signed distance to the plane, negative inside. */
  c: number;
}

/** How deep a point sits inside a convex hull (negative when outside). */
export function insideDepth(p: Point, planes: HullPlane[]): number {
  let depth = Number.POSITIVE_INFINITY;
  for (const { n, c } of planes) depth = Math.min(depth, -(n[0] * p[0] + n[1] * p[1] + n[2] * p[2] + c));
  return depth;
}

/**
 * Strokes whose midpoint sits deeper than `threshold` inside the body's hull: seats and dashboards seen
 * through window openings. Shell lines lie on or just under the hull (mirrors and arches widen it a little).
 */
export function dropInterior(strokes: Point[][], planes: HullPlane[], threshold: number): Point[][] {
  if (planes.length === 0) return strokes;
  return strokes.filter((pts) => {
    const mid = pts[Math.floor(pts.length / 2)];
    const a = pts[Math.floor((pts.length - 1) / 2)];
    const m: Point = [(mid[0] + a[0]) / 2, (mid[1] + a[1]) / 2, (mid[2] + a[2]) / 2];
    return insideDepth(m, planes) <= threshold;
  });
}

export interface Bounds {
  min: Point;
  max: Point;
}

function strokeLength(pts: Point[]): number {
  let length = 0;
  for (let i = 1; i < pts.length; i++) length += Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1], pts[i][2] - pts[i - 1][2]);
  return length;
}

/**
 * A stricter minimum length inside some regions: in a wheel the spokes and tread are short strokes,
 * the tyre and rim outlines long ones, so only the outlines survive.
 */
export function pruneWithin(strokes: Point[][], regions: Bounds[], minLength: number): Point[][] {
  if (regions.length === 0) return strokes;
  return strokes.filter((pts) => {
    const n = pts.length;
    const m: Point = [0, 0, 0];
    for (const p of pts) for (let a = 0; a < 3; a++) m[a] += p[a] / n;
    const inside = regions.some((r) => m.every((v, a) => v >= r.min[a] && v <= r.max[a]));
    return !inside || strokeLength(pts) >= minLength;
  });
}

/** Fill triangles (9 floats each) whose centroid lies deeper than `threshold` inside the hull are dropped. */
export function dropInteriorTriangles(fill: Float32Array, planes: HullPlane[], threshold: number): Float32Array {
  if (planes.length === 0) return fill;
  const keep: number[] = [];
  for (let t = 0; t + 8 < fill.length; t += 9) {
    const c: Point = [(fill[t] + fill[t + 3] + fill[t + 6]) / 3, (fill[t + 1] + fill[t + 4] + fill[t + 7]) / 3, (fill[t + 2] + fill[t + 5] + fill[t + 8]) / 3];
    if (insideDepth(c, planes) <= threshold) keep.push(t);
  }
  const out = new Float32Array(keep.length * 9);
  keep.forEach((t, i) => out.set(fill.subarray(t, t + 9), i * 9));
  return out;
}
