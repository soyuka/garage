import { CUES } from '../tokens/audition';

// Pure burst and work rules for the Web Audio edge in `useCues.ts`.

export type Cue = (typeof CUES.names)[number];

/**
 * Visual magnitude, and nothing else. Used only to pick a burst's winner: to the ear, a burst that
 * ghosts the shell and then raises a panel is a reveal, whatever order the frames arrived in.
 */
const RANK: Record<Cue, number> = {
  reveal: 9,
  opening: 8,
  blocked: 7,
  complete: 6,
  select: 5,
  panel: 4,
  card: 3,
  mic: 2,
  dismiss: 1,
  working: 0,
};

export interface CueBurst {
  cue: Cue | null;
  at: number;
  upgraded: boolean;
}

export const CUE_BURST_INIT: CueBurst = { cue: null, at: 0, upgraded: false };

export type CueBurstEvent = { type: 'want'; cue: Cue; at: number } | { type: 'flush'; at: number };

export interface CueBurstOut {
  state: CueBurst;
  /** the one cue to play, once per burst */
  fire: Cue | null;
}

export function cueBurstReduce(state: CueBurst, ev: CueBurstEvent): CueBurstOut {
  if (ev.type === 'want') {
    if (state.cue === null || ev.at - state.at >= CUES.burstMs) {
      return { state: { cue: ev.cue, at: ev.at, upgraded: false }, fire: ev.cue };
    }
    if (!state.upgraded && RANK[ev.cue] > RANK[state.cue]) {
      return { state: { ...state, cue: ev.cue, upgraded: true }, fire: ev.cue };
    }
    return { state, fire: null };
  }
  return { state, fire: null };
}

export interface Work {
  /** a turn is genuinely in flight (`state.ts` busy(), which already excludes a QUIET pilot run) */
  busy: boolean;
  /** a line is on the lips; the playback mix ducks the loop without stopping it */
  speaking: boolean;
  /** how long the turn has been silent */
  sinceMs: number;
}

/**
 * Whether the working loop should sound.
 *
 * This is the rule that keeps the loop on the right side of *no theatre* (`docs/prompt-pilot-motion.md`
 * § Hard rules 1): it is not padding, it is bounded by real work. It requires work actually in flight,
 * keeps running beneath a spoken line at the ducked gain, and waits out `loopAfterMs` so a fast step
 * passes in silence.
 */
export function loopSounds(work: Work): boolean {
  return work.busy && work.sinceMs >= CUES.loopAfterMs;
}
