export type Vec3 = [number, number, number];

/**
 * How much of a mesh's edges are drawn, `elapsed` ms after it arrived: exponential ease-out over
 * `duration` after `delay`. A zero duration is the reduced-motion path: drawn at once.
 */
export function drawProgress(elapsed: number, duration: number, delay = 0): number {
  if (duration <= 0) return 1;
  const t = (elapsed - delay) / duration;
  if (t <= 0) return 0;
  if (t >= 1) return 1;
  return Math.min(1, (1 - 2 ** (-10 * t)) / (1 - 2 ** -10));
}

const STAGGER_MS = 90;
const STAGGER_CAP = 5;

/** The light stagger between parc thumbnails drawing on. */
export function staggerDelay(index: number): number {
  return Math.min(index, STAGGER_CAP) * STAGGER_MS;
}

/**
 * Line segments (6 floats each) reordered so a progressive draw range reveals them in a readable order:
 * outward from `origin` when one is given (the focused region), else bottom to top.
 */
export function orderSegments(positions: Float32Array, origin: Vec3 | null): Float32Array {
  const count = Math.floor(positions.length / 6);
  const keys = new Float64Array(count);
  for (let i = 0; i < count; i++) {
    const o = i * 6;
    const mx = (positions[o] + positions[o + 3]) / 2;
    const my = (positions[o + 1] + positions[o + 4]) / 2;
    const mz = (positions[o + 2] + positions[o + 5]) / 2;
    keys[i] = origin ? (mx - origin[0]) ** 2 + (my - origin[1]) ** 2 + (mz - origin[2]) ** 2 : my;
  }
  const order = Array.from({ length: count }, (_, i) => i).sort((a, b) => keys[a] - keys[b] || a - b);
  const out = new Float32Array(count * 6);
  order.forEach((src, dst) => out.set(positions.subarray(src * 6, src * 6 + 6), dst * 6));
  return out;
}

export interface Box {
  min: Vec3;
  max: Vec3;
}

/**
 * Scale + offset that put a mesh in its stage frame: a vehicle is centred on x/z, floored on y and
 * scaled to `size` along x (its length); a part is centred on every axis and its largest side scaled to
 * `size`. Applied as (p + offset) * scale.
 */
export function fitTransform(box: Box, fit: { mode: 'floor' | 'centre'; size: number }): { scale: number; offset: Vec3 } {
  const ext = [box.max[0] - box.min[0], box.max[1] - box.min[1], box.max[2] - box.min[2]];
  const centre = [(box.min[0] + box.max[0]) / 2, (box.min[1] + box.max[1]) / 2, (box.min[2] + box.max[2]) / 2];
  const span = fit.mode === 'floor' ? ext[0] : Math.max(...ext);
  const scale = fit.size / Math.max(1e-9, span);
  const offset: Vec3 = [-centre[0], fit.mode === 'floor' ? -box.min[1] : -centre[1], -centre[2]];
  return { scale: Math.round(scale * 1e9) / 1e9, offset: offset.map((v) => v + 0) as Vec3 };
}

/** 1 for each segment whose midpoint lies within `radius` of the focus point, else 0. */
export function focusMask(positions: Float32Array, focus: { point: Vec3; radius: number } | null): Uint8Array {
  const count = Math.floor(positions.length / 6);
  const out = new Uint8Array(count);
  if (!focus) return out;
  const r2 = focus.radius ** 2;
  for (let i = 0; i < count; i++) {
    const o = i * 6;
    const dx = (positions[o] + positions[o + 3]) / 2 - focus.point[0];
    const dy = (positions[o + 1] + positions[o + 4]) / 2 - focus.point[1];
    const dz = (positions[o + 2] + positions[o + 5]) / 2 - focus.point[2];
    out[i] = dx * dx + dy * dy + dz * dz <= r2 ? 1 : 0;
  }
  return out;
}

/**
 * Camera distance from the box centre that fits its bounding sphere inside the view, whichever of the
 * vertical or horizontal field is tighter for this `aspect`, times `margin`.
 */
export function fitDistance(box: { length: number; height: number; width: number }, fovDeg: number, aspect: number, margin: number): number {
  const radius = 0.5 * Math.hypot(box.length, box.height, box.width);
  const vertical = ((fovDeg / 2) * Math.PI) / 180;
  const horizontal = Math.atan(Math.tan(vertical) * aspect);
  return (radius * margin) / Math.sin(Math.min(vertical, horizontal));
}

/**
 * Exact distance along `dir` (from `center` towards the camera) at which every point sits inside the
 * view: each point bounds the distance by its lateral offset over the field's tangent.
 */
