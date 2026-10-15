export type Vec3 = [number, number, number];

export interface Rect {
  x: number;
  y: number;
  w: number;
  h: number;
}

export type Face = 'top' | 'bottom';

export type CoteKey = 'diameter' | 'height' | 'thread' | 'seal';

export const COTE_PRIORITY: readonly CoteKey[] = ['diameter', 'height', 'thread', 'seal'];

export interface FilterCotes {
  diameter: { from: Vec3; to: Vec3; label: Vec3 };
  height: { extensions: Vec3[]; from: Vec3; to: Vec3; label: Vec3 };
  thread: { points: Vec3[]; label: Vec3 };
}

interface CoteOptions {
  offset?: number;
  gap?: number;
  overshoot?: number;
  lift?: number;
  faceY?: number;
  face?: Face;
}

const THREAD_RIM_ANGLE = Math.PI / 4;
const THREAD_KNEE = { out: 8, down: 14 };
const THREAD_SHELF = 18;

// In the camera-facing frame of a cylinder standing on its base at the origin (+Y the axis, +Z toward
// the camera, +X screen right): every point at |x| ≥ R projects outside the silhouette.
export function filterCotes(R: number, H: number, { offset = 12, gap = 1.5, overshoot = 2.5, lift = 3, faceY = H, face = 'top' }: CoteOptions = {}): FilterCotes {
  const x = R + offset;
  const rimX = -R * Math.sin(THREAD_RIM_ANGLE);
  const rimZ = R * Math.cos(THREAD_RIM_ANGLE);
  const knee: Vec3 = [-R - THREAD_KNEE.out, -THREAD_KNEE.down, rimZ];
  const end: Vec3 = [-R - THREAD_KNEE.out - THREAD_SHELF, -THREAD_KNEE.down, rimZ];
  return {
    diameter: { from: [-R, faceY, 0], to: [R, faceY, 0], label: [0, face === 'top' ? faceY + lift : faceY - lift, 0] },
    height: {
      extensions: [
        [R + gap, 0, 0],
        [x + overshoot, 0, 0],
        [R + gap, H, 0],
        [x + overshoot, H, 0],
      ],
      from: [x, 0, 0],
      to: [x, H, 0],
      label: [x, H / 2, 0],
    },
    thread: { points: [[rimX, 0, rimZ], knee, end], label: end },
  };
}

const FULL_RADIUS = 0.93;

// Highest point of an axisymmetric profile ([radius, y] pairs) still at full radius: on a domed can
// the top face's visible rim sits there, below the dome's crown.
export function rimTop(profile: readonly (readonly [number, number])[]): number {
  const maxR = Math.max(...profile.map(([r]) => r));
  return Math.max(...profile.filter(([r]) => r >= maxR * FULL_RADIUS).map(([, y]) => y));
}

// Arrowhead half-width direction: across the line and across the view, so the head always shows its face.
export function arrowSide(back: readonly number[], view: readonly number[]): Vec3 {
  const [bx, by, bz] = back as [number, number, number];
  const [vx, vy, vz] = view as [number, number, number];
  let s: Vec3 = [by * vz - bz * vy, bz * vx - bx * vz, bx * vy - by * vx];
  let len = Math.hypot(...s);
  if (len < 1e-6) {
    const helper: Vec3 = Math.abs(by) < 0.9 ? [0, 1, 0] : [1, 0, 0];
    s = [by * helper[2] - bz * helper[1], bz * helper[0] - bx * helper[2], bx * helper[1] - by * helper[0]];
    len = Math.hypot(...s);
  }
  return [s[0] / len, s[1] / len, s[2] / len];
}

const overlaps = (a: Rect, b: Rect): boolean => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

// Greedy by priority: a label shows unless it collides with a higher-priority label already shown.
export function visibleLabels(items: readonly { key: CoteKey; rect: Rect }[]): CoteKey[] {
  const kept: { key: CoteKey; rect: Rect }[] = [];
  const sorted = [...items].sort((a, b) => COTE_PRIORITY.indexOf(a.key) - COTE_PRIORITY.indexOf(b.key));
  for (const item of sorted) if (!kept.some((k) => overlaps(k.rect, item.rect))) kept.push(item);
  return kept.map((k) => k.key);
}

const HYSTERESIS = (10 * Math.PI) / 180;
const wrap = (a: number): number => Math.atan2(Math.sin(a), Math.cos(a));

// Exponential approach along the shortest arc, so the cotes follow the camera azimuth without ever
// swinging the long way round the ±π seam.
export function dampAngle(current: number, target: number, delta: number, rate: number, instant = false): number {
  if (instant) return target;
  return current + wrap(target - current) * (1 - Math.exp(-delta * rate));
}

// Elevation of the camera above the part's mid-height plane.
export function facingFace(elevation: number, prev: Face, hysteresis = HYSTERESIS): Face {
  if (elevation > hysteresis) return 'top';
  if (elevation < -hysteresis) return 'bottom';
  return prev;
}

// Lowest point of an axisymmetric profile still at full radius: where the bottom face's visible rim sits.
export function rimBottom(profile: readonly (readonly [number, number])[]): number {
  const maxR = Math.max(...profile.map(([r]) => r));
  return Math.min(...profile.filter(([r]) => r >= maxR * FULL_RADIUS).map(([, y]) => y));
}
