import { regionAnchors, type Anchor, type BodyRegion } from './anchors';
import type { BodyClass } from './CarProxy';

// ─────────────────────────────────────────────────────────────────────────────
// The earned camera (D1) — pure core.
//
// Focus no longer DIMS the stage; the view DOLLIES to the region. The camera move itself is the
// information (where the agent's / operator's attention is). This module is the testable seam: it derives
// a per-region camera preset from the SAME regionAnchors the pins/glow read off (so the framing never
// drifts from the part it names), and runs the intent-gate reducer that keeps the move "earned" — never
// mid-drag (§ generative-UI: earned adaptation beats surprise adaptation), and a user drag breaks the hold.
//
// Coordinate space = the car group's local frame (turntable origin, +x = front). Same as anchors.ts.
// The imperative eased lerp + OrbitControls wiring lives in Plateau's CameraRig leaf.
// ─────────────────────────────────────────────────────────────────────────────

export type Vec3 = [number, number, number];

export interface CameraPose {
  position: Vec3;
  target: Vec3;
}

/** A held camera focus. `key` re-triggers a dolly even for a repeat region (mirrors PulseRegion).
 * `mode` picks the pose family — absent means `focus`, so a reveal is a hold like any other. */
export interface CameraFocus {
  region: BodyRegion;
  key: number;
  mode?: FocusMode;
}

/** The resting framing — the single source (was inline in Plateau's Canvas camera + OrbitControls). */
export const HOME: CameraPose = {
  position: [6.2, 4.3, 9.2],
  target: [0, 0.45, 0],
};

/** OrbitControls polar clamps (angle from +y). A region preset must stay inside them. */
export const POLAR = { min: Math.PI * 0.16, max: Math.PI * 0.49 };

/** A region framing sits at this fraction of the home orbit radius — closer, so the region fills more. */
export const DOLLY_RADIUS_FACTOR = 0.62;

/**
 * How a region is LOOKED AT, as opposed to where it is (that is `regionAnchors`). Three numbers,
 * because the framing has to carry the same three facts the operator needs: which end of the car,
 * from how high, and how big the thing is.
 *
 * - `azimuth` is an OFFSET from the home viewing angle, radians, positive swinging toward the nose.
 *   An offset rather than an absolute keeps every pose on the established viewing side — the camera
 *   moves around the car it is already looking at instead of cutting to the far flank.
 * - `polar` is absolute, from +y. Small = looking down INTO something, large = at it from its own level.
 * - `factor` is the orbit radius as a fraction of home. A caliper is not framed like an engine bay.
 *
 * Per-REGION and shared across body classes on purpose: the anchor already carries the body-class
 * difference, and the angle you read a wheel from does not change because the car is a van.
 */
export interface RegionView {
  azimuth: number;
  polar: number;
  factor: number;
}

export const REGION_VIEWS: Record<BodyRegion, RegionView> = {
  // the engine, from a raised front three-quarter — the angle a person leans over a fender at
  hood: { azimuth: 0.2, polar: Math.PI * 0.3, factor: 0.55 },
  // deeper and steeper than the hood: the bay is a volume you look down into, not a surface
  bay: { azimuth: 0.1, polar: Math.PI * 0.25, factor: 0.48 },
  // the greenhouse, from slightly above and ahead — close to the resting angle, it is the one region
  // the home framing already reads well
  windshield: { azimuth: 0.05, polar: Math.PI * 0.33, factor: 0.62 },
  // a wheel is read at its own level, swung forward so the face is not edge-on
  frontAxle: { azimuth: 0.34, polar: Math.PI * 0.45, factor: 0.4 },
  // …and the rear swings the other way, so the two axles are never the same shot
  rearAxle: { azimuth: -0.4, polar: Math.PI * 0.45, factor: 0.4 },
};

/** The reveal family: the same regions, descended far enough to look INTO the car, no further. */
// Tuned against the real mesh, not derived: a first pass put these at 0.09–0.14π and 0.46 radius —
// literally the "near-vertical over-the-bay pose" C2 asked for — and it was unreadable. Two reasons,
// both only visible on screen. Straight down through a GHOSTED shell superimposes the roof, the floor
// and both flanks into one dense mat of lines, so the steepest angle is the WORST one for a wireframe.
// And at that radius the car overflows the frame, which costs the operator the very thing the reveal is
// for: knowing where on the car they are looking. So the reveal descends decisively but stops short of
// plan view, and stands far enough back that the body still reads. Note these radii are LARGER than the
// plain focus family's: a reveal is not a closer look at the outside, it is the same car seen through —
// what changes is the angle and the ghost, and pulling IN as well would throw away the context that
// makes the diagram locatable.
//
// A consequence worth recording, because it deleted code: C2 planned to WIDEN the polar floor for the
// reveal ("descend to a near-vertical over-the-bay pose", CONFERENCE.md). Once the pose was tuned to be
// readable it landed comfortably INSIDE the resting band, so the widened band — and the machinery in
// CameraRig that had to widen it before the ease and give it back only once the camera was home — was
// dead weight guarding a case that turned out not to exist. The band that stops a user's drag flying
// over the roof is the same band a legible reveal wants.
export const REVEAL_VIEWS: Record<BodyRegion, RegionView> = {
  hood: { azimuth: 0.16, polar: Math.PI * 0.23, factor: 0.84 },
  bay: { azimuth: 0.08, polar: Math.PI * 0.21, factor: 0.82 },
  windshield: { azimuth: 0.04, polar: Math.PI * 0.26, factor: 0.86 },
  frontAxle: { azimuth: 0.3, polar: Math.PI * 0.32, factor: 0.66 },
  rearAxle: { azimuth: -0.36, polar: Math.PI * 0.32, factor: 0.66 },
};