export function fitPointsDistance(points: Float32Array, center: Vec3, dir: Vec3, fovDeg: number, aspect: number, margin: number): number {
  const n = Math.hypot(...dir);
  const z: Vec3 = [dir[0] / n, dir[1] / n, dir[2] / n];
  // Camera basis as three's lookAt builds it with +y up.
  const xr: Vec3 = [z[2], 0, -z[0]];
  const xn = Math.hypot(...xr) || 1;
  const x: Vec3 = [xr[0] / xn, xr[1] / xn, xr[2] / xn];
  const y: Vec3 = [z[1] * x[2] - z[2] * x[1], z[2] * x[0] - z[0] * x[2], z[0] * x[1] - z[1] * x[0]];
  const tanV = Math.tan((fovDeg / 2) * (Math.PI / 180));
  const tanH = tanV * aspect;
  let d = 0;
  for (let i = 0; i + 2 < points.length; i += 3) {
    const px = points[i] - center[0];
    const py = points[i + 1] - center[1];
    const pz = points[i + 2] - center[2];
    const depth = px * z[0] + py * z[1] + pz * z[2];
    const lx = Math.abs(px * x[0] + py * x[1] + pz * x[2]);
    const ly = Math.abs(px * y[0] + py * y[1] + pz * y[2]);
    d = Math.max(d, depth + (lx * margin) / tanH, depth + (ly * margin) / tanV);
  }
  return d;
}

/** The same fit for a box centred on the target: its eight corners. */
export function fitBoxDistance(box: { length: number; height: number; width: number }, dir: Vec3, fovDeg: number, aspect: number, margin: number): number {
  const corners: number[] = [];
  for (const sx of [-1, 1]) for (const sy of [-1, 1]) for (const sz of [-1, 1]) corners.push((sx * box.length) / 2, (sy * box.height) / 2, (sz * box.width) / 2);
  return fitPointsDistance(new Float32Array(corners), [0, 0, 0], dir, fovDeg, aspect, margin);
}

/**
 * Camera target and distance that centre the points' projected silhouette in the frame, with every
 * point at most 1/margin from the centre on either axis. The target slides across the view plane until
 * the projected bounds are symmetric; the distance is refitted at each step.
 */
export function fitCentered(points: Float32Array, dir: Vec3, fovDeg: number, aspect: number, margin: number): { target: Vec3; distance: number } {
  const n = Math.hypot(...dir);
  const z: Vec3 = [dir[0] / n, dir[1] / n, dir[2] / n];
  const xr: Vec3 = [z[2], 0, -z[0]];
  const xn = Math.hypot(...xr) || 1;
  const x: Vec3 = [xr[0] / xn, xr[1] / xn, xr[2] / xn];
  const y: Vec3 = [z[1] * x[2] - z[2] * x[1], z[2] * x[0] - z[0] * x[2], z[0] * x[1] - z[1] * x[0]];
  const tanV = Math.tan((fovDeg / 2) * (Math.PI / 180));
  const tanH = tanV * aspect;

  const min: Vec3 = [Infinity, Infinity, Infinity];
  const max: Vec3 = [-Infinity, -Infinity, -Infinity];
  for (let i = 0; i + 2 < points.length; i += 3)
    for (let a = 0; a < 3; a++) {
      min[a] = Math.min(min[a], points[i + a]);
      max[a] = Math.max(max[a], points[i + a]);
    }
  const target: Vec3 = [(min[0] + max[0]) / 2, (min[1] + max[1]) / 2, (min[2] + max[2]) / 2];
  let distance = fitPointsDistance(points, target, dir, fovDeg, aspect, margin);

  for (let iter = 0; iter < 40; iter++) {
    const cam: Vec3 = [target[0] + z[0] * distance, target[1] + z[1] * distance, target[2] + z[2] * distance];
    let loX = Infinity;
    let hiX = -Infinity;
    let loY = Infinity;
    let hiY = -Infinity;
    for (let i = 0; i + 2 < points.length; i += 3) {
      const r: Vec3 = [points[i] - cam[0], points[i + 1] - cam[1], points[i + 2] - cam[2]];
      const depth = -(r[0] * z[0] + r[1] * z[1] + r[2] * z[2]);
      const nx = (r[0] * x[0] + r[1] * x[1] + r[2] * x[2]) / (depth * tanH);
      const ny = (r[0] * y[0] + r[1] * y[1] + r[2] * y[2]) / (depth * tanV);
      loX = Math.min(loX, nx);
      hiX = Math.max(hiX, nx);
      loY = Math.min(loY, ny);
      hiY = Math.max(hiY, ny);
    }
    const cx = (loX + hiX) / 2;
    const cy = (loY + hiY) / 2;
    if (Math.abs(cx) < 1e-4 && Math.abs(cy) < 1e-4) break;
    const sx = cx * distance * tanH;
    const sy = cy * distance * tanV;
    for (let a = 0; a < 3; a++) target[a] += x[a] * sx + y[a] * sy;
    distance = fitPointsDistance(points, target, dir, fovDeg, aspect, margin);
  }
  return { target, distance };
}

// A mesh draws itself on the first time it appears in the session; afterwards it is simply there.
const DRAWN = new Set<string>();

export function drawKey(url: string, fit: { mode: string; size: number }): string {
  return `${url}|${fit.mode}|${fit.size}`;
}

export function shouldDraw(key: string): boolean {
  return !DRAWN.has(key);
}

export function markDrawn(key: string): void {
  DRAWN.add(key);
}
