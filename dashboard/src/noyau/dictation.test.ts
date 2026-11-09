import { describe, expect, it } from 'vitest';
import {
  MAX_RECORDING_MS,
  dictate,
  emptyDictation,
  merge,
  submission,
  type DictationState,
} from './dictation';

/** The control pressed once: the mic is being ASKED for, and is not open yet. */
const armed = (): DictationState => dictate(emptyDictation, { type: 'press', at: 0, busy: false });
/** …and the browser handed the stream over, which is when the recording actually starts. */
const recording = (at = 0): DictationState => dictate(armed(), { type: 'opened', at });

/**
 * A LATCHED toggle: one press opens the mic, the next closes it — corrected 2026-09-11, hours after
 * push-to-talk shipped, because a held control cannot survive the permission prompt. The prompt is
 * modal and takes the pointer, so the release always arrived before the stream did and every first
 * recording of a session was lost. The rest of the rule is unchanged: no VAD, no wake word, nothing
 * opens the mic but a press, and the operator can see it is open for exactly as long as it is.
 */
describe('dictate — the press-to-start, press-to-stop state machine', () => {
  it('asks for the mic on a press, and is not yet recording', () => {
    expect(armed()).toEqual({ phase: 'arming', startedAt: null, unavailable: false });
  });

  // The clock starts when the STREAM does, not when the control was pressed: the permission prompt
  // can sit on screen for seconds, and the cap must not spend them.
  it('starts recording, and starts the clock, when the stream arrives', () => {
    expect(recording(9_000)).toEqual({ phase: 'recording', startedAt: 9_000, unavailable: false });
  });

  // A turn writes to the garage and one instruction at a time is the rule on both input paths
  // (Atelier.tsx `send` already no-ops while busy).
  it('ignores a press while a turn is busy', () => {
    expect(dictate(emptyDictation, { type: 'press', at: 1_000, busy: true })).toBe(emptyDictation);
  });

  it('transcribes on the second press', () => {
    const state = dictate(recording(), { type: 'press', at: 3_000, busy: false });

    expect(state).toEqual({ phase: 'transcribing', startedAt: null, unavailable: false });
  });

  /**
   * Pressed again while the permission prompt is still up: the operator changed their mind, so the
   * gesture is cancelled outright. Nothing is transcribed — there is no audio yet — and the caller is
   * left to close the stream it will be handed a moment later.
   */
  it('cancels the gesture when pressed again while still arming', () => {
    expect(dictate(armed(), { type: 'press', at: 500, busy: false })).toEqual(emptyDictation);
  });

  // The cancelled stream lands anyway, a beat later. It must not open a recording nobody asked for.
  it('ignores a stream that arrives after the gesture was cancelled', () => {
    const cancelled = dictate(armed(), { type: 'press', at: 500, busy: false });

    expect(dictate(cancelled, { type: 'opened', at: 600 })).toBe(cancelled);
  });

  // A mic left open by a control nobody pressed again is the failure this bounds.
  it('stops itself at the cap and transcribes what it has', () => {
    const state = dictate(recording(0), { type: 'tick', at: MAX_RECORDING_MS });

    expect(state.phase).toBe('transcribing');
  });

  it('keeps recording before the cap', () => {
    const state = dictate(recording(0), { type: 'tick', at: MAX_RECORDING_MS - 1 });

    expect(state.phase).toBe('recording');
  });

  // The cap is measured from the stream, so a slow permission prompt cannot eat the recording.
  it('does not tick while the mic is still being asked for', () => {
    const asking = armed();

    expect(dictate(asking, { type: 'tick', at: MAX_RECORDING_MS * 2 })).toBe(asking);
  });

  it('returns to rest once the transcript lands', () => {
    const state = dictate(dictate(recording(), { type: 'press', at: 1, busy: false }), {
      type: 'transcript',
      text: 'Enregistre une vidange.',
    });

    expect(state.phase).toBe('idle');
  });

  // Silence, a 503, a dead sidecar: all of them end with the operator typing, none of them with a
  // card to dismiss.
  it('returns to rest on a failure, without latching anything closed', () => {
    const state = dictate(dictate(recording(), { type: 'press', at: 1, busy: false }), { type: 'failed' });

    expect(state).toEqual({ phase: 'idle', startedAt: null, unavailable: false });
  });

  /**
   * A refused permission is an answer, not an error state to decorate: the control disappears for the
   * session rather than re-prompting. `unavailable` is what hides it.
   */
  it('latches the mic away for the session when permission is denied', () => {
    const denied = dictate(armed(), { type: 'denied' });

    expect(denied).toEqual({ phase: 'idle', startedAt: null, unavailable: true });
    expect(dictate(denied, { type: 'press', at: 5_000, busy: false })).toBe(denied);
  });

  // The transcript is in flight: pressing again must not open a second mic behind it.
  it('ignores a press while a transcript is already in flight', () => {
    const inFlight = dictate(recording(), { type: 'press', at: 1, busy: false });

    expect(dictate(inFlight, { type: 'press', at: 2, busy: false })).toBe(inFlight);
  });

  it('ignores a stream handed over when nothing asked for one', () => {
    expect(dictate(emptyDictation, { type: 'opened', at: 1_000 })).toBe(emptyDictation);
  });
});

describe('merge — what the transcript does to the composer', () => {
  it('fills an empty composer', () => {
    expect(merge('', 'Enregistre une vidange.')).toBe('Enregistre une vidange.');
  });

  /**
   * Typed text is never destroyed: the operator may have started typing and finished by voice. The
   * transcript is APPENDED, one space between, cursor left at the end.
   */
  it('appends to what was already typed', () => {
    expect(merge('Mazda 6', 'vidange à 142 000 km')).toBe('Mazda 6 vidange à 142 000 km');
  });

  it('does not double the spacing', () => {
    expect(merge('Mazda 6 ', '  vidange')).toBe('Mazda 6 vidange');
  });

  // Silence must cost one gesture and nothing else — least of all the sentence being typed.
  it('leaves the composer untouched when the transcript is empty', () => {
    expect(merge('Mazda 6', '')).toBe('Mazda 6');
    expect(merge('Mazda 6', '   ')).toBe('Mazda 6');
  });
});

/**
 * What a landed transcript DOES — reversed 2026-09-11 at the operator's request, having used the
 * Enter version: the line now sends itself. The rule it replaces (*nothing is submitted unheard*) was
 * retired.
 */
describe('submission — a transcript sends itself', () => {
  it('sends the dictated line', () => {
    expect(submission('', 'Enregistre une vidange.', false)).toBe('Enregistre une vidange.');
  });

  // Typed text is still never destroyed: the operator may have begun typing and finished by voice, and
  // what goes out is the whole sentence, not the half the mic heard.
  it('sends what was typed AND what was said, in that order', () => {
    expect(submission('Mazda 6', 'vidange à 142 000 km', false)).toBe('Mazda 6 vidange à 142 000 km');
  });

  it('sends nothing for a transcript with no words in it', () => {
    expect(submission('', '', false)).toBeNull();
    expect(submission('', '   ', false)).toBeNull();
  });

  /**
   * One instruction at a time, the rule both input paths already honour (`Atelier.tsx` `send` no-ops
   * while busy). Holding the line back rather than submitting it is what keeps the words: a composer
   * cleared into a `send` that no-ops is a dictated sentence that simply vanished.
   */
  it('holds the line back while a turn is running, rather than losing it', () => {
    expect(submission('', 'Enregistre une vidange.', true)).toBeNull();
  });
});