/** Which pose family a hold uses. `reveal` is the cutaway (C1–C4); everything else is `focus`. */
export type FocusMode = 'focus' | 'reveal';

/** Dolly duration — matches the ~900ms sine ease the spec calls for. */
export const DOLLY_SECONDS = 0.9;

export function clampPolar(polar: number, min: number = POLAR.min, max: number = POLAR.max): number {
  return Math.min(max, Math.max(min, polar));
}

/** Sine ease-in-out over [0,1], flat outside. 0→0, 0.5→0.5, 1→1. */
export function sineEaseInOut(t: number): number {
  if (t <= 0) return 0;
  if (t >= 1) return 1;
  return 0.5 - 0.5 * Math.cos(Math.PI * t);
}

/** Cartesian offset → spherical, using the three.js/OrbitControls convention (polar from +y, azimuth
 * around y measured from +z). */
export function toSpherical(offset: Vec3): { radius: number; polar: number; azimuth: number } {
  const [x, y, z] = offset;
  const radius = Math.hypot(x, y, z);
  const polar = radius === 0 ? 0 : Math.acos(Math.min(1, Math.max(-1, y / radius)));
  const azimuth = Math.atan2(x, z);
  return { radius, polar, azimuth };
}

/** Spherical → cartesian offset (inverse of {@see toSpherical}). */
export function fromSpherical(radius: number, polar: number, azimuth: number): Vec3 {
  const sinPolar = Math.sin(polar);
  return [radius * sinPolar * Math.sin(azimuth), radius * Math.cos(polar), radius * sinPolar * Math.cos(azimuth)];
}

/**
 * A framing centred on `anchor`, seen through `view`: re-target onto the region, swing `view.azimuth`
 * off the home viewing angle, tilt to `view.polar`, and pull in to `view.factor` of the home radius.
 * Retargeting is what makes the move read as "going to the region"; the angle is what makes it read as
 * going to THAT region (CHOREOGRAPHY § "The region pose"). The tilt is clamped into `band` so a preset
 * can never land somewhere OrbitControls will immediately snap back out of.
 */
export function derivePreset(anchor: Vec3, view: RegionView, home: CameraPose = HOME, band: { min: number; max: number } = POLAR): CameraPose {
  const homeOffset: Vec3 = [home.position[0] - home.target[0], home.position[1] - home.target[1], home.position[2] - home.target[2]];
  const { radius, azimuth } = toSpherical(homeOffset);
  const offset = fromSpherical(radius * view.factor, clampPolar(view.polar, band.min, band.max), azimuth + view.azimuth);
  return {
    position: [anchor[0] + offset[0], anchor[1] + offset[1], anchor[2] + offset[2]],
    target: [...anchor] as Vec3,
  };
}

/** The camera preset for a region on a body class, in the given pose family. */
export function presetForFocus(bodyClass: BodyClass, region: BodyRegion, mode: FocusMode = 'focus'): CameraPose {
  const anchor: Anchor = regionAnchors(bodyClass)[region];
  return derivePreset(anchor as Vec3, (mode === 'reveal' ? REVEAL_VIEWS : REGION_VIEWS)[region]);
}

/** The plain focus preset — what every non-reveal call site means. */
export function presetForRegion(bodyClass: BodyClass, region: BodyRegion): CameraPose {
  return presetForFocus(bodyClass, region, 'focus');
}

// ── Intent gate ────────────────────────────────────────────────────────────────
// Earned adaptation: the camera obeys explicit intent only. The gate never starts a move mid-drag (it
// queues the request until pointerup), and a user grabbing the camera during a hold wins — the hold breaks.

export interface GateState {
  /** the focus the dolly animates toward (null = home). */
  held: CameraFocus | null;
  /** a request that arrived mid-drag, deferred until the drag ends. */
  queued: CameraFocus | null;
  dragging: boolean;
}

export const GATE_INIT: GateState = { held: null, queued: null, dragging: false };

export type GateEvent =
  | { type: 'request'; focus: CameraFocus | null }
  | { type: 'dragStart' }
  | { type: 'dragEnd' };

export function gateReduce(state: GateState, event: GateEvent): GateState {
  switch (event.type) {
    case 'request':
      // mid-drag → queue (never move while the user orbits); else adopt immediately.
      return state.dragging ? { ...state, queued: event.focus } : { ...state, held: event.focus, queued: null };
    case 'dragStart':
      // the user grabbed the camera — their input wins: drop the hold + any pending request.
      return { held: null, queued: null, dragging: true };
    case 'dragEnd':
      // promote a request that arrived mid-drag; none → settle home.
      return { held: state.queued, queued: null, dragging: false };
  }
}

/**
 * What an operator click on a region control means, given what the camera currently holds. Re-activating
 * the SAME region goes back ("click again to return", D1); any other region is a new hold, not a release —
 * the camera moves rather than snapping home and being sent out again.
 */
export function holdIntent(held: CameraFocus | null, region: BodyRegion): 'hold' | 'release' {
  return held?.region === region ? 'release' : 'hold';
}

/** True when a starting drag interrupts an active or pending hold — the parent must then clear cameraFocus. */
export function dragBreaksHold(state: GateState): boolean {
  return state.held != null || state.queued != null;
}
