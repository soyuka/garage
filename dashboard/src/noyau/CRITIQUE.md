# Stage A — Le Noyau · 3-lens adversarial critique (the mandatory critique panel)

> **Form update (post-critique):** the owner chose the Noyau form = a **diagnostic instrument**
> (machined lens/iris/gauge that reads the car) over the abstract orb. The visual layer was reworked
> (faceted sphere → iris); **every motion + a11y fix below was carried forward** onto the iris (the
> "fault facet" became a reticle fault-flash; the reduced-motion shape cue is now the aperture size,
> which also dilates on listening / contracts on working). The frame→state machine, choreography, and
> the fidelity-lens verdict are unchanged — the rework was presentation-only.

Three independent reviewers, each defaulting to REFUTE and required to cite a concrete vision §,
CONTRACT.md rule, or `file:line`. Run via the `noyau-stage-a-critique` workflow.

| Lens | Verdict (initial) | After fixes |
|---|---|---|
| Motion quality | **concerns** | resolved (majors fixed) |
| Reduced-motion & a11y | **FAIL** (2 blockers) | resolved |
| Frame-shape / contract fidelity | **pass** | pass (2 doc notes addressed) |

## Fixed (blockers + majors + cheap minors)

- **[a11y blocker] Result was color-only / never text.** Turn-end now sets a French mono label
  (`posé · tout est à jour` / `· un service à prévoir` / `· anomalie détectée`) on the aria-live
  narration line, and the journal done row renders the signal word (VERT/AMBRE/ROUGE). Green/amber/red
  are now distinguishable in grayscale and announced to screen readers. (state.ts, Noyau.tsx)
- **[a11y major] Journal done glyph color-only** → signal token rendered per row.
- **[a11y major] Reduced-motion working state was color-only** → the +12px lift is kept as a STATIC
  raised position (shape cue, no motion) under both the media query and the manual toggle.
- **[motion major] Color teleport** → the filament/emissive/seam color drivers are now registered
  `@property <color>` vars with a 500–600ms transition, so cyan→amber *ignites* and the done color
  *cools in* rather than snapping.
- **[motion major] Listening glow not steady** → filament holds steady on listening; only the
  orb-scale breathe quickens.
- **[motion major] Listening ring didn't trace** → the inner ring now sweeps via animated
  `stroke-dashoffset`.
- **[motion major] Missing fault lamp** → on a red done, one facet flares red (`nx-fault`).
- **[motion minor] Seams never brighten** → `--nx-seam` rebinds brighter per working/done phase.
- **[motion minor] No idle specular glint** → a slow highlight crosses the facets on idle.
- **[fidelity minor] §1 pulse-per-tool-call drift** → CONTRACT.md now states the per-narrated-step
  rule supersedes the stale vision text.
- **[fidelity nit] `worstTone` lingered between turns** → cleared on settle (matters for the §9
  overdue push, which has no turn frame). Covered by a new test.
- **[motion nit] Idle rotation comment** corrected to ~9°/s.
- **[a11y nit] Pulse-ring DOM churn under reduced motion** → ring creation short-circuited when reduced.
- **[a11y minor] Orb exposed no phase to AT** → aria-label now carries phase + result; aria-expanded
  on the orb/journal toggle.

## Deferred (logged, not blocking — stylistic nits)

- **Narration exit animation** (minor): the ticker animates its entrance but the outgoing line is
  not faded up-and-out. Acceptable for v1; revisit with a crossfade/view-transition.
- **Pulse ring origin** (nit): rings expand concentrically from center rather than "off the equator."
  Accepted as stylization for v1.

## Strengths the panel confirmed

Apple sine-breathe faithful (cubic-bezier(0.37,0,0.63,1), 4s, 100→103%); 12px lift + damped settle
correct; **pulse is per-status, sparse — never per tool-call** (contract-correct); done color derived
honestly from the worst toast tone (no invented turn-end field); frame shapes match types.ts/CONTRACT
field-for-field so Stage C is a genuine transport swap; reduced-motion covered on both OS + manual
paths; decorative SVG aria-hidden, ember/narration are aria-live.
