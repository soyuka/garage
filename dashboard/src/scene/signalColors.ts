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

/** The holo cyan — the agent's own light: the scan band, the region pulse, the two stage lights and the
 * emitter lenses all wear it. Rationed to the agent (tokens/atelier.css `--at-cyan` comment); never a
 * painted fill, and never a signal. */
export const HOLO_CYAN = '#5fe0d9';

/** The blueprint edge — electric blue, saturated (hue ~220°, deliberately bluer than Le Noyau's
 * rationed cyan ~190° so "cyan = the agent thinking" stays unambiguous). */
export const BLUEPRINT_EDGE = '#6d9eff';

/** The operator's focus hue — mirrors `--at-orange`, and carries the same ration: the OPERATOR's
 * subject and the primary action, never a signal and never the agent's cyan. A reveal's called-out
 * part wears it because a part named in the answer is standing state, not an action in flight. */
export const OPERATOR_HEX = '#ee4322';
