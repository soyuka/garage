// The pure half of dictation. Every rule that matters — when the mic
// may open, when it closes itself, what a refused permission costs, what a transcript does to the
// composer — is decided here, so the impure edge (recorder.ts, useDictation.ts) is left with nothing
// but getUserMedia, MediaRecorder and one fetch. Same split as pcm.ts / speaker.ts, same reason: a
// repo with no browser can still test the rules.
//
// Dictation is a CONVENIENCE over a keyboard that still works. Every failure path below ends with the
// operator typing, and none of them ends in a card to dismiss.
//
// The control LATCHES — press to start, press again to stop (corrected 2026-09-11, hours after
// push-to-talk shipped). Holding cannot work: the permission prompt is modal and takes the pointer, so
// the release always arrived before the stream and the first recording of every session was lost. What
// the original rule was actually protecting — no VAD, no wake word, nothing opens the mic but a
// deliberate gesture, and the open mic is visible for exactly as long as it is open — all survives.

export type DictationPhase =
  /** At rest. The control is offered and nothing is open. */
  | 'idle'
  /**
   * The mic has been asked for and the browser has not handed it over yet — the permission prompt may
   * be on screen. Its own phase because nothing is being captured during it: the cap must not run, and
   * the operator must not be told the room is being listened to when it is not.
   */
  | 'arming'
  | 'recording'
  | 'transcribing';

export interface DictationState {
  phase: DictationPhase;
  /** When the mic opened — the cap below is measured from it. */
  startedAt: number | null;
  /**
   * The mic is gone for the rest of the session: permission refused, or no secure context. A browser
   * permission the operator declined is an answer, not an error to re-prompt against, so the control
   * disappears rather than sitting there asking again.
   */
  unavailable: boolean;
}

export type DictationEvent =
  /**
   * The operator pressed the control — the ONE gesture, latched: it opens the mic at rest, closes it
   * while recording, and cancels the gesture while still arming. `busy` is the running-turn lock the
   * composer already honours.
   */
  | { type: 'press'; at: number; busy: boolean }
  /** The browser handed the stream over. This, not the press, is when a recording exists. */
  | { type: 'opened'; at: number }
  /** The clock, for the cap only — nothing else in here needs time to pass. */
  | { type: 'tick'; at: number }
  | { type: 'transcript'; text: string }
  /** A 503, a dead sidecar, a network that went away. Spends the gesture, latches nothing. */
  | { type: 'failed' }
  | { type: 'denied' };

/**
 * An instruction is one line. A recording past this is a mic the operator forgot to close, so it stops
 * itself and transcribes what it has rather than waiting for a second press that may never come — the
 * bound that matters most now the control LATCHES (2026-09-11). Measured from the stream, not from the
 * press, so a slow permission prompt cannot eat the recording. Matches the `/stt` byte cap at the other
 * end (30 s of opus ≈ 90 KB, capped there at 1 MB).
 */
export const MAX_RECORDING_MS = 30_000;

export const emptyDictation: DictationState = { phase: 'idle', startedAt: null, unavailable: false };

const rest = (unavailable: boolean): DictationState => ({ phase: 'idle', startedAt: null, unavailable });

/**
 * Returns the SAME object when an event changes nothing, so a caller can treat identity as "no
 * transition" — which is also what keeps a repeated press from orphaning a running recorder.
 */
export function dictate(state: DictationState, event: DictationEvent): DictationState {
  switch (event.type) {
    case 'press':
      if (state.unavailable || event.busy) return state;
      // Pressed while the permission prompt is still up: the operator changed their mind before there
      // was any audio, so the gesture is simply spent. The caller closes the stream it is about to be
      // handed (`useDictation.ts`) — there is nothing here to transcribe.
      if ('arming' === state.phase) return rest(false);
      if ('recording' === state.phase) return { ...state, phase: 'transcribing', startedAt: null };
      if ('idle' !== state.phase) return state; // a transcript in flight: one instruction at a time

      return { phase: 'arming', startedAt: null, unavailable: false };

    case 'opened':
      // A stream for a gesture that is no longer waiting for one — cancelled, or denied and latched.
      // It opens nothing; the caller stops it.
      if ('arming' !== state.phase) return state;

      return { ...state, phase: 'recording', startedAt: event.at };

    case 'tick':
      if ('recording' !== state.phase || null === state.startedAt) return state;
      if (event.at - state.startedAt < MAX_RECORDING_MS) return state;

      return { ...state, phase: 'transcribing', startedAt: null };

    case 'transcript':
    case 'failed':
      return rest(state.unavailable);

    case 'denied':
      return rest(true);
  }
}

/**
 * What the transcript does to the composer's current value. Appends rather than replaces: the operator
 * may have begun typing and finished by voice, and destroying typed text is the one thing a
 * convenience must never do. An empty transcript — silence, a mis-press, a room too loud — returns the
 * input untouched, which is how a mis-press costs exactly one gesture.
 */
export function merge(existing: string, transcript: string): string {
  const spoken = transcript.trim();
  if ('' === spoken) return existing;

  const typed = existing.trim();

  return '' === typed ? spoken : `${typed} ${spoken}`;
}

/**
 * The line a landed transcript submits — or null to leave the words in the composer and send nothing.
 *
 * **A transcript sends itself (2026-09-11).** This reverses the rule the mic shipped with,
 * *nothing is submitted unheard*, at the operator's request after using the Enter version: dictation
 * exists for hands that are in an engine bay, and a gesture that still ends at the keyboard gives back
 * most of what the mic was for. The argument that was traded away:
 * a turn writes to the garage and has no undo, so a misheard plate or odometer reading now reaches a
 * real record with no beat in between. The cancel control on a running turn is what is left of that
 * beat.
 *
 * Null while a turn is running rather than sending anyway: one instruction at a time is the rule both
 * input paths honour (`Atelier.tsx` `send` no-ops while busy), and a composer cleared into a `send`
 * that no-ops is a dictated sentence that simply vanished.
 */
export function submission(existing: string, transcript: string, busy: boolean): string | null {
  if (busy) return null;

  const line = merge(existing, transcript).trim();

  return '' === line ? null : line;
}
