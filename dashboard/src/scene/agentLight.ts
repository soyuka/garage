import type { NoyauPhase } from '../noyau/state';
import type { Signal } from '../types';
import { HOLO_CYAN, NEON_RED, SIGNAL_HEX } from './signalColors';

// The hologram is the light. The room is dark; two point
// lights carry Le Noyau's state onto the disc and the mat ring: phase as intensity, result as colour.
// Everything a test can hold lives here; AgentLightRig only drives THREE objects from these numbers.
// three r169 has physically-correct lights only: intensity is candela, attenuation
// (1/d^decay) · (1 − (d/distance)^4)^2 — mirrored in `attenuation` so the confinement rule is testable.

export type AgentLight = 'rest' | 'working' | 'quiet' | `done:${Signal}`;

/** phase/result/quiet → the one primitive the Canvas receives. 'listening' is rest (no load yet). */
export function agentLightOf(s: { phase: NoyauPhase; result: Signal | null; quiet: boolean }): AgentLight {
  if (s.phase === 'done') return `done:${s.result ?? 'green'}`;
  if (s.phase === 'working') return s.quiet ? 'quiet' : 'working';
  return 'rest';
}

// ── the room ──
/** Cool, dim: the mat base reads as black mass, its grid faintly. */
export const ROOM_AMBIENT = 0.5;
export const ROOM_AMBIENT_COLOR = '#c8d4e0';
export const ROOM_BACKGROUND = '#07080a';

// ── the two lights ──
export const LIGHT_DECAY = 2;
export const AGENT_LIGHT_POS: [number, number, number] = [0, 1.6, 0];
/** Dies at the rim (r 3.11): irradiance there is under 6 % of r = 1 — pinned in agentLight.test.ts. */
export const AGENT_LIGHT_DISTANCE = 4.2;
export const SPILL_POS: [number, number, number] = [0, 3.0, 0];
export const SPILL_DISTANCE = 14;
/** Spill intensity = SPILL_RATIO × agent intensity; its colour is always HOLO_CYAN. */
export const SPILL_RATIO = 0.5;
/** Candela; intensity = base + amp · breathe01(t). `done` holds steady — the filament does not breathe. */
export const AGENT_LIGHT_LEVELS = {
  rest: { base: 28, amp: 4 },
  quiet: { base: 36, amp: 4 },
  working: { base: 50, amp: 8 },
  done: { base: 50, amp: 0 },
} as const;
/** Lens colour = target colour × LENS_HDR × (0.85 + 0.3·b); toneMapped:false → above 1.0 it blooms.
 *  rest sits just over Bloom's 0.5 luminance threshold (0.75 × HOLO_CYAN's 0.77) so the lens halos faintly. */
export const LENS_HDR = { rest: 0.75, quiet: 0.9, working: 2.2, done: 2.2 } as const;
/** The result colour on the agent light — the same hue the orb's filament shows, at the same moment. */
export const RESULT_LIGHT: Record<Signal, string> = { green: SIGNAL_HEX.green, amber: SIGNAL_HEX.amber, red: NEON_RED };

// ── surfaces the lights land on ──
/** Matte: the pool is a diffuse term. #232a33 is ≈ 0.02 linear albedo, so the metalness that once bought a
 *  specular edge cost 60 % of the little diffuse there was, and an overhead light's lobe misses the HOME camera. */
export const DISC = { color: '#232a33', metalness: 0.2, roughness: 0.7 } as const;
export const EMITTER = {
  count: 4,
  /** Inside RING_IN 3.11, on the disc top (disc r 3.2, top y 0.02). */
  radius: 2.95,
  /** position = [r·cos θ, y, r·sin θ] — 45/135/225/315°. */
  azimuth: (i: number) => ((i + 0.5) * Math.PI) / 2,
  housing: { radiusTop: 0.09, radiusBottom: 0.11, height: 0.06, color: '#2c3440', metalness: 0.3, roughness: 0.4 },
  lens: { radius: 0.055, height: 0.012 },
} as const;

export interface LightTarget {
  hex: string;
  base: number;
  amp: number;
  lens: number;
}

const LIGHT_TARGETS: Readonly<Record<AgentLight, LightTarget>> = Object.freeze({
  rest: Object.freeze({ hex: HOLO_CYAN, ...AGENT_LIGHT_LEVELS.rest, lens: LENS_HDR.rest }),
  quiet: Object.freeze({ hex: HOLO_CYAN, ...AGENT_LIGHT_LEVELS.quiet, lens: LENS_HDR.quiet }),
  working: Object.freeze({ hex: HOLO_CYAN, ...AGENT_LIGHT_LEVELS.working, lens: LENS_HDR.working }),
  'done:green': Object.freeze({ hex: RESULT_LIGHT.green, ...AGENT_LIGHT_LEVELS.done, lens: LENS_HDR.done }),
  'done:amber': Object.freeze({ hex: RESULT_LIGHT.amber, ...AGENT_LIGHT_LEVELS.done, lens: LENS_HDR.done }),
  'done:red': Object.freeze({ hex: RESULT_LIGHT.red, ...AGENT_LIGHT_LEVELS.done, lens: LENS_HDR.done }),
});

/** A lookup, never a build: the rig holds the result across frames (cheap idle — no per-frame allocation). */
export function lightTarget(light: AgentLight): LightTarget {
  return LIGHT_TARGETS[light];
}

/** The cross-fade between two targets — twin of MOTION.filament (motion.test.ts pins it). */
export const FILAMENT_SECONDS = 0.6;

/** 0..1 progress of the cross-fade; reduced motion cuts (state, not attention). */
export function blend01(sinceChange: number, reduced: boolean): number {
  if (reduced) return 1;
  return Math.min(1, Math.max(0, sinceChange / FILAMENT_SECONDS));
}

export function lightIntensity(t: LightTarget, breathe: number): number {
  return t.base + t.amp * breathe;
}

/** three's punctual attenuation, mirrored so the confinement rule is unit-testable. */
export function attenuation(d: number, distance: number, decay: number = LIGHT_DECAY): number {
  const cutoff = Math.min(1, Math.max(0, 1 - (d / distance) ** 4));
  return (1 / Math.max(d ** decay, 0.01)) * cutoff * cutoff;
}

/** Irradiance factor on a horizontal surface at y = 0, radius r from a light at height y. */
export function irradianceAt(r: number, y: number, distance: number): number {
  const d = Math.hypot(r, y);
  return attenuation(d, distance) * (y / d);
}
