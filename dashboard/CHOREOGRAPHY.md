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
| `focusView {panel:'parts'}` | raises the **shopping list** — every reference ALREADY chosen across the open checklist, grouped by the job it belongs to — as a **CENTRED surface** (scrim + `aria-modal`, groups **unfurl** staggered). The one intent that does NOT use the right projection column, and the exception is the point: the column is ~270px and these rows carry a reference, a brand, a description, a price and two shop links each, which at column width wrapped to unreadable stubs. It is a working document the operator reads across and acts on, not a glance-answer. Read-only, and deliberately **without a primary action**: these refs are already on their tasks, so an "Ajouter" would merge what is already there — the idempotent no-op the old "Pièces dispo · <service>" chip offered behind an orange primary. Each line carries **Mister-Auto** and **Auto-Doc** searches in the rationed operator hue (restored from 44cb4fb; a reference you cannot go and buy is one you retype into a search box). An unpriced reference reads `—`, never `0,00 €`: free and unknown must not look the same | scene overlay (`ShoppingListOverlay`) |
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

**A visit card is an object, not a bordered block** (2026-08-15, the visual pass). It wears the SAME
material as the projection surfaces on the right — the .86 dark ground, the hairline, the 13px radius,
the hair leader stubbing toward the car — so the two columns read as one instrument bracketing the
stage rather than a sidebar next to a canvas. What differs is the direction the leader points (right,
at the car) and one thing the right column has no business carrying: a **severity spine** down the
card's gutter, coloured by the visit's worst signal. The spine is the health signal, not decoration —
it is how *Maintenant* reads louder than *Prochaine visite* without a second font size (vibrancy tiers
+ the spine carry the hierarchy; the colour ration is unchanged).

