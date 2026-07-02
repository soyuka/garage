import type { ForecastDue, Signal } from '../types';
import type { BodyRegion } from './anchors';
import { NEON_RED, NEUTRAL_HEX, SIGNAL_HEX } from './signalColors';

// Pure timing/color logic for the holo podium. The visuals live in Plateau/HoloPodium; everything
// that can be unit-tested lives here.

/** Apple sleep-LED breathe: slow sine ease-in-out, ~4s period, in [0,1]. Every breathing element on
 * the stage (rim arcs, halo ring, light shafts, particles) samples THIS curve off the shared clock so
 * the whole podium inhales in phase. */
export const BREATHE_HZ = 0.25; // 1 / 4s

export function breathe01(tSeconds: number): number {
  return (Math.sin(tSeconds * BREATHE_HZ * Math.PI * 2) + 1) / 2;
}

/** The podium's ambient glow color (halo ring + light shafts + particles): any overdue échéance burns
 * neon red, a due-soon summary warms amber, healthy rests green, untracked stays neutral. The
 * segmented rim stays the per-item gauge; this is the room-light the whole podium casts. */
export function podiumGlow(status: Signal | 'unknown', due: ForecastDue[]): string {
  if (due.some((d) => d.overdue) || status === 'red') return NEON_RED;
  if (status === 'unknown') return NEUTRAL_HEX;
  if (status === 'amber') return SIGNAL_HEX.amber;
  return SIGNAL_HEX.green;
}

// ── PULSE: the agent acts on a region ──────────────────────────────────────────
// The second verb of the motion grammar. Where the scan WAVE (blueprintBuild.patchHolo) sweeps a cyan
// band along the whole car while Le Noyau thinks, the PULSE is a single localized cyan flare on ONE
// region's edges for the duration of a tool call — the agent touching that part of the car. It reuses
// the scan's swept-axis coordinate (vScanC): a stationary band centred at the region's slice, eased in
// and out once. (The third verb, persistent health TINT, lives on the rim/podium, not here.)

/** One pulse lasts about a short tool call. */
export const PULSE_DURATION = 1.4; // seconds

/** One-shot pulse amplitude in [0,1] at `t` seconds into a pulse of `duration`: a half-sine hump — 0 at
 * both ends, 1 at the midpoint, symmetric ease in/out. Silent (0) outside the [0,duration] window. */
export function pulseEnvelope(t: number, duration: number = PULSE_DURATION): number {
  if (duration <= 0 || t <= 0 || t >= duration) return 0;
  return Math.sin((t / duration) * Math.PI);
}

/** Where a region sits along the car's long (swept) axis, as a fraction of the half-length in [-1,1]:
 * +1 = nose, -1 = tail. Follows the anchors' +x=front convention (the glb resolver yaw-corrects meshes
 * to it), so the flare lands on the right end for the proxy and the real mesh alike. The pulse band is a
 * cross-sectional slice at this position — a region, never the whole car. */
const REGION_AXIS: Record<BodyRegion, number> = {
  frontAxle: 0.7,
  hood: 0.55,
  bay: 0.28,
  windshield: 0.0,
  rearAxle: -0.7,
};

export function regionAxisFraction(region: BodyRegion): number {
  return REGION_AXIS[region];
}
