import type { Signal } from '../types';

// JS-side mirror of the --at-*-hi signal tokens, for THREE materials (RimGauge arcs, BodyGlow halos)
// which cannot read CSS custom properties. Keep in lockstep with tokens/atelier.css. The "hi" variants
// are used because emissive/additive 3D glow reads best at the bright end of each signal.
export const SIGNAL_HEX: Record<Signal, string> = {
  green: '#4ade80', // --at-green-hi
  amber: '#fcd34d', // --at-amber-hi
  red: '#fca5a5', // --at-red-hi
};

/** Neutral / not-tracked — matches --at-unknown. NOT a healthy state. */
export const NEUTRAL_HEX = '#5b5f66';

/** Saturated neon red for 3D emissive alarms (rim arcs, podium glow). SIGNAL_HEX.red is a pale pink
 * tuned for text that glows weakly as emissive; an overdue arc wants a hot alarm, not a blush. */
export const NEON_RED = '#ff2418';

/** The blueprint edge — electric blue, saturated (hue ~220°, deliberately bluer than Le Noyau's
 * rationed cyan ~190° so "cyan = the agent thinking" stays unambiguous). */
export const BLUEPRINT_EDGE = '#6d9eff';
