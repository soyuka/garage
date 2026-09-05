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
