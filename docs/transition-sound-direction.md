# Transition sound direction

Review date: 2026-09-11. The operator approved the ten-sound spatial palette and requested integration.
The earlier review remains below as evidence for the replacement; current runtime behavior is recorded
in `CHOREOGRAPHY.md` and `docs/voice.md`.

## Direction

**Revised after listening, 2026-09-11:** the operator rejected the tactile workshop candidates as
harsh, strident and unpleasant. The previous ceramic/felt/aluminium direction, recorded close and
dry, overemphasized physical contacts. The requested direction is spatial, futuristic interface audio
with depth, like a Jarvis interface: rounded low-mid synthetic motion, soft attacks, stereo expansion
and diffuse tails. Rejecting futuristic UI vocabulary was an unjustified creative constraint.

**Second audition, 2026-09-11:** the operator liked `reveal-spatial` and rejected `panel-spatial`
as too high-pitched. Preserve that reveal recording unchanged. The revised panel brief asks for a
dark, muted low-mid swell settling downward in pitch. The operator approved generating the remaining
palette: selection, panel, proposal, dismissal, completion, blocked action, microphone ready,
working loop and opening. These are audition assets, not authorization to wire new runtime behavior.

Preserve raw generations alongside listening copies with softened upper frequencies and conservative
playback levels. This remains a hypothesis to audition, not a claim that filtering guarantees pleasant sound.
Silence remains an intentional choice for idle and redundant updates.

Six candidates were generated separately in [the audition set](audio-candidates-2026-09-11/index.html): `select`
(0.5 s), `panel` (0.7 s), `reveal` (1.4 s), `card` (0.6 s), `dismiss` (0.5 s), and `working`
(4 s loop). They are retained as the rejected first direction for comparison; the physical-object
prompt briefs below are historical proposals, not the current generation brief.

## What exists now

