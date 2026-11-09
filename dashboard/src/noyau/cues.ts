import { CUES } from '../tokens/audition';

// Pure burst and work rules for the Web Audio edge in `useCues.ts`.

export type Cue = (typeof CUES.names)[number];

/**
 * Visual magnitude, and nothing else. Used only to pick a burst's winner: to the ear, a burst that
 * ghosts the shell and then raises a panel is a reveal, whatever order the frames arrived in.
 */
const RANK: Record<Cue, number> = {
  reveal: 10,
  opening: 9,
  blocked: 8,
  complete: 7,
  point: 6,
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
  /** the agent has said something of its own this turn (a step, an answer, a proposal) */
  answered?: boolean;
}

/**
 * Whether the working loop should sound.
 *
 * This is the rule that keeps the loop on the right side of *no theatre*: it is not padding, it is bounded by real work. It requires work actually in flight,
 * keeps running beneath a spoken line at the ducked gain, and waits out `loopAfterMs` so a fast step
 * passes in silence.
 */
export function loopSounds(work: Work, afterMs: number = CUES.loopAfterMs): boolean {
  return work.busy && !work.answered && work.sinceMs >= afterMs;
}

/** The loop's gain factor `sinceMs` into the wait: a long wait thins it rather than letting it nag. */
export function loopScale(sinceMs: number, thinAfterMs: number, thinGain: number): number {
  return sinceMs >= thinAfterMs ? thinGain : 1;
}

/** Whether a cue may sound again `at`, given when it last did and its palette's repeat window. */
export function cueRepeats(lastAt: number | undefined, at: number, repeatMs: number | undefined): boolean {
  return lastAt === undefined || repeatMs === undefined || at - lastAt >= repeatMs;
}

/** What the operator has heard from a turn so far: the ticker line, the retained notices, the step chips, the tray, the documentation scans. */
export interface TurnHeard {
  narration: string | null;
  notices: number;
  steps: string[];
  proposal: boolean;
  /** documentation scans played so far (the view's `scanSeq`); absent where nothing scans */
  scans?: number;
}

/**
 * Whether the agent has said anything of its own since the turn began. The click-time acknowledgement
 * (`acknowledge.ts`) is the app talking, not the agent, so it does not end the wait.
 */
export function agentAnswered(start: TurnHeard, now: TurnHeard): boolean {
  return now.steps.some((s) => s !== 'acknowledge') || now.narration !== start.narration || now.notices > start.notices || (now.proposal && !start.proposal) || (now.scans ?? 0) > (start.scans ?? 0);
}
