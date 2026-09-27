// ─────────────────────────────────────────────────────────────────────────────
// The Atelier audition constants — the ear's twin of motion.ts (CHOREOGRAPHY § Binding rules:
// "Constants live in code, not CSS. `src/tokens/audition.ts` beside `motion.ts`"). Seconds where the
// quantity is placed on the AUDIO clock (`AudioContext.currentTime`), milliseconds where it is a JS
// deadline. A component may not spell one of these out.
// ─────────────────────────────────────────────────────────────────────────────

export const SPEECH = {
  /** audio held before the first sample is scheduled — two pocket-tts pieces (80 ms of audio every
   *  ~60 ms, measured 2026-09-05, docs/voice.md), so one late piece is a wobble in the margin and not
   *  a gap in the first word */
  primeSeconds: 0.16,
  /** how far ahead of the audio clock a piece is placed: a placement racing the render thread must
   *  never land in the past, and this is well under one piece */
  leadInSeconds: 0.03,
  /** how long a suspended context is given to come back running before the line is treated as REFUSED
   *  — Firefox with autoplay off leaves `resume()` pending forever, so this cannot be an await */
  resumeGraceMs: 250,
} as const;

/**
 * The ONE signal's peak, as CHOREOGRAPHY § Audition specifies it. The `échéance` earcon is not built
 * yet, but the number is recorded here because it is the ceiling every other sound answers to: nothing
 * the Atelier plays may reach signal level, or the one thing that must cut through stops cutting through.
 */
export const SIGNAL = {
  /** peak gain, never normalised. Raised from `0.14` to `0.28` on 2026-09-17 with the whole palette
   *  (CHOREOGRAPHY § "Audition — transition cues", the 2026-09-17 level correction): the ceiling is
   *  what every other sound is measured against, so lifting the room without lifting it would have
   *  flattened the hierarchy instead of moving it. The échéance earcon is not built yet, so nothing
   *  else reads this number. */
  peakGain: 0.28,
} as const;

/**
 * The approved spatial transition cues (CHOREOGRAPHY § "Audition — transition cues", revised
 * 2026-09-11). Listening assets are capped at −12 dBFS and quiet recordings are not boosted.
 *
 * Every gain sits under {@see SIGNAL.peakGain}: the cues are the machine talking about itself, and the
 * one caution signal has to be able to cut through all of them.
 *
 * Levels raised 6 dB on 2026-09-17 — every gain and the ceiling multiplied by the same 2, so the
 * palette's internal ratios are exactly the ones the 2026-09-11 audition approved. The loudest cue is
 * now unity, which is the honest ceiling: the files are mastered to −12 dBFS and a quiet recording is
 * not boosted, so going further would mean re-mastering the assets, not editing this table.
 */
export const CUES = {
  names: ['panel', 'reveal', 'select', 'card', 'dismiss', 'complete', 'blocked', 'mic', 'opening', 'working'] as const,
  gain: {
    panel: 1,
    reveal: 1,
    select: 0.76,
    card: 0.92,
    dismiss: 0.68,
    complete: 0.92,
    blocked: 0.92,
    mic: 0.84,
    opening: 0.96,
  },
  /** the loop is the quietest thing the app plays — it runs UNDER the others, not beside them */
  loopGain: 0.24,
  speechDuck: 0.25,
  /**
   * How long a turn must be silent before the loop starts. Most steps resolve faster; without this the
   * loop would stutter on every 400 ms step, which is a nervous tic rather than a progress indicator.
   */
  loopAfterMs: 1500,
  /** loop fade-in — stopping is a short bounded release because speech and the mic take priority */
  loopFadeSeconds: 0.45,
  /**
   * The burst window, mirroring the camera's (`tokens/motion.ts` BURST_MS) — a piloted turn lands its
   * intents together and the ear gets ONE of them.
   */
  burstMs: 120,
} as const;
