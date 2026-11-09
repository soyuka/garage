// ─────────────────────────────────────────────────────────────────────────────
// The Atelier motion scale — milliseconds, one step per INTENT.
//
// Its twin is the `--at-dur-*` block in tokens/atelier.css; `motion.test.ts` fails if the two drift.
// Two homes because there are two runtimes: CSS owns the DOM transitions, this module owns the
// motions that run inside `useFrame` (the earned-camera dolly, the region pulse) and the JS timers
// that bracket them — a `useFrame` loop cannot read a CSS custom property per frame.
//
// A step is an intent, not a number: pick by what the motion MEANS, never by taste. A duration that
// does not fit an intent here is a sign the motion has no data behind it.
// Loop PERIODS are a different quantity (a frequency, not a duration) and only `breathe` is one —
// it is protected: Le Noyau's ~4s sine breathe is the sleeping-Mac signature, not a placeholder.
// ─────────────────────────────────────────────────────────────────────────────

export const MOTION = {
  /** a control tints: colour/border answering hover, focus, disabled */
  tint: 140,
  /** a surface swaps: dims, slides, cross-fades in place */
  swap: 240,
  /** a surface arrives: a sheet, a popup card, a projection panel; also one visit slab's rise/sink
   *  (twin of VisitRing's RISE_SECONDS) */
  rise: 280,
  /** a line etches: narration, a CTA rising from the cradle */
  etch: 360,
  /** a set fans in: staggered constellation nodes, manifest rows */
  fan: 480,
  /** the done filament cross-fades to its result colour */
  filament: 600,
  /** the domain data cascade — health bars, cardinals, hotspots; also the
   *  visit ring's stagger window (twin of visitRing's CASCADE_SECONDS) */
  cascade: 800,
  /** the earned-camera dolly to a body region (twin of cameraRig.DOLLY_SECONDS) */
  dolly: 900,
  /** one region pulse — about the length of a short tool call (twin of holo.PULSE_DURATION) */
  pulse: 1400,
  /** Le Noyau's idle breathe PERIOD — the protected signature */
  breathe: 4000,
} as const;

/**
 * How long a state is HELD before the next one is allowed — a dwell, not a motion. Separate from
 * MOTION because nothing animates for this long; these are the pauses the choreography needs to stay
 * readable, and the liveness deadlines. Named here so they are reviewable in one place instead of
 * being literals scattered through the orchestrator.
 */
export const DWELL = {
  /** the done filament is legible before the orb returns to veille (useNoyau) */
  settle: 1600,
  /** a hands-off §9 concern murmur is readable before the orb settles itself (Atelier) */
  concern: 2000,
  /** a rejected deterministic answer's warning paints before the orb settles with no surface */
  warn: 600,
  /** a notice card's read time before it auto-closes (NoticeStack) — never counted while its own line
   *  is still being spoken; the dwell starts fresh when that sentence ends */
  noticeRead: 8000,
  /** the in-app turn's liveness watchdog: the worker runs async, so a dead one must not hang the orb
   *  forever. NOT applied to a pilot run — see PILOT_QUIET_MS. */
  watchdog: 90_000,
} as const;

// ── Pilot-session timings ─────────────────────────────────────────────────────
// A pilot run is human-paced: frames arrive in bursts with dead air between them (docs/pilot.md).
// These two numbers are the whole burstiness policy, and each answers to a datum:

/**
 * The burst window. Render intents landing inside it are ONE gesture, so the camera dollies once, to
 * where the burst ended — the operator is shown the conclusion, not the path. Below the ~200ms bound
 * at which a delay stops reading as caused by the frame that triggered it, so coalescing the camera
 * never makes the move feel disconnected. It delays the DOLLY only: panels open the instant they can
 * (motion may overlap an arrival, never gate one).
 */
export const BURST_MS = 120;

/**
 * How long a pilot run may go without a frame before the console reads "en attente": the pilot holds
 * the run but is not acting (Claude is reading code, the operator is typing a prompt). Long enough
 * that the gaps between tool calls in a live burst never trip it, short enough that a human looking
 * up at the screen is not told the machine is working when it is not.
 */
export const PILOT_QUIET_MS = 4000;
