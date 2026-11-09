import type { Point } from './regionPoint';

/** One turn of the car stage's turntable, in seconds. */
export const TURN_SECONDS = 40;
/** How far from a pure side view the camera may swing to face a region: it stays a three-quarter view. */
export const FACING_LIMIT = 0.95;
export const FACE_MS = 500;
export const RESET_MS = 400;
/** After a drag the turntable waits this long before it picks up again. */
export const RESUME_MS = 2000;

/** OrbitControls' autoRotateSpeed for one turn per `turnSeconds` (it advances 2π/3600·speed per frame, at 60 fps). */
export function autoRotateSpeed(turnSeconds: number): number {
  return 60 / turnSeconds;
}

/** The signed shortest rotation from one azimuth to another, in (−π, π]. */
export function shortestTurn(from: number, to: number): number {
  const tau = 2 * Math.PI;
  const d = (((to - from) % tau) + tau) % tau;
  return d > Math.PI ? d - tau : d;
}

/**
 * The orbit azimuth (OrbitControls' theta: atan2(x, z) of camera − target) that turns `point` towards the
 * viewer: on the side of the car the point sits on (the camera's own side for a centre-line point), never
 * further than FACING_LIMIT from that side's square-on view.
 */
export function facingAzimuth(point: Point, target: Point, current: number, limit = FACING_LIMIT): number {
  const dx = point[0] - target[0];
  const dz = point[2] - target[2];
  if (Math.hypot(dx, dz) < 1e-6) return current;
  const side = Math.abs(dz) > 1e-6 ? Math.sign(dz) : Math.cos(current) >= 0 ? 1 : -1;
  const a = Math.max(-limit, Math.min(limit, Math.atan2(dx, Math.abs(dz))));
  return side > 0 ? a : shortestTurn(0, Math.PI - a);
}

/** The system ease-out, cubic-bezier(0.16, 1, 0.3, 1), as the frame loop's exponential equivalent. */
export function easeOutExpo(t: number): number {
  if (t <= 0) return 0;
  if (t >= 1) return 1;
  return (1 - 2 ** (-10 * t)) / (1 - 2 ** -10);
}

export interface TurntableState {
  playing: boolean;
  reduced: boolean;
  dragging: boolean;
  /** The agent's ring is on the car. */
  focused: boolean;
  /** On screen and the tab visible. */
  visible: boolean;
}

/** The stored preference is `playing`; everything else pauses it without touching that preference. */
export function turntableSpins(s: TurntableState): boolean {
  return s.playing && !s.reduced && !s.dragging && !s.focused && s.visible;
}
