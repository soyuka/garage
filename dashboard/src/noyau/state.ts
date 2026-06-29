import type { Signal, Tone } from '../types';

// The frame→state machine for Le Noyau. It speaks the REAL session-frame shapes (turn/status/toast)
// plus two local UI events (typing, settle) that have no wire frame — so Stage C is a transport swap,
// not a rewrite. Pure + clock-injected (timestamps passed in) so it is fully unit-testable.

export type NoyauPhase = 'idle' | 'listening' | 'working' | 'done';

export interface JournalEntry {
  id: number;
  kind: 'pulse' | 'narration' | 'alarm' | 'done';
  message: string;
  elapsedMs: number; // since the current turn started
  signal?: Signal; // on a 'done' entry
}

export interface NoyauState {
  phase: NoyauPhase;
  narration: string | null; // the single ticker line — never stacks
  pulseCount: number; // pulses fired this turn (one per narrated status step)
  result: Signal | null; // set on 'done'
  ember: number; // pending alarm notifications docked to the orb
  worstTone: Tone | null; // highest-severity toast this turn → drives the done color
  journal: JournalEntry[];
  turnStartedAt: number | null;
  entrySeq: number;
}

export type NoyauEvent =
  | { type: 'turn'; phase: 'start' | 'end' }
  | { type: 'status'; message: string }
  | { type: 'toast'; tone: Tone; message: string }
  | { type: 'concern'; message: string } // §9 overdue DOMAIN push (no turn): dock ember + murmur, hands-off
  | { type: 'typing'; active: boolean } // local UI cue, not a Mercure frame
  | { type: 'settle' } // local: the done animation finished → back to idle
  | { type: 'clearEmber' }; // local: the user acknowledged the docked notifications

export const initialNoyau: NoyauState = {
  phase: 'idle',
  narration: null,
  pulseCount: 0,
  result: null,
  ember: 0,
  worstTone: null,
  journal: [],
  turnStartedAt: null,
  entrySeq: 0,
};

const TONE_RANK: Record<Tone, number> = { info: 0, ok: 1, warn: 2, alarm: 3 };

function worse(a: Tone | null, b: Tone): Tone {
  if (a === null) return b;
  return TONE_RANK[b] > TONE_RANK[a] ? b : a;
}

/** The done filament color, derived honestly from the worst toast seen this turn. */
function resultFromTone(tone: Tone | null): Signal {
  if (tone === 'alarm') return 'red';
  if (tone === 'warn') return 'amber';
  return 'green';
}

// The done state is announced as a mono label (not color alone — §8): a screen-reader hears the
// outcome on the aria-live line, and green/amber/red stay distinguishable in grayscale.
const RESULT_LABEL: Record<Signal, string> = {
  green: 'posé · tout est à jour',
  amber: 'posé · un service à prévoir',
  red: 'posé · anomalie détectée',
};

function append(state: NoyauState, entry: Omit<JournalEntry, 'id' | 'elapsedMs'>, at: number): JournalEntry[] {
  const elapsedMs = at - (state.turnStartedAt ?? at);
  return [...state.journal, { ...entry, id: state.entrySeq, elapsedMs }];
}

export function reduceNoyau(state: NoyauState, ev: NoyauEvent, at: number): NoyauState {
  switch (ev.type) {
    case 'typing':
      // Busy orb is never interrupted by typing; listening only toggles from rest.
      if (state.phase === 'working' || state.phase === 'done') return state;
      return { ...state, phase: ev.active ? 'listening' : 'idle' };

    case 'turn':
      if (ev.phase === 'start') {
        return {
          ...state,
          phase: 'working',
          narration: null,
          pulseCount: 0,
          result: null,
          worstTone: null,
          turnStartedAt: at,
        };
      }
      // phase === 'end'
      {
        const signal = resultFromTone(state.worstTone);
        const label = RESULT_LABEL[signal];
        return {
          ...state,
          phase: 'done',
          result: signal,
          narration: label, // surfaces the outcome as text on the aria-live line
          journal: append(
            { ...state, turnStartedAt: state.turnStartedAt ?? at },
            { kind: 'done', message: label, signal },
            at,
          ),
          entrySeq: state.entrySeq + 1,
        };
      }

    case 'status': {
      // A status implies the agent is working; tolerate a missing turn-start.
      const turnStartedAt = state.phase === 'working' ? state.turnStartedAt : at;
      const base = { ...state, phase: 'working' as const, turnStartedAt };
      return {
        ...base,
        narration: ev.message,
        pulseCount: base.pulseCount + 1,
        journal: append(base, { kind: 'pulse', message: ev.message }, at),
        entrySeq: state.entrySeq + 1,
      };
    }

    case 'toast': {
      const worstTone = worse(state.worstTone, ev.tone);
      if (ev.tone === 'alarm') {
        // Real popup + ember; never hijacks the heartbeat narration line.
        return {
          ...state,
          worstTone,
          ember: state.ember + 1,
          journal: append(state, { kind: 'alarm', message: ev.message }, at),
          entrySeq: state.entrySeq + 1,
        };
      }
      // Non-alarm toasts feed the narration line (matches the live narration routing).
      return {
        ...state,
        worstTone,
        narration: ev.message,
        journal: append(state, { kind: 'narration', message: ev.message }, at),
        entrySeq: state.entrySeq + 1,
      };
    }

    case 'concern':
      // A Mercure domain frame (the §9 overdue crossing) — NOT an agent turn. It docks the ember and
      // murmurs one concern line on the aria-live narration, but must NOT enter 'working' (no orb lift,
      // no scan-lock, no pulse). phase is preserved (idle stays idle; an in-flight turn keeps working).
      return {
        ...state,
        narration: ev.message,
        ember: state.ember + 1,
        worstTone: worse(state.worstTone, 'alarm'),
        journal: append(state, { kind: 'alarm', message: ev.message }, at),
        entrySeq: state.entrySeq + 1,
      };

    case 'settle':
      // Clear worstTone too: a between-turn alarm push (the §9 overdue crossing — a Mercure domain
      // frame with no turn) must not carry its tone into the next idle period.
      return { ...state, phase: 'idle', result: null, narration: null, worstTone: null, turnStartedAt: null };

    case 'clearEmber':
      return state.ember === 0 ? state : { ...state, ember: 0 };
  }
}
