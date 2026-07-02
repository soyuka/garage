import type { ForecastDue, Signal } from '../types';
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