| Real frame / condition | Atelier motion | Owner layer |
|---|---|---|
| a visit card enters the rail | rows **unfurl** staggered inside the card, same vocabulary as the worklist/carnet projections; the card itself arrives on `--at-dur-rise`. The rail **assembles** rather than appearing at once: cards stagger by index (capped at 4 steps, so the last card is never late) exactly like the ladder's rungs. The stagger delays PAINT only — no data waits on it, and nothing is gated (hard rule 1) | `EntretienRail` |
| the operator clicks a visit card's **head** | the earned camera **dollies to that visit's body region** (`visitRegions(visit)[0]` over the same `regionAnchors` a `focusRegion` uses) — the D1 ~900 ms sine ease, toggled off by clicking the same head again, by "vue libre", `Escape`, or a drag. **No cyan pulse**: the operator moved the camera, Le Noyau did not, and cyan is rationed to the agent. This is the one thing the ambient layer may do to the stage, and it is why the rail is part of the instrument instead of a list beside it — a card named « Moteur » can put the moteur on screen | `EntretienRail` + scene assembler (`cameraRig`) |
| the camera is held on a region | every visit card that reads off that region wears the **orange operator edge** and its hair leader lights orange toward the car. The tie between the card and the part is the held link, drawn. Static state on `--at-dur-tint`, never a loop — and it survives `prefers-reduced-motion` unchanged, because it is the information | `EntretienRail` |
| clicking **Plus tard · n** | the later visits project into the RIGHT column as a **time ladder** (`VisitProjection`): one rung per visit, and the vertical gap between rungs is **proportional to the real calendar gap**, so 2027→2028 sits close and 2028→2030 sits far. Distance encodes when — the ladder is the only place the horizon is legible as a shape rather than read as a date. Rungs unfurl staggered; dismiss clears like every other projection. Does **not** dim the stage (it is a side projection, per the rule above) | `VisitProjection` |
| a rail row carrying parts | the row shows a **count** (`3 réf.`), never the references. Opening it raises the row's own editor, where the lines are editable. A catalogue is a DECISION surface and the rail is a STATUS surface — three brands at three prices in the ambient layer is the summoned layer leaking into it | `EntretienRail` |
| a row is ticked off | the task goes `done` and **leaves every horizon** — "done is not work", the carnet is where a finished service belongs (`visits.ts`). But the whole row body is the hit target, so the tick is easy to make by accident, and the reopen control (`TaskRow`'s checkbox, "Rouvrir la tâche") lives on a row that is never rendered again. So the footer drawer holds **both** closed states, done and dismissed (`rail.ts` `closedTasks`), and every control in it puts the task back — except `×`, which keeps its own meaning and retires a done row. Compression is only honest if there is a way to look closer; that applies to a row the operator removed as much as to `Plus tard · n` | `EntretienRail` |
| nothing due at all | the rail collapses to **one line**. Seven rows saying "OK" is seven rows of cost for no information; the health ring already carries "fine" | `EntretienRail` |

**Why a count and not a scroll.** Compression is only honest if there is a way to look closer, so
`Plus tard` is a control, never a truncation — the ladder is that magnification (the "optical lenses that
shunt into place" read, `docs/design-inspiration.md` § 3). A collapsed bucket with no way to open it would
be hiding, which the truth-before-spectacle rule forbids as much as any fake motion.

**Earned camera (D1).** A region spotlight (a task focus, `focusRegion`, or clicking a rail visit
card's head — the ROW itself ticks the task off, so the head is the camera control)
**dollies the camera** to that region's anchor instead of dimming the stage — the move itself is the
attention cue, and the camera never drifts from the part it names (the same `regionAnchors` the glow
reads off), **from that region's own angle** (§ "The region pose (C2)" below — the framing is part of
the answer, not a uniform zoom). It fires only on explicit intent: never mid-drag (a request during a user drag queues until
release), and grabbing the camera always wins — it drops the hold outright. Eases over ~900ms sine
in/out; released by re-activating the SAME row (a toggle — "click again to go back"), the **"vue libre"**
button, `Escape`, or the drag itself. While a region is held, or the projection column is open, the
stage/cockpit/rail dim (`data-dim`) to cue that a projected answer is the current subject.

**Deterministic-first.** The quick-action chips above the composer resolve **client-side — no `postTurn`,
no tokens**: they replay the SAME choreography (`turn:start` → two `status` narration steps ≈ +950ms →
answer + `turn:end` ≈ +1900ms) and set the same `focus`/`cta` the agent would. Only the composer free-text
opens an LLM turn. The user sees no difference.

## The reveal — looking inside the car (C1–C4)

`CONFERENCE.md` § Workstream C. A focus dollies to the OUTSIDE of a closed shell, which is a polite
zoom and nothing more: the answer « quelle pièce je commande ? » is about a thing the operator cannot
see. The reveal is the surface for that, and it is the one place the stage stops drawing the car and
starts drawing a **diagram** — deliberately, because `public/mazda6-gh-web.glb` is ONE node, ONE mesh
(no hood, no door, no engine), so any claim to be showing that specific engine bay would be a lie the
geometry cannot back. Authored primitives in the region's anchor space say "circuit du filtre à
carburant"; they never say "your car's fuel filter, photographed".

| Real frame / condition | Atelier motion | Owner layer |
|---|---|---|
| `revealAssembly {region, partKeys?}` | the shell **ghosts**: the paint-tinted fill fades out and the blueprint edges dim to a floor, so the body reads as a wireframe you can see through — never to nothing, because the operator must keep the car's shape to know where they are. Under it, the region's **schematic assembly** rises in that region's anchor space, parts unfurling staggered (`--at-dur-fan`, the manifest/rail vocabulary, capped at 4 steps). The camera takes the region's **reveal pose** — steeper than its focus pose and further BACK, not closer: a reveal is the same car seen through, and cropping to the part would throw away the context that makes the diagram locatable | `VehicleMesh` (`GhostRunner`) + `Assembly` + `cameraRig` |
| a reveal whose parts have not all landed | **the ghost depth tracks how much of the assembly is on screen.** The shell dissolves as the parts arrive, so a slow catalogue lookup produces a slow dissolve rather than a finished dissolve held over an empty bay. The one thing that could have made C1 theatre — a fixed-length fade — is exactly what this removes: the duration is the fetch's | `VehicleMesh` + `Assembly` |
| a reveal is released (same controls as any hold: re-activating it, "vue libre", `Escape`, a drag) | ghost returns to 0 and the assembly sinks and clears on `--at-dur-swap`; the camera returns to the resting band. A drag during a reveal breaks it like any other hold — the operator grabbing the camera always wins | `VehicleMesh` + `Assembly` + `cameraRig` |
| `prefers-reduced-motion` | the ghost and the assembly are **state, not attention**: both cut instantly to their end value and stay there. Nothing is lost — a wireframe shell with a diagram under it is the information, and the dissolve was only ever the transition into it. The reveal dolly cuts, like every other dolly | all three |

**A reveal is a hold, not a mode.** It reuses `CameraFocus` with `mode:'reveal'`, so every rule the
earned camera already has — the intent gate, the mid-drag queue, the drag-breaks-hold, the burst
coalescer — applies unchanged and none of them was re-implemented. The only difference is which pose
family it reads.

C2 also planned to **widen the polar floor** for the reveal ("descend to a near-vertical over-the-bay
pose"). Built and then deleted, on the evidence: straight down through a *ghosted* shell superimposes
roof, floor and both flanks into one mat of lines, so the steepest angle is the worst one for a
wireframe, and at that radius the car overflowed the frame — costing the operator the one thing the
reveal is for, knowing where on the car they are looking. The pose that actually reads sits inside the
resting band, so the widening (and the machinery that had to widen it before the ease and give it back
only once the camera was home) guarded a case that does not exist. `cameraRig.test.ts` asserts it stays
inside the band.

### The region pose (C2) — the angle IS the region

`derivePreset` used to keep the HOME azimuth and the HOME polar for every region and change only the
target and the radius. Every dolly in the app therefore produced the same shot, and the framing
encoded nothing beyond "closer". A camera that cannot tell the hood from the rear axle is a camera
carrying no datum, which the Taste rule forbids as much as any decorative sweep.

So each region now owns a **view** (`REGION_VIEWS` in `cameraRig.ts`): an azimuth offset from home, an
absolute polar, and a radius factor. The hood is read from a raised front three-quarter, the bay
steeper still, the axles from near ground level swung toward the wheel they name. The offsets are
per-REGION and shared across body classes — the anchor position already carries the body-class
difference, and the angle you read a wheel from does not change because the car is a van.

The radius factor is per-region for the same reason: one flat `DOLLY_RADIUS_FACTOR` framed a brake
caliper and a whole engine bay identically, so the frame never said how big the thing was. It does now.

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

## Audition — one signal, and the reasons the others were cut

The Atelier makes **one** sound, and it is **off until the operator turns it on**. Not a bed, not a
palette of earcons, not a severity mapped onto a pitch. The reasoning is the same test the motion map
already applies — *name the datum, in one sentence* — with one addition the ear forces: **sound is for
the moment the operator is not looking at the screen.** Human-factors guidance is explicit that a
non-verbal audio signal's job is to be "supplementary or supportive, alerting and directing the user's
attention to the appropriate visual display" (FAA HFDS 2003 §7.1.1.12, from MIL-STD-1472F). Every frame
in the tables above is an answer to something the operator or the pilot just did, in front of a screen
they are watching. Sounding those is not instrumentation, it is applause.

Exactly one row in this whole document describes a change **nobody asked for**.

| Real frame / condition | The signal | Owner layer |
|---|---|---|
| **overdue crossing** — a domain `updated` that tips a service past due, hands-off (§ Domain frames) | **`échéance`** — two 180 ms pulses, ~1.2 s apart, once. A 660 Hz fundamental with a single odd partial at 1980 Hz at −14 dB, 15 ms attack, `setTargetAtTime` release; peak gain `0.14`, never normalised. Fires **at most once per sweep**, not once per service: a nightly forecast that tips five items is one event to the ear, exactly as a burst is one dolly to the camera. The datum: *something became late while you were under a car.* It carries no severity and no identity — the ember badge, the visit spine and the aria-live line carry those, and they were already there | Le Noyau (`audition.ts`) |

That row survives because of FAA HFDS §7.1.1.8: an alerting signal must give the user *a greater
probability of detecting the triggering condition than normal observation would provide in its absence*.
On an unattended workshop screen, a red hood-glow gives a probability of zero. Nothing else in the map
can make that claim.

### What does not sound, and why

| Candidate | Why it is silent |
|---|---|
| a continuous ambient bed | encodes no datum. It fails the one-sentence test outright, and it is the exact "overuse of sound" the guidance warns produces signals that "are ignored and can cause annoyance, interference, or confusion" (HFDS §7.2.1.8, discussion) |
| `turn {phase:'end'}` — the settle tone (ok/warn/alarm) | the operator is watching the thing that just settled. The done filament already pairs colour with a mono label and announces on the aria-live line; a tone repeats a value that is on screen at full precision. A needle that moves because a value moved is instrumentation; a needle that moves because the operator already saw the value is theatre |
| a write landing (`log_maintenance`, a `patchTask` merge) | the write produces the largest visual event the app has — the health arc animating old→new, the task striking and leaving the rail. It is also **bursty**: several frames per gesture. ISA-18.2 defines an alarm flood as more than ten alarms in ten minutes; a piloted burst of four intents in 300 ms would hit that on its own. Anything the burst window coalesces must not have a sound per member |
| the break of a long pilot silence (veille pilotée → under load) | it requires no response. EEMUA 191 / ISA-18.2 rationalisation is a single question — *does this need the operator to do something?* — and "the pilot resumed" does not. The frame's own motion is the cue, as the pilot table already says. Its steady-state budget is also wrong: fewer than one signal per ten minutes per operator position is the target, and a chatty session breaks that in a minute |
| severity mapped parametrically onto the tone | this is the documented failure of IEC 60601-1-8, whose eight high-priority alarms share one acoustic structure and one rhythm and differ **only** in pitch pattern. They are hard to learn, hard to discriminate, their urgency is misperceived, and nurses with a year of musical training identify them far more accurately than those without — a set that only musicians can read is not a set. The audio twin of **never colour-only** is **never pitch-only**: severity is read off the spine and the ember, in grayscale, on a still screen |
| speech ("échéance dépassée") | genuinely better on the evidence — auditory icons and speech beat abstract earcons on identification speed, accuracy and learnability (McNeer et al. 2018; Edworthy et al. 2014, 2017) — and rejected anyway: `SpeechSynthesis` voices are OS-dependent, French quality is a lottery, bundling a voice breaks the self-contained page, and a talking dashboard over a conference PA is a catastrophe |
| recorded auditory icons (a real ratchet, a real chime) | the same evidence favours them, and the same constraint forbids them: no external CDN assets, the page stays self-contained. Synthesised oscillators cannot make a credible auditory icon, so the one signal is honestly an abstract earcon and is kept to **one** for that reason |

### Binding rules

- **Nothing is audio-only.** Every sound has a visual that defines the condition and an aria-live
  announcement — the mirror of *never colour-only*, and the letter of HFDS §7.1.1.11 ("all nonverbal
  audio signals shall be accompanied by a visual signal that defines the condition"). Mute the app and
  no value is lost; the test is the same one the WebGL canvas already passes.
- **One signal, and it is a caution, not a warning.** A late oil change is a "condition requiring
  attention but not immediate action" (MIL-STD-411 via HFDS §7.1.2.7). So: fires once, never loops,
  never escalates in loudness, never persists until acknowledged. A signal that nags trains the operator
  to kill the feature, and then the one real crossing is silent too. 85–99 % of clinical alarms need no
  intervention (Joint Commission SEA 50, 2013) — that is what a product earns by sounding everything.
- **Mute is a first path, and silence is the default.** `prefers-reduced-motion` is a first path because
  the OS *tells us*. There is no audio twin — Media Queries Level 5 has no `prefers-reduced-sound` — so
  the OS tells us nothing, and a preference we cannot read must not be guessed loud. Ships muted;
  one persisted toggle (`mpa.audition`, beside `mpa.recency`), reachable from the à-décider inbox where
  the concern lands anyway. A caution signal shall have manual reset and volume control
  (MIL-STD-1472F §7.2.9.11); WCAG 1.4.2 exempts audio under three seconds, which this is, but the
  affordance is not optional for that reason.
- **Room-proof, not desk-proof.** 660/1980 Hz sits inside the 500–3000 Hz band the guidance prefers
  (HFDS §7.2.7.2, range 200–5000) and must clear a bay with a compressor in it — two pulses rather than
  one beep, because a single short beep in a garage is lost. It must **not** resemble a sound the room
  already makes (HFDS §7.2.3.4): no click, no impact, no phone-notification shape.
- **No abrupt gain changes, ever.** A step in gain is a discontinuity and the discontinuity is the
  click. Attack over 15 ms, release with `setTargetAtTime` — `exponentialRampToValueAtTime` cannot
  reach 0 and throws on it, which is how a "why does the fade-out pop" afternoon starts.
- **Schedule against the audio clock, never `setTimeout`.** The two pulses are placed at
  `ctx.currentTime + n` and let the hardware fire them; the JS timer is jittered by layout and GC
  ("A Tale of Two Clocks"). This is the same rule as *cheap idle* seen from the audio side — one
  scheduling call per event, no polling loop, no `AudioContext` left running to burn a core on a screen
  that has been quiet for six hours. Suspend after the tail, resume on the next signal.
- **Constants live in code, not CSS.** `src/tokens/audition.ts` beside `motion.ts`, same discipline:
  frequencies, gains and envelope times are named, and a component may not spell one out. The
  synthesiser is pure (nodes in, schedule out) and unit-tested against a stub context — no test sleeps
  and none plays a sound.

### The AudioContext trap

**A piloted session produces no user gesture in the browser.** Frames arrive over Mercure from an MCP
call made on another machine; the tab is never clicked. An `AudioContext` constructed before the
document has received a gesture is created **suspended**, and every note scheduled on it is silently
dropped — no error, no console warning, nothing to debug. It only ever resumes from inside a real
gesture handler.

So the unlock is **stage setup, not a code path**: the context is created and `resume()`d from the
first genuine interaction the page receives (the operator opening the app, accepting the cert, picking
a vehicle), and that single unlock holds for the tab's lifetime. It must then be **asserted**, not
assumed — `mpa stage check` (workstream B) adds one line: `ctx.state === 'running'`, alongside the
pilot-topic match. Chrome gates this on a per-profile Media Engagement Index, so the behaviour is
*machine-dependent*: it can work on the dev laptop and fail on the stage laptop with identical code.
That is precisely the class of failure the preflight exists to catch before the room fills.

### Not in the talk

The stage profile ships **muted**, and `mpa stage check` asserts that too. The act is a presenter
speaking over a PA while the UI answers in 3D — the visual *is* the payload, and WCAG 1.4.2's own
rationale for the audio-control requirement is that concurrent audio makes speech hard to hear.
A tone competing with the lapel mic is a regression in the demo's own terms. Worse, it is
unrehearsable: laptop→projector over HDMI routes audio to a projector speaker that is usually
disabled, and a conference PA generally carries the mic and nothing else. Workstream E rehearses every
failure with its recovery; an audio path that may not reach the room at all cannot be rehearsed, and
should not be added six weeks out. Three beats in ten minutes is also not enough exposure to learn a
signal — retention needs regular presentation (HFDS §7.2.2.1, discussion, after Patterson 1982 and
Stanton & Edworthy 1994), which the workshop screen has and the stage does not.

### Headroom, if a second signal is ever wanted

The ceiling is low and it is worth writing down before someone spends it. Absolute identification of
non-verbal audio signals should not exceed **four** (HFDS §7.2.2.1, from MIL-STD-1472F); 4–7 are
acquired reasonably quickly and performance falls off sharply beyond, with up to nine retained only
under regular exposure. This product has spent **one**. A second must clear the same bar the first did
— a change the operator did not cause, on a screen they are not watching, that they must act on — and
it must be discriminable by **timbre and rhythm**, not by pitch alone, for the IEC reason above. Nothing
currently in the frame vocabulary qualifies.

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
