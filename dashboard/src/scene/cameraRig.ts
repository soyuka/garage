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

/** A held camera focus. `key` re-triggers a dolly even for a repeat region (mirrors PulseRegion). */
export interface CameraFocus {
  region: BodyRegion;
  key: number;
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
 * A closer framing centred on `anchor`: keep the home viewing angle (azimuth + tilt), but re-target onto
 * the region and pull the camera in to {@link DOLLY_RADIUS_FACTOR} of the home radius. Retargeting is what
 * makes the move read as "going to the region" (each region's anchor differs); the dolly-in fills the frame.
 * The tilt is clamped so a preset can never leave the OrbitControls polar band.
 */
export function derivePreset(anchor: Vec3, home: CameraPose = HOME, factor: number = DOLLY_RADIUS_FACTOR): CameraPose {
  const homeOffset: Vec3 = [home.position[0] - home.target[0], home.position[1] - home.target[1], home.position[2] - home.target[2]];
  const { radius, polar, azimuth } = toSpherical(homeOffset);
  const offset = fromSpherical(radius * factor, clampPolar(polar), azimuth);
  return {
    position: [anchor[0] + offset[0], anchor[1] + offset[1], anchor[2] + offset[2]],
    target: [...anchor] as Vec3,
  };
}

/** The camera preset for a region on a body class — derived from the region's 3D anchor. */
export function presetForRegion(bodyClass: BodyClass, region: BodyRegion): CameraPose {
  const anchor: Anchor = regionAnchors(bodyClass)[region];
  return derivePreset(anchor as Vec3);
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
