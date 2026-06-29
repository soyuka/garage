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

/** The blueprint edge steel-blue (locked decision: distinct from the agent's rationed cyan). */
export const BLUEPRINT_EDGE = '#a8c4dc';