The contract has twelve UI frame shapes: `setVehicles`, `selectVehicle`, four panel/surface frames,
`proposeActions`, `focusRegion`, `revealAssembly`, `toast`, `status`, and `turn`
([types.ts](../dashboard/src/types.ts#L307)). `cueFor()` currently maps seven of them into four
one-shots: panel = `showFact` / `focusView` / `showParts` / `showHistory`, plus `reveal`, `select`, and
`card`; everything else is silent ([cues.ts](../dashboard/src/noyau/cues.ts#L26)). A fifth asset loops
for real work ([useCues.ts](../dashboard/src/noyau/useCues.ts#L21)).

The files are PCM 16-bit, 24 kHz, stereo. Measured durations are `select` 0.48 s, `card` 1.00 s,
`panel` 2.00 s, `reveal` 3.08 s, and `working` 0.68 s. The tree records library provenance for
`panel`, `reveal`, and `working`, and generated provenance for `select` and `card`
([voice.md](voice.md#L444)). Those are facts about origin, not evidence that generation cannot
produce event-shaped material: three failed loop prompts are only evidence about those prompts and
outputs ([voice.md](voice.md#L458)).

All cues are forced on ([Atelier.tsx](../dashboard/src/Atelier.tsx#L123)). One-shots are delayed by a
260 ms trailing debounce, then lazily fetched and decoded ([useCues.ts](../dashboard/src/noyau/useCues.ts#L152));
the first occurrence can therefore arrive perceptibly after its visual. The comment says this window
mirrors the camera, but the camera constant is 120 ms ([motion.ts](../dashboard/src/tokens/motion.ts#L73))
while audio is 260 ms ([audition.ts](../dashboard/src/tokens/audition.ts#L57)).

## Complete coverage proposal

“Speech” below means the existing printed-text TTS path, not a new audio asset. “Silent” is explicit
coverage, not an omission.

| Actual transition | Current behavior | Proposed coverage |
|---|---|---|
| Opening / direct arrival after data settles | `greet` is ticker speech once per session ([Atelier.tsx](../dashboard/src/Atelier.tsx#L265), [state.ts](../dashboard/src/noyau/state.ts#L436)); no cue | Keep greeting speech. Audition the optional `opening` cue only if it binds to the workshop becoming ready, never every car change. |
| Parc car → hero entrance | Local departure ghost begins immediately with hash navigation ([Atelier.tsx](../dashboard/src/Atelier.tsx#L154)); agent `selectVehicle` requests `select` | Use `select` for both local and agent selection, fired from the committed navigation/visual transition, not merely a received frame. |
| `setVehicles` / background collection refresh | Silent; it may also select `preferredId` ([Atelier.tsx](../dashboard/src/Atelier.tsx#L454)) | Silent for list refresh. If `preferredId` actually changes selection, reuse `select` once. |
| Panel arrives: `showFact`, `focusView`, `showHistory` | `panel`, even if focus repeats | `panel` only when the visible slot/content actually changes. |
| Parts surface: `showParts` | Requests `panel` before checking whether parts exist; empty input renders nothing ([Atelier.tsx](../dashboard/src/Atelier.tsx#L427), [Atelier.tsx](../dashboard/src/Atelier.tsx#L434)) | `panel` only after a non-empty parts CTA is committed. |
| Local wayfinder opens owned/fetched data | Opens popup/focus via `runLocalAnswer`; spoken status/result, no transition cue ([Atelier.tsx](../dashboard/src/Atelier.tsx#L650)) | Same `panel` family when a surface actually opens; keep speech as payload. |
| Earned camera / `focusRegion` / operator visit-card aim | Camera motion and agent pulse; `focusRegion` has no cue ([Atelier.tsx](../dashboard/src/Atelier.tsx#L366)) | Explicit silence. Camera movement alone does not merit a second signal; a panel/reveal cue already binds compound moves. |
| `revealAssembly` | `reveal` requested even if the region has no diagram and `revealFor()` declines ([Atelier.tsx](../dashboard/src/Atelier.tsx#L386), [Atelier.tsx](../dashboard/src/Atelier.tsx#L440)) | `reveal` only after a valid reveal state is committed. |
| Proposal card: `proposeActions` | `card` requested even when it is deferred straight to the inbox because no hero is open ([Atelier.tsx](../dashboard/src/Atelier.tsx#L446)) | `card` only when a card rises. Inbox-only deferral stays silent. |
| Toast with voice available | Notice card plus speech; cue intentionally absent ([cues.ts](../dashboard/src/noyau/cues.ts#L21)) | Speech only. Success/warn/alarm meaning remains in text and shape; do not double it with a transition cue. |
| Toast when voice is blocked, failed, stale, or mic-muted | Printed notice survives; TTS may legally drop it ([voice.ts](../dashboard/src/noyau/voice.ts#L7)) | Still silent by default. Optional `write success` / `failure` cues only for attributable operation outcomes, never as substitutes for text and never tone-only severity. |
| `status` step with voice available | Ticker speech; no cue | Speech only. |
| `status` without playable voice | Printed ticker only | Explicit silence. A generic blip per tool step would turn a burst into chatter. |
| Real work: `turn/start` until settled | `working` starts after 1.5 s if busy and not speaking ([useCues.ts](../dashboard/src/noyau/useCues.ts#L124)) | Compare the sparse 4 s candidate with complete silence. If retained, start only for a genuinely long wait, yield to speech and mic, and stop on the true end. It is progress, not ambience. |
| Pilot quiet / ordinary idle | `busy()` excludes pilot quiet; no loop | Silent. No room bed. |
| Mic permission pending → recording ready | Speech is muted from the initial press through recording ([Composer.tsx](../dashboard/src/scene/Composer.tsx#L62)); no cue | Prefer silence until capture ordering is proven. An optional `mic ready` must finish playback before recording begins, after real stream readiness; playing it into an open capture would contaminate the transcript. |
| Recording stop → transcription | Button changes to `⋯`; no cue ([Composer.tsx](../dashboard/src/scene/Composer.tsx#L97)) | Explicit silence on stop; visual state is sufficient. Do not sound while a captured mic is still closing. |
| Dictation failure / silence | Silent by design, input unchanged ([useDictation.ts](../dashboard/src/noyau/useDictation.ts#L17)) | Keep silence for empty capture. Consider the common `failure` cue only for a diagnosed failure if the UI also exposes an equivalent visible state. |
| Notice/card dismiss; proposal cancel; panel/reveal close | Visual removal; no cue. Notice dismissal also cancels its speech ([voice.ts](../dashboard/src/noyau/voice.ts#L143)) | Audition `dismiss` for direct operator closes only. Auto-close and supersession stay silent. |
| Turn completion | `turn/end` settles the Noyau; no cue | Explicit silence when a spoken/printed result exists. Optional `write success` only for a completed deterministic mutation whose result is visibly committed. |
| Turn stall or operation error | Watchdog creates a warning toast before failed end ([Atelier.tsx](../dashboard/src/Atelier.tsx#L307)); mutation failures create alarm toasts ([Atelier.tsx](../dashboard/src/Atelier.tsx#L790)) | Speech/printed error remains primary. Audition `failure` only for an actionable blocked stop, never a siren and never for undiagnosed pilot silence. |

## Additional prompt briefs (optional, not generated)

- `mic ready`, 0.5 s: “Close dry recording: one small wooden key seats firmly in a felt-lined
  precision mechanism; a single short, soft contact.”
- `write success`, 0.6 s: “Close dry recording: two small glazed ceramic precision parts fit
  together on felt in one restrained, resolved contact.”
- `failure`, 0.7 s: “Close dry recording: a brushed-aluminium latch moves once and gently reaches a
  padded blocked stop; controlled, low-force and unresolved.”
- `opening`, 1.4 s: “Close dry recording: a felt-lined precision instrument case opens once on a
  workbench; one short natural glass resonance settles behind it.”

These are hypotheses until heard. Avoid over-constraining with long negation lists; the room-bed
experiment found that wording could collapse output toward silence ([voice.md](voice.md#L383)), but
that observation does not establish a universal generation rule.

## Review findings before promotion

1. One-shot cues are not suppressed by speech or the microphone: speech receives `!listening`, while
   cues receive literal `true` ([Atelier.tsx](../dashboard/src/Atelier.tsx#L121)). `play()` has no later
   speech/mic guard ([useCues.ts](../dashboard/src/noyau/useCues.ts#L56)). Review this before any cue ships.
2. Async state can go stale. `startLoop()` checks state before awaiting the asset and does not recheck
   busy/speaking after decode ([useCues.ts](../dashboard/src/noyau/useCues.ts#L102)); a completed turn or
   newly started utterance can therefore be followed by a late loop start.
3. `requestCue(frame)` runs before branch validation or state commitment
   ([Atelier.tsx](../dashboard/src/Atelier.tsx#L419)). Repeated focus, invalid reveal, empty parts, a
   same-car selection, or inbox-deferred proposal can sound without an actual transition.
4. The current 0.68 s loop repeats about **88 times/minute** continuously after threshold. A 4 s loop
   is about 15 repeats/minute, but lower repetition is not automatically better than silence. Audition
   both over a genuinely long wait.
5. All current assets were peak-normalised and then assigned gains ([audition.ts](../dashboard/src/tokens/audition.ts#L31)).
   Equal peak is not equal perceived loudness; match the set by listening and loudness measurement,
   while preserving headroom below the alert ceiling.
6. Mic-ready ordering is unresolved: the current recorder begins when `record()` resolves and then
   emits `opened` ([useDictation.ts](../dashboard/src/noyau/useDictation.ts#L168)). A ready sound added
   at that point would play into live capture. Either validate a playback-before-recording sequence or
   keep this transition silent.

Before promotion, perform blind A/B listening against the current set and silence on laptop speakers,
phone speakers, and headphones. Exercise first-play/lazy-load, a long wait, continuous speech, mic
permission/record/stop, rapid frame bursts, repeated no-op frames, dismiss/auto-dismiss, success,
failure, and autoplay refusal. A candidate advances only when the visual event and sound are perceived
as one event, speech stays intelligible, no cue fires audio-only, and the set remains tolerable over a
full rehearsal.

## Reproducibility and ownership

The removed room-bed mastering script was named `scratchpad/master.py` and was session-local
([voice.md](voice.md#L417)); its documented steps, not the missing scratchpad, are the durable record.
The server-side vendor key location is `api/.env.local` (name only; never copy it into the dashboard or
documentation). Existing sound-generation calls are build-time provenance, not a runtime dependency.
