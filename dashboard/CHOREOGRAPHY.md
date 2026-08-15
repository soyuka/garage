# Atelier choreography map — FROZEN in Stage B.0

The single binding of the **real** agent vocabulary (and Mercure domain frames) to Atelier motion.
Written in the verified `UIAction` / frame names only — never the `docs/ui.md` sketch names. Every
Stage-B/C component animates against THIS map; no agent invents a different mapping.

## Agent → UI intents (session topic)

| Real frame | Atelier motion | Owner layer |
|---|---|---|
| `selectVehicle {id}` | the car **drives forward** into Le Plateau; previous car recedes into the lot (~350ms cross-fade) | scene assembler |
| `showFact {keys, title?}` / `focusView {panel:'specs', keys?}` | the **spec constellation** fans the matching `vehicle.facts` into the RIGHT **projection column** (staggered **etch-in**). Empty `keys` ⇒ the whole sheet | scene overlay (`SpecConstellation`) |
| `showFact {nodes, title?}` | the SAME constellation, same etch-in — but the nodes ARE the content: an already-formatted surface the agent FETCHED (fill volumes, an oil grade, the SIV carte grise), which no `keys` filter over stored facts could ever select. Read-only (no inline edit — a fetched value is not the operator's), and it wears the same agent-placed cyan tick as any projection. Not a new motion: the identical surface the "Capacités fluides" / "Fiche technique" chips already project, now reachable **on the wire** | scene overlay (`SpecConstellation`) |
| `focusView {panel:'tasks', keys}` | projects the **worklist** — the agent's cut of the rail — into the RIGHT projection column (rows **unfurl** staggered, same vocabulary as the carnet), AND resolves the first key to a body region (`regionForFrame`) to **spotlight it by dollying the earned camera there** (D1) + a one-shot cyan pulse on the region's edges. The spotlighted services' Entretien-rail row(s) light with the orange operator cue (`data-lit`: a 2px left edge in the gutter + a lift), so the projected answer and the editable rail name visibly the same rows. **Never dims the stage** — like the specs/carnet projections it reads as integrated, not blocking | scene overlay (`WorklistProjection`) + scene assembler (`cameraFocus`) + `EntretienRail` |
| `focusRegion {region}` | same earned-camera dolly + pulse as above, addressed directly by region. **Client-synthesized today** (a contract seed — the client already dollies for a `focusView`/`showFact` that resolves to a region, so handling both is idempotent; not yet emitted by the live agent) | scene assembler (`cameraRig`) |
| `showParts {service, taskId, parts?}` | projects the **parts manifest** into the RIGHT projection column (rows **unfurl** staggered), primary **Ajouter** = a deterministic `patchTask` merge. **Client-synthesized today** by the "Pièces dispo" wayfinder chip — the identical shape a real parts-bearing `proposeActions` produces (see below), so chip and agent answer are visually indistinguishable | scene overlay (`PartsManifest`) |
| `showHistory` / `focusView {panel:'carnet'}` | raises the **carnet projection** (past visits + spend) into the same RIGHT column. The `showHistory` FRAME stays client-synthesized (the "Carnet · dépenses" chip), but the SURFACE is now a real server emission: the agent raises it with `focusView {panel:'carnet'}` after reading the history, instead of degrading to a prose toast. Both funnel through `focusForAction` into one focus state, so chip and agent answer are indistinguishable | scene overlay (`CarnetProjection`) |
| `proposeActions {headline, finding?, actions}` | a plain proposal → a CTA card **rises from Le Noyau's cradle** (behind the composer, never over it); a **parts-bearing** proposal (an `actions[].patchTask` with `parts`) projects instead as the **parts manifest** in the RIGHT column with the primary **Ajouter** = that deterministic PATCH. `patchTask` → **no agent turn**; `instruction` → new turn. A proposal that arrives while one is already pending, or with no hero open (nowhere to raise a cradle), is **deferred into the à-décider queue** instead of dropped (`review` item — replays the same actions later) | CTA layer / `PartsManifest` / à-décider inbox |
| `toast {tone, message}` | non-alarm → narration line (Noyau heartbeat); **alarm** → docks a `notify` item onto the à-décider queue + a real toast popup | Le Noyau |
| `status {message}` | one **Noyau pulse** (per narrated step, sparse) + the narration line etches; the same step also appends an **aria-hidden tool-step chip** (short verb + glyph) beside Le Noyau, collapsing into the journal on turn end — a silent companion, never a second announcement | Le Noyau |
| `turn {phase}` | `start` → Noyau busy/working (aperture focuses, scan locks) + a **cyan scan-sweep** slides across the car; `end` → settle → green/amber/red | Le Noyau + scene overlay |

`focusView` addresses the whole `PanelKind` — `specs`, `tasks` **and** `carnet` (CONTRACT.md). A panel the
type allows but the wire cannot reach is a render surface the agent has no words for, and the answer
degrades to prose; the vocabulary and the type stay the same size on purpose.

## The pilot driver — same verbs, different envelope

The dashboard has TWO drivers (docs/pilot.md): the in-app turn, and an external MCP host calling the
same render intents. **The frames are identical, so the MOTIONS are identical** — the table above is
the whole vocabulary for both, and no verb means one thing under one driver and another under the
other. A distinct dialect would be decoration: the operator would have two grammars to learn for one
set of frames, and the difference would encode nothing about the car.

What DOES differ is the run's **envelope**, because the data differs. An in-app turn is bracketed
(`turn:start` … `turn:end`), bounded (4–20 s) and cancellable, and a silent worker is a fault. A pilot
run declares no start, has no length, cannot be cancelled from here, and its silence is a person
thinking. So the envelope gets one state a turn cannot reach, and one nameplate:

| Real frame / condition | Atelier motion | Owner layer |
|---|---|---|
| first `status` at rest, arriving on the pilot topic | **the run opens with no `turn:start`** — the orb lifts and works exactly as it does for a turn (the reducer already treats a status at rest as the start). Nothing new to learn | Le Noyau (`state.ts`) |
| any frame on the pilot topic | the console wears a persistent **`PILOTE` nameplate** beside the narration, tier-3, mono, uppercase. NOT an animation: it has to be true of a still screen, because the panels in front of the operator were placed by someone who is not them. Announced once on the aria-live line, then silent (the tool-step-chip rule) | Le Noyau (`NoyauVoice`) |
| no pilot frame for `PILOT_QUIET_MS` (4 s) | **veille pilotée** — the run is still open, so the orb stays LIFTED and the aperture stays the working point, but the instrument stops claiming to read: the exertion gradient cools amber→cyan (amber = under load), the reticle drops from its 2 s lock to the resting 18 s drift, and the pupil resumes the protected ~4 s breathe. Beside the narration, a live **elapsed-silence** read (`· en attente · 2 min`) — the datum the state exists to show, because a breathing orb cannot say "two minutes". The composer UNLOCKS: a run that may hold for minutes must not hold the dashboard hostage, and typing takes the controls back | Le Noyau + the console |
| any pilot frame after that | quiet clears, the instrument goes back under load. No animation of its own — the frame's own motion is the cue | Le Noyau |
| `turn {phase:'end'}` from `settle_dashboard` | the existing done filament, unchanged | Le Noyau |
| liveness | **a pilot run never trips the 90 s watchdog.** "Le tour ne répond plus" is a claim the client cannot support here — it cannot tell a host reading code from a host that quit — so it says how long it has been silent instead and lets the operator judge. Only `settle_dashboard` (or the operator taking over) ends a pilot run | Atelier |

**Burstiness (both drivers, pilot-shaped).** A pilot narrates, goes quiet for two minutes, then fires
four intents in 300 ms. Two rules keep that legible, and both are pure + unit-tested (`src/pilot.ts`):

- **One dolly per gesture.** Region targets landing inside one 120 ms window coalesce to the LAST one,
  so a burst dollies once — the operator is shown where the attention landed, not the route it took.
  Uncoalesced, each intent re-eased the camera from wherever the previous had reached: a camera
  whipping between regions, arriving nowhere, encoding nothing. Only the dolly waits for the window;
  the panels those same frames open are not on that path (see hard rule 1 below).
- **An answer lands on the car it is about.** A surface-placing intent (`showFact`, `focusView`,
  `showParts`, `showHistory`, `focusRegion`, `proposeActions`) arriving while a `selectVehicle` is
  still driving a car in is HELD, and replayed in order the moment that car is on screen. Heartbeat
  frames (`status`, `toast`, `turn`) are never held — they are what says why the stage is moving. A
  second `selectVehicle` supersedes and drops what was held (it was about the other car); a car that
  never arrives drops it after 6 s **with a warn toast**, never silently.

## Motion scale

Durations are tokens, one step per INTENT: `--at-dur-tint` (140 ms, a control tints) · `--at-dur-swap`
(240 ms, a surface dims/slides/cross-fades) · `--at-dur-rise` (280 ms, a surface arrives) ·
`--at-dur-etch` (360 ms, a line etches / a CTA rises) · `--at-dur-fan` (480 ms, a set fans in) ·
`--at-dur-filament` (600 ms, the done colour) · `--at-dur-cascade` (800 ms, the data cascade) — plus
the protected `--at-breathe` (4 s). `src/tokens/motion.ts` is the twin for the motions CSS cannot
reach (the 900 ms dolly, the 1.4 s pulse, the burst window) and for the DWELLs (settle hold, watchdog).
`motion.test.ts` fails if the two drift, **and if any component stylesheet spells a one-shot duration
out instead of using a step**. A loop PERIOD is a frequency, not a duration — exempt.

## Le Noyau's à-décider queue (ember)

Hands-off concerns (the §9 overdue crossing), alarm toasts, and superseded/homeless `proposeActions`
proposals never vanish — they queue on the ember (`notify`/`review` `DecisionItem`s; a `question` kind is
reserved, not yet produced). The badge shows the live queue length. Opening the inbox **mutes** the badge
(`emberSeen`) but does **not** drain the queue — only resolving or dismissing every row does, so a closed-
without-resolving inbox re-opens with everything still there. Rows resolve deterministically (a review
row replays its `patchTask`/`instruction`; a notify row offers "vu" or "planifier ○") — never a transcript.

## Data around the turning car (option 2a)

The car IS the interface — no docked slot. Data is either **true of a body region** (échéances — the
persistent LEFT "Entretien" rail, grouped by VISIT, see below; the disc itself stays clean —
the ring pins were removed, indices read as a classification they weren't) or **fetched on
demand** (specs / parts / carnet / later visits, which project into the RIGHT **projection column** — one
right-edge slot so the operator learns a single glance direction for "the agent answered" — and clear on
dismiss).

### The rail is grouped by VISIT, not by service (2026-08-15)

A garage books **trips to the lift**, not services. The log side always knew this — a `MaintenanceRecord`
is one visit carrying `items[]` — while the plan side listed one row per service, so a vidange and its
oil filter on the same date read as two obligations and seven planned services read as seven. `visits.ts`
is the mirror, pure data over `buildRail`, with two grouping axes because undated and dated work are
scheduled by different things:

| Bucket | What it holds | Grouped by |
|---|---|---|
| **Maintenant** | late work + decided-but-undated open tasks | **body region** — what shares a teardown |
| **Prochaine visite** | the soonest dated cluster | **calendar** — within `VISIT_WINDOW_DAYS` (60), chained off the previous member, not the cluster start |
| **Plus tard · n** | every later cluster | a COUNT, never a list |

| Real frame / condition | Atelier motion | Owner layer |
|---|---|---|
| a visit card enters the rail | rows **unfurl** staggered inside the card, same vocabulary as the worklist/carnet projections; the card itself arrives on `--at-dur-rise` | `EntretienRail` |
| clicking **Plus tard · n** | the later visits project into the RIGHT column as a **time ladder** (`VisitProjection`): one rung per visit, and the vertical gap between rungs is **proportional to the real calendar gap**, so 2027→2028 sits close and 2028→2030 sits far. Distance encodes when — the ladder is the only place the horizon is legible as a shape rather than read as a date. Rungs unfurl staggered; dismiss clears like every other projection. Does **not** dim the stage (it is a side projection, per the rule above) | `VisitProjection` |
| a rail row carrying parts | the row shows a **count** (`3 réf.`), never the references. Opening it raises the row's own editor, where the lines are editable. A catalogue is a DECISION surface and the rail is a STATUS surface — three brands at three prices in the ambient layer is the summoned layer leaking into it | `EntretienRail` |
| nothing due at all | the rail collapses to **one line**. Seven rows saying "OK" is seven rows of cost for no information; the health ring already carries "fine" | `EntretienRail` |

**Why a count and not a scroll.** Compression is only honest if there is a way to look closer, so
`Plus tard` is a control, never a truncation — the ladder is that magnification (the "optical lenses that
shunt into place" read, `docs/design-inspiration.md` § 3). A collapsed bucket with no way to open it would
be hiding, which the truth-before-spectacle rule forbids as much as any fake motion.

**Earned camera (D1).** A region spotlight (a task focus, `focusRegion`, or clicking a rail row)
**dollies the camera** to that region's anchor instead of dimming the stage — the move itself is the
attention cue, and the camera never drifts from the part it names (the same `regionAnchors` the glow
reads off). It fires only on explicit intent: never mid-drag (a request during a user drag queues until
release), and grabbing the camera always wins — it drops the hold outright. Eases over ~900ms sine
in/out; released by re-activating the SAME row (a toggle — "click again to go back"), the **"vue libre"**
button, `Escape`, or the drag itself. While a region is held, or the projection column is open, the
stage/cockpit/rail dim (`data-dim`) to cue that a projected answer is the current subject.

**Deterministic-first.** The quick-action chips above the composer resolve **client-side — no `postTurn`,
no tokens**: they replay the SAME choreography (`turn:start` → two `status` narration steps ≈ +950ms →
answer + `turn:end` ≈ +1900ms) and set the same `focus`/`cta` the agent would. Only the composer free-text
opens an LLM turn. The user sees no difference.

## Stage look

Le Plateau has one look — there is no mode/variant to pick. The floor is always the procedural
cutting-mat workbench (`CuttingMat.tsx`, always mounted): a lit, graphite/blue-grey surface, **never
green** — green is reserved for the health signal (PRODUCT.md "light is meaning"), so the floor material
can never be mistaken for a status read. The car itself keeps its original blueprint look everywhere it
renders — the rung-b proxy (`CarProxy.tsx`), the rung-a real `.glb` (`blueprintBuild.ts`), and the
parc-grid spinning thumbnails (`ParcThumb`/`ParcLot.tsx`): a faint paint-tinted unlit night-glass fill
under glowing HDR-boosted electric-blue edges. Blueprint edges, the holo scan/pulse verbs, and the
podium's rim/glow all carry the signals this map defines; the rim/podium additive glow is tuned to read
as a reflection on the lit mat floor rather than fog in a void (`Plateau.tsx`'s `PODIUM_INTENSITY`
constant, 0.55) — that tuning is the only thing the mat floor changed.

## Domain frames (collection topic)

| Frame | Atelier motion | Owner layer |
|---|---|---|
| `{kind:'vehicle', event, resource}` | **the data cascade** (~800ms): health bars animate old→new with the glow trailing; KPI numbers count; hotspots cross-fade color (400ms); the rim sweeps the delta arc. Identical for local + other-tab events — the room is shared | scene assembler |
| overdue crossing (a domain `updated` that tips a service past due) | hood-glow breathes red + rim arc ignites + ember docks to the Noyau, **hands-off** — the §9 wow | scene assembler + Le Noyau |

## Priority & honesty rules (binding)

- **All panel animations YIELD to Le Noyau's narration.** While a turn is active, panel transitions
  queue, then run in sequence (~50ms apart) when the Noyau settles. No "everything moves at once."
- **Z-order:** WebGL canvas (back) → glass panels → composer tray → **Le Noyau (highest)**.
- **Nothing teleports** — selection drives, facts etch, CTAs rise, colors cross-fade, the earned camera
  dollies to a focused region (never a cut).
- **Truth before spectacle:** every value a motion conveys (glow, rim arc, hotspot card) MUST also be
  in the truth-panel / flat plan list at full precision. Hide the WebGL canvas → no value lost.
- **`prefers-reduced-motion`:** drives/etches/pulses/cascade collapse to instant state changes; the
  overdue wow becomes a static red state + the narration line; the earned-camera dolly becomes an
  instant cut (and "vue libre" an instant return). **Veille pilotée** loses its breath the same way and
  keeps everything that carries the information: the lifted orb, the cooled cyan, the `PILOTE`
  nameplate and the elapsed-silence read are all static state, so nothing is lost with motion off.
- **The pilot's silence is read, not animated.** The elapsed counter is a leaf with its own interval:
  one second's tick re-renders that text node and nothing else (measured: 4 DOM mutations over 4 s of
  veille pilotée, all `characterData` on `.noyau-wait-clock`). Cheap idle is a hard rule, and a state
  that can last minutes is exactly where it would have been broken.
- **Tool-step chips never double-announce.** They are an `aria-hidden` companion to Le Noyau's
  `aria-live` narration line — the narration is the only thing a screen reader hears per step; the
  chips are a sighted-only quiet trace, collapsing into the journal on turn end.
- **Vibrancy tiers.** HUD hierarchy over the car is expressed as **opacity tiers** (`--at-vib-1/2/3` in
  tokens/atelier.css — 1 / .72 / .5), not another font-size jump: the same visionOS-style vocabulary as
  the colour-ration rule (cyan/orange are rationed by ROLE; vibrancy tiers ration EMPHASIS). A surface
  keeps mono text at one constant size; primary/secondary/tertiary read off tier + weight instead.
- **Scrim law.** Any DOM text container painted above the WebGL canvas (cockpit band, échéance cards,
  constellation nodes, parts manifest, CTA card, composer) MUST sit on a fill of **>= .86 opacity** dark
  ground (glass panels: `--at-glass-fill`) OR gain a local radial scrim behind it (cockpit band — no
  card, so a fixed-size radial gradient in the `--at-void` family stands in). A light translucent fill
  over the rotating, bloomed car is unreadable — never ship one under .86 without a scrim.
