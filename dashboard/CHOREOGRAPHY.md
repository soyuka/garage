# Atelier choreography map — the authority, and revisable

The single binding of the **real** agent vocabulary (and Mercure domain frames) to Atelier motion.
Written in the verified `UIAction` / frame names only — never the `docs/ui.md` sketch names. Every
Stage-B/C component animates against THIS map; no agent invents a different mapping.

**Unfrozen 2026-09-04.** This file was "FROZEN in Stage B.0 / FROZEN-but-extensible", which bundled
three rules together. Two of them stand and are not negotiable:

1. **It is the authority.** No component animates against a mapping that is not written here, and a
   new motion gets its row *before* it is written.
2. **Verified vocabulary only.** Real `UIAction` / frame names, never a sketch name.

The third — *existing rows may only be appended to, never rewritten* — is dropped, because it was
producing contradictions instead of preventing them. The speech row read "rejected" for weeks after
speech shipped, and `docs/voice-plan.md` recorded the workaround in as many words: *"the file is
FROZEN-but-extensible, so the row is added, not rewritten"* — leaving two opposed claims about the
same behaviour in one document. A map that cannot be corrected stops describing the thing it maps.

So: **correct a row when it goes stale, and date the correction.** That is already the habit
everywhere below ("added 2026-08-17", "Corrected 2026-08-17", "Decided 2026-08-17") — the practice had
outgrown the label. What a rewrite must never do is quietly erase a decision: if a rule is being
reversed rather than clarified, say what it was and why it changed, so the reasoning survives.

## Agent → UI intents (session topic)

| Real frame | Atelier motion | Owner layer |
|---|---|---|
| `selectVehicle {id}` | the car **drives forward** into Le Plateau; previous car recedes into the lot (~350ms cross-fade). **Corrected 2026-09-05:** built as § "One stage — the lot and the podium": the picked card's ghost drives to the stage centre over `--at-dur-etch` (there is no 350 ms token) while the lot recedes, and the hero car etches in on the podium; the hash changes at the click, never after the motion | `Atelier.tsx` (`Departure`) + `holoFill.ts` |
| `showFact {keys, title?}` / `focusView {panel:'specs', keys?}` | the **spec constellation** fans the matching `vehicle.facts` into the RIGHT **projection column** (staggered **etch-in**). Empty `keys` ⇒ the whole sheet | scene overlay (`SpecConstellation`) |
| `showFact {nodes, title?}` | the SAME constellation, same etch-in — but the nodes ARE the content: an already-formatted surface the agent FETCHED (fill volumes, an oil grade, the SIV carte grise), which no `keys` filter over stored facts could ever select. Read-only (no inline edit — a fetched value is not the operator's), and it wears the same agent-placed cyan tick as any projection. Not a new motion: the identical surface the "Capacités fluides" / "Fiche technique" chips already project, now reachable **on the wire** | scene overlay (`SpecConstellation`) |
| `focusView {panel:'tasks', keys}` | projects the **worklist** — the agent's cut of the rail — into the RIGHT projection column (rows **unfurl** staggered, same vocabulary as the carnet), AND resolves the first key to a body region (`regionForFrame`) to **spotlight it by dollying the earned camera there** (D1) + a one-shot cyan pulse on the region's edges. The spotlighted services' Entretien-rail row(s) light with the orange operator cue (`data-lit`: a 2px left edge in the gutter + a lift), so the projected answer and the editable rail name visibly the same rows. **Never dims the stage** — like the specs/carnet projections it reads as integrated, not blocking | scene overlay (`WorklistProjection`) + scene assembler (`cameraFocus`) + `EntretienRail` |
| `focusRegion {region}` | same earned-camera dolly + pulse as above, addressed directly by region. **Client-synthesized today** (a contract seed — the client already dollies for a `focusView`/`showFact` that resolves to a region, so handling both is idempotent; not yet emitted by the live agent) | scene assembler (`cameraRig`) |
| `showParts {service, taskId, parts?}` | projects the **parts manifest** into the RIGHT projection column (rows **unfurl** staggered), primary **Ajouter** = a deterministic `patchTask` merge. **Corrected 2026-09-12:** if every proposed reference already exists on that target task (trimmed, case-exact reference comparison), it contracts to its heading/finding: no duplicate list and no idempotent **Ajouter** control. **Client-synthesized today** by the "Pièces dispo" wayfinder chip — the identical shape a real parts-bearing `proposeActions` produces (see below), so chip and agent answer are visually indistinguishable | scene overlay (`PartsManifest`) |
| `showHistory` / `focusView {panel:'carnet'}` | raises the **carnet projection** (past visits + spend) into the same RIGHT column. The `showHistory` FRAME stays client-synthesized (the "Carnet · dépenses" chip), but the SURFACE is now a real server emission: the agent raises it with `focusView {panel:'carnet'}` after reading the history, instead of degrading to a prose toast. Both funnel through `focusForAction` into one focus state, so chip and agent answer are indistinguishable | scene overlay (`CarnetProjection`) |
| `focusView {panel:'parts'}` | raises the **shopping list** — every reference ALREADY chosen across the open checklist, grouped by the job it belongs to — as a **CENTRED surface** (scrim + `aria-modal`, groups **unfurl** staggered). The one intent that does NOT use the right projection column, and the exception is the point: the column is ~270px and these rows carry a reference, a brand, a description, a price and two shop links each, which at column width wrapped to unreadable stubs. It is a working document the operator reads across and acts on, not a glance-answer. Read-only, and deliberately **without a primary action**: these refs are already on their tasks, so an "Ajouter" would merge what is already there — the idempotent no-op the old "Pièces dispo · <service>" chip offered behind an orange primary. Each line carries **Mister-Auto** and **Auto-Doc** searches in the rationed operator hue (restored from 44cb4fb; a reference you cannot go and buy is one you retype into a search box). An unpriced reference reads `—`, never `0,00 €`: free and unknown must not look the same | scene overlay (`ShoppingListOverlay`) |
| `focusView {panel:'docs'}` (added 2026-10-02) | raises the **documentation** — the vehicle's paper, grouped in a fixed order: **Devis** (a quote linked to an OPEN planned task names that task), **Manuels**, **Notes techniques**, **Factures** (the bills already riding the visits — the same attachments Le Dossier shows, not a second fetch), **Autres** — as a **CENTRED surface**, the shopping list's own vocabulary reused verbatim: scrim fades in over `--at-dur-swap`, the glass etches in over `--at-dur-rise`, groups **unfurl** staggered (≤ 5 steps × 70 ms). No new motion. Centred for the shopping list's reason: each row carries a title, a source reference, a page count or size, an **Ouvrir** link and a delete, and the surface ends in an upload form — a working document, not a glance-answer, and at column width it would wrap to stubs. It has no station, so the scrim covers nothing the disc is saying. The operator ACTS here (open, delete, add), so those controls wear the rationed operator hue, like the shop links; the header wears the same placed-by-Le-Noyau tick the shopping list does (chip or agent — both are Le Noyau answering). Reachable by the « Documentation » wayfinder chip too (always shown: an empty file is still where you add the first document). **Silent** under audition, like every other panel (§ Audition, the Data-surface row). A document write re-reads the bundle on the same vehicle domain frame bills ride, so the list settles in place — no re-entry animation. Reduced motion: appears, no unfurl | scene overlay (`DocumentationPanel`) |
| `showDocument {url, title, contentType, filename, page?, highlight?}` (added 2026-10-05) — and « Ouvrir » on a documentation row / a bill chip in Le Dossier | opens the **document viewer** — the document itself, read in place instead of a new browser tab — as a **CENTRED surface** in the shopping list's / documentation panel's vocabulary reused verbatim: scrim fades in over `--at-dur-swap`, the glass etches in over `--at-dur-rise`. **No new motion**: pages are not animated in (a page's canvas appears when it has rendered, lazily as it nears the viewport), a jump to `page` (the agent's search hit, or the operator's page field / prev-next / PageUp-PageDown) is an **instant** scroll, never a smooth one, and the highlight marks are static (no pulse, no sweep) — the current match only gets a stronger fill. A PDF reads as a continuous vertical scroll at fit-width; an image fits the surface and zooms/pans under the operator's hand; any other type is a card with **Télécharger**. Toolbar: title, « p. N / total », page field, prev/next, zoom −/+, fit-width, find (Ctrl/Cmd+F focuses it; it reuses the highlight machinery, with a match counter and prev/next match), download, ✕. The toolbar's controls are operator actions, so they wear the rationed operator hue like the shop links. **Stacking:** opened from the documentation panel, the viewer stacks ABOVE it (its own scrim over the panel) and closing it returns to the panel, focus back on the « Ouvrir » that opened it — the panel is never closed underneath. Opened from a Le Dossier bill, same rule over Le Dossier. Opened by the agent (`showDocument`), it stacks over whatever is up (the documentation panel if it is open) and closing it returns there; a second `showDocument` REPLACES the open document, viewers never stack on each other. One Escape = one step back: the viewer swallows it, so the panel underneath stays. Like the documentation panel it stops above the dock (the dock stays above every surface, so the operator can still talk to Le Noyau about the page it is showing). Navigating to another vehicle closes it (it is that car's paper), and the pilot staging gate holds it while a car drives in. **Silent** under audition (§ Audition, the Data-surface row): the answer is the agent's spoken line; the viewer is the source it points at. Reduced motion: appears, no etch | scene overlay (`DocumentViewer`) |
| `proposeActions {headline, finding?, actions}` | a plain proposal → a CTA card **rises from Le Noyau's cradle** (behind the composer, never over it — this describes the socketed layout; under the pilot, § The pilot driver row `driver` becomes `'pilot'`, the card rises **above the freed orb**, whose cradle is then its bottom edge — clause added 2026-09-04); a **parts-bearing** proposal (an `actions[].patchTask` with `parts`) projects instead as the **parts manifest** in the RIGHT column with the primary **Ajouter** = that deterministic PATCH — unless every trimmed, case-exact reference is already on the target task, when it is a compact confirmation with no no-op control. `patchTask` → **no agent turn**; `instruction` → new turn. A proposal that arrives while one is already pending, or with no hero open (nowhere to raise a cradle), is **deferred into the à-décider queue** instead of dropped (`review` item — replays the same actions later) | CTA layer / `PartsManifest` / à-décider inbox |
| `toast {tone, message}` | **every** tone raises a **notice card** above the console (§ The notice stack) — dismissed by **vu**, or auto-closed after a read dwell once its spoken line has finished (corrected 2026-09-04; it used to stay until dismissed). On top of that: non-alarm also writes the narration line (Noyau heartbeat, transient); **alarm** also docks a `notify` item onto the à-décider queue | Le Noyau (`NoticeStack`) |
| `status {message}` | one **Noyau pulse** (per narrated step, sparse) + the narration line etches; the same step also appends an **aria-hidden tool-step chip** (short verb + glyph) beside Le Noyau, collapsing into the journal on turn end — a silent companion, never a second announcement | Le Noyau |
| `turn {phase}` | `start` → Noyau busy/working (aperture focuses, scan locks) + a **cyan scan-sweep** slides across the car; `end` → settle → green/amber/red | Le Noyau + scene overlay |
| composer submit / a CTA `instruction` click, for a **real agent turn** (added 2026-09-05) | at the GESTURE itself — before `turn:start` is even acked, and 2.6 s before the worker's first real frame in a measured turn — `send()` dispatches its own `status {tool:'acknowledge'}` right after `turn:start`, so the ticker prints `t.atelier.acknowledged` (« Je m'en occupe… ») immediately, and speaks it if audition is on, since the click is also the gesture that unlocks the AudioContext: this is the first line a fresh session can actually play. The worker's own first `status` frame **supersedes** it (latest wins, unchanged ticker rule); if it is being spoken it finishes, unchanged. Chip: **reçu**. The deterministic wayfinder chips (`runLocalAnswer`) already printed two `status` steps at click time — this follows that precedent for a real turn, which used to dispatch nothing until the worker answered. Requires the `spoken`-pruning fix (§ Audition, corrected 2026-09-05 above) as a prerequisite: without it, this exact line said once would go silent for the rest of the tab | `Atelier.tsx` (`send`) + Le Noyau |

`focusView` addresses the whole `PanelKind` — `specs`, `tasks`, `carnet`, `parts` **and** `docs` (CONTRACT.md; `parts` and `docs` listed here 2026-10-02 — the sentence had stopped at `carnet` while the wire already carried `parts`). `visits` stays client-synthesized. A panel the
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
| `driver` becomes `'pilot'` (the first pilot frame) — rows in this group added 2026-09-04 | **Le Noyau leaves the socket.** Under the in-app driver the orb is the operator's instrument and lives shrunk (`scale(0.36)`) in the console's 52 px socket beside the input they type into. Under the pilot the operator is not typing — someone else is driving — so the orb renders at its **native 138 px, centred above the console**, the cradle under it, and the console **thins to a hairline handle** beneath (next row). Same DOM, different geometry: the console turns into a column (orb, then handle); the **socket box itself grows to 138 × 158 px** (orb-wrap 138 + cradle 26 − 6 overlap, `noyau.css:71,237`) and the orb's `scale(0.36)` goes to `scale(1)`, both over `--at-dur-swap`, the orb staying absolute-centred in the box throughout — so the à-décider inbox (anchored `bottom: calc(100% + 10px)` on the socket, `ambientInbox.css`), the ember (orb-relative corner) and the cradle all anchor to a box that matches the orb, and the focus-restore fallback (`SceneView.tsx` `'.noyau-ember, .at-scene-input'`, scoped to the console) still finds both. The notice stack and the CTA **rise above the orb** (DOM order — they sit above the console, and the orb is now the console's top). Keyed on the dock (`.at-scene-dock[data-driver='pilot']`), forwarded from `noyau.driver` — the first CSS that reads `data-driver` anywhere. **Corrected 2026-09-04, at build:** the left rail is NOT untouched by this — its bottom clearance (`rail.css` `max-height: calc(100% - 200px)`) is a literal sized to the 66 px row console, and the freed orb makes the console up to 218 px tall (socket 160 + gap 8 + composer 50 in veille pilotée), so the chip row landed on the rail's « Plus tard » section at every viewport height. The clearance grows by the same 152 px under the pilot, same `--at-dur-swap`, keyed on the SAME dock attribute through `.at-scene:has(…)` so `data-driver` is still written in one place | `SceneView` + `scene.css` + `rail.css` (+ `ambientInbox.css` only if its anchor needs it) |
| the hairline handle — `driver: 'pilot'`, `quiet: false` (the run is **under load**; added 2026-09-04) | the console is the **locked composer, collapsed**: a 10 px hairline capsule the width of the console, grayscale by construction (`--at-hairline` border, the console's own dark fill — no cyan, no orange), so it reads as "closed" without colour (hard rule 5). It is locked because `busy()` is true here (`state.ts:145`; `send()` no-ops, `Atelier.tsx:519`). **Hover does not inflate it** — a pointer over a control that cannot be used is not an intent, and a projector or touch surface has no hover. The input stays in the tab order (it is never `disabled`; the lock is the submit no-op), and on `:focus-within` **of the composer itself** — not of the console, so focusing the ember or the inbox inside the socket does nothing to the handle — it inflates to the full 48 px input, because a focused control the operator cannot see fails WCAG 2.4.7. What it must not do is *look* usable while it is not: the pre-existing gap that the locked input carries no visible lock affordance under either driver is not widened here; the same change records it in `FUTURE.md` | `scene.css` |
| **veille pilotée** — `driver: 'pilot'`, `quiet: true` (added 2026-09-04) | the handle **inflates to the full input on its own**, over `--at-dur-swap`, the moment `quiet` flips: that is the exact instant `busy()` becomes false (`state.ts:145`) and the composer UNLOCKS (row above, § veille pilotée) — so the unlock is *seen*, not hidden behind a Tab press, during the one state that invites the operator to type and may hold for minutes. The freed orb stays where it is. A pilot frame that clears `quiet` collapses the handle again (same step); typing posts a turn → `driver: 'app'` → the orb returns to the socket (next row) | `scene.css` (keyed `[data-driver='pilot'][data-quiet='true']`, `data-quiet` forwarded onto the dock beside `data-driver`) |
| `settle` after a pilot run (`driver` → `null`), or the operator typing (`driver` → `'app'`) (added 2026-09-04) | the orb **returns to the socket**, the console re-inflates — same `--at-dur-swap`. The app driver's turns never leave the socket: nothing changes under `driver: 'app'` | `SceneView` + `scene.css` |
| `prefers-reduced-motion` (added 2026-09-04) | the resize is an **instant state change** in both directions, and so are the handle's collapse and inflate (`quiet` on/off, focus in/out). The orb's size and the handle's height encode who is driving and whether the operator may type — information; the transitions into them are not | `scene.css` |
| liveness | **a pilot run never trips the 90 s watchdog.** "Le tour ne répond plus" is a claim the client cannot support here — it cannot tell a host reading code from a host that quit — so it says how long it has been silent instead and lets the operator judge. Only `settle_dashboard` (or the operator taking over) ends a pilot run | Atelier |

**Corrected 2026-09-05 — the orb leaves the socket under BOTH drivers.** The 2026-09-04 rows above
freed the orb only under the pilot, on the reasoning that under the in-app driver the operator is typing
and the orb is their instrument, shrunk beside the input. That reasoning held the orb at 36 % on the one
screen where it is the agent's only body, and the user's first note on the stage was that the orb should
take more space. So the geometry rows (socket 138 × 158, `scale(1)`, console as a column with the orb on
top, rail clearance grown) now apply **unconditionally**; `data-driver` keys only what sits UNDER the orb:
the composer input under the in-app driver (unchanged, the operator still types), the hairline handle /
veille-pilotée inflate under the pilot (rows above, unchanged). The `driver → null` "return to the
socket" row is therefore void — there is no socket to return to; the orb stays where it is and only the
console under it changes. Reduced motion: still an instant state change for the handle.

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

Clarified 2026-09-04, with the hologram: the exemption covers every **continuous** loop on the stage —
the breathe, the light-shaft drift (`HoloPodium` `SHAFT_FRAG`, already there), the scan-sweep's
travelling wave, and now the hologram's scanline drift (`HOLO_SCAN_PERIOD`). What is NOT exempt is a
one-shot that happens to run in `useFrame`: the arrival flicker's decay is a duration and takes the step
whose INTENT it has — `--at-dur-etch`, *a line etches*: the hologram writes itself in line by line, which
is an etch, not a fan (a fan is a SET of things arriving staggered; the flicker is one surface resolving).
Its twin `FLICKER_SECONDS` ↔ `MOTION.etch` MUST be pinned by `motion.test.ts` in the same change, exactly
as the dolly and the pulse are today (`motion.test.ts:29-31` pins only those two until then). The test
is: does it end on its own? Then it is a duration and it takes a step.

## Le Noyau's à-décider queue (ember)

Hands-off concerns (the §9 overdue crossing), alarm toasts, and superseded/homeless `proposeActions`
proposals never vanish — they queue on the ember (`notify`/`review` `DecisionItem`s; a `question` kind is
reserved, not yet produced). The badge shows the live queue length. Opening the inbox **mutes** the badge
(`emberSeen`) but does **not** drain the queue — only resolving or dismissing every row does, so a closed-
without-resolving inbox re-opens with everything still there. Rows resolve deterministically (a review
row replays its `patchTask`/`instruction`; a notify row offers "vu" or "planifier ○") — never a transcript.

## The notice stack — an answer that lands, then clears (2026-08-16; auto-close 2026-09-04)

The narration line is a **heartbeat**: it says what Le Noyau is doing right now, and the next `status`
overwrites it, and `settle` clears it. That was the whole surface a non-alarm `toast` ever reached, so
the agent's actual *findings* — « pas de filtre à air dans le catalogue pour ce moteur », « j'ai pris
la Mazda, tu en as deux » — scrolled past at the speed of the next tool call and were gone by the time
the turn settled. An answer is not a heartbeat. It gets its own surface.

**Reversed 2026-09-04 — the cards now auto-close.** This section was written as *"an answer that
stays"*, and the row below said in as many words: *"It does not expire, does not fade, is not on a
timer."* That rule was protecting against findings scrolling past before they could be read. It is
dropped, deliberately, because in use the stack became litter the operator had to sweep: three cards
each needing a **vu**, during a demo, with both hands elsewhere.

What makes the reversal honest is the same thing that made `NOTICE_CAP` honest — **the journal drawer
keeps every toast at full precision.** Nothing is destroyed by a card leaving; it is compression with a
way to look closer. What would NOT be honest is a card vanishing mid-sentence, so:

- **The dwell never cuts the voice.** A dismissal is a cancellation (§ binding rules), so a fixed timer
  shorter than the utterance would truncate the very line it is displaying — recreating the exact
  truncation fixed on 2026-09-04 in the pocket-tts header. A card whose own line is still being spoken
  does not close; the dwell starts when the sentence ends.
- **It closes whether or not it was spoken**, so muted and unmuted behave the same. The audition is a
  garnish and must never be what decides whether the screen clears.
- **vu still works and still closes at once.** Auto-close is a floor, not a replacement for the control.
- **An alarm is unaffected** — it docks in the à-décider inbox, and the queue item is not on any timer.
  Auto-closing a *reminder to decide* would be deciding it, which is the one thing **vu** already may
  not do.

| Real frame / condition | Atelier motion | Owner layer |
|---|---|---|
| any `toast {tone, message}` | a **notice card rises** above the console (`--at-dur-rise`, from below — it comes out of Le Noyau, like the CTA cradle), newest closest to the orb. It carries a severity spine in its gutter (the visit-card vocabulary: colour + a mono tone label, never colour alone) and one control, **vu**. It **auto-closes** after a read dwell — but never while its own line is still being spoken, and the dwell only starts when that sentence ends (corrected 2026-09-04; it used to never expire) | Le Noyau (`NoticeStack`) |
| a toast whose exact `tone:message` is already on the stack | **nothing new** — the same finding re-arriving is one card (the `pushDecision` dedup rule, applied to notices). A pilot repeating itself does not build a wall | `state.ts` |
| a fourth notice while three are up | the **oldest leaves**, instantly (`NOTICE_CAP` = 3). The stack is a working set, not a log — the journal drawer already keeps every toast at full precision, which is what makes the cap honest (compression with a way to look closer) | `state.ts` |
| **vu** | the card is removed at once — no exit animation. A dismissal is the operator's own action, and animating their own click back at them is the theatre the render path forbids | `NoticeStack` |
| `turn {phase:'end'}` / `settle` | **notices survive both.** They are what the turn produced; clearing them when the orb returns to veille would delete the answer at the exact moment the operator looks up from the car | `state.ts` |
| `prefers-reduced-motion` | the rise cuts to the end state. Nothing is lost — the card and its spine *are* the information | `NoticeStack` |

**Not a second announcement.** The stack carries no `aria-live`: a non-alarm toast is already announced
once on the narration line, and an alarm is already a row in the à-décider inbox. The cards are a
sighted-only retention surface reachable by browsing — the same rule the tool-step chips follow.

**An alarm still docks in the inbox.** The card and the `notify` item are two different jobs — the card
is *read this now*, the queue item is *decide this later* — so **vu** on a card does not resolve the
queue row. Dismissing a reminder is not deciding the thing it reminds you of.

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

**Corrected 2026-09-04 — generic part meshes.** The paragraph above said the reveal is *"the one place the
stage stops drawing the car and starts drawing a diagram — deliberately, because `public/mazda6-gh-web.glb`
is ONE node, ONE mesh … so any claim to be showing that specific engine bay would be a lie the geometry
cannot back. Authored primitives in the region's anchor space say 'circuit du filtre à carburant'; they
never say 'your car's fuel filter, photographed'."* Three things in it still hold and are not reopened:
(1) the car's glb is one node — nothing is, or will be, extracted from it; (2) the authored primitives
(`assembly.ts`, five shapes) stay the diagram's **vocabulary**: every part has one, and it is what draws
whenever nothing better is on screen; (3) a part's `size` is chosen to READ at the reveal framing, never
to be real-scale (a 95 mm plug at 1:1 beside a 4.3 m car is a dot). What changes is where the detail the
low-poly shell cannot carry goes: **into the parts.** A part whose KIND has a generic mesh in
`public/parts/` (`PART_MESHES` in `assembly.ts` — the one table) draws that mesh instead of its primitive,
through the **same `buildBlueprint` pass as the shell** (bloomed edges over the holo fill), so it is visibly
the same matter — a hologram of the thing you order — and it is **normalised to the primitive's box**:
the mesh's LONGEST axis is scaled to the primitive's extent along THAT axis (`schematicBox(shape, size)` →
`meshFit`), never to the raw `size` and never to the primitive's own longest axis. **Corrected 2026-09-05,
at review:** this sentence first said "largest dimension equals the primitive's largest dimension
(`schematicExtent`)" — a rule that defeats its own reason: `engine-block.glb` is tall (0.433 × 0.700 ×
0.386) under a housing that is long (1.116 × 0.692 × 0.870), so fitting its height to the housing's length
made a block 61 % taller than the shape it replaces, its top above the roofline (1.56 m vs a 1.48 m
`cabinTop` on the berline), and the loading swap jumped the way the sentence said it must not. Fitted along
the matched axis the block is 0.428 × 0.692 × 0.382 — inside its housing, top at 1.35 m as today; the
battery fits its housing's depth, the plug its sphere, the filter its canister's height. The other axes
fall where the part's own proportions put them (a disc's hub is deeper than a 0.16·`size` pancake — that is
the true shape of a disc, not a jump). The mesh is centred on the same offset (floor at 0 → lifted by half
its scaled height). This does not reopen the honesty
argument, it obeys it: a generic spark plug says "a spark plug" exactly as the sphere did — more shape, no
more claim. The line that is not crossed is **vehicle-specific detail**: `engine-v6.glb` is **refused** — a
detailed V6 under a four-cylinder Mazda is the very lie the paragraph above forbids, and no vehicle carries
an engine-layout datum that could select it — so the file stays on disk, unwired, and
`public/parts/SOURCES.md` records why. Same rule, smaller: `spark-plug-worn.glb` is not wired (a WORN
plug is a claim about the operator's plug the data does not hold); `fuel_filter` keeps its canister (the
oil-filter mesh is a different part, so it would be a lie about a different thing); lines, circuits, pads,
tyres, the airbox and the cockpit stay primitives. The "Out of scope" paragraph in § "The hologram is the
light", which deferred "detailed part meshes" as a content decision, is corrected the same day to point
here: the decision is taken, with the generic-only limit.

**Corrected 2026-09-05 — the `disc` primitive stands on the axle.** Since C3 the `disc` shape has been a
`cylinderGeometry` with no rotation (`Assembly.tsx`): its axis is vertical, so `brake_discs` drew as a flat
pancake under the wheel — the wrong picture of a brake disc, unnoticed because from the axle's reveal pose
a thin flat disc is a line. It was caught when the generic mesh arrived yawed onto the axle (`brake-disc.glb`
has its axis along x; `rotationY: π/2` puts it along z, the axle) and the Suspense swap from the primitive
would have been a 90° flip of a 0.6 m disc. Decision: correct the PRIMITIVE, not the mesh — the disc
primitive now rotates `π/2` about x (`DISC_UPRIGHT`), axis along z, face in the wheel plane; its
`schematicBox` is `[2·size, 2·size, 0.16·size]`. Both vocabularies now show a disc the way a wheel holds
one. The alternative — keep the flat primitive and lay the mesh flat to match — was refused: it would have
made the honest fallback and the mesh agree on a false picture.

| Real frame / condition | Atelier motion | Owner layer |
|---|---|---|
| `revealAssembly {region, partKeys?}` | the shell **ghosts**: the paint-tinted fill fades out and the blueprint edges dim to a floor, so the body reads as a wireframe you can see through — never to nothing, because the operator must keep the car's shape to know where they are. Under it, the region's **schematic assembly** rises in that region's anchor space, parts unfurling staggered (`--at-dur-fan`, the manifest/rail vocabulary, capped at 4 steps). The camera takes the region's **reveal pose** — steeper than its focus pose and further BACK, not closer: a reveal is the same car seen through, and cropping to the part would throw away the context that makes the diagram locatable. **Corrected 2026-09-12:** while a parts manifest owns the right projection column, this exact reveal framing shifts as one rigid pose left of that column; radius, polar angle and reduced-motion cut are unchanged | `VehicleMesh` (`GhostRunner`) + `Assembly` + `cameraRig` |
| a reveal whose parts have not all landed | **the ghost depth tracks how much of the assembly is on screen.** The shell dissolves as the parts arrive, so a slow catalogue lookup produces a slow dissolve rather than a finished dissolve held over an empty bay. The one thing that could have made C1 theatre — a fixed-length fade — is exactly what this removes: the duration is the fetch's | `VehicleMesh` + `Assembly` |
| a part whose kind has a generic mesh (`PART_MESHES`; added 2026-09-04, corrected 2026-09-05) | **Arrival — same rise, same stagger.** The mesh rises from `RISE_FROM` over `--at-dur-rise` in the slot `assemblyDelay(i)` gives its primitive, reading the diagram's ONE clock: a glb that lands mid-rise joins the rise at the current instant, one that lands after it snaps to landed — never a second rise. Its fill fades in with the rise through a dedicated `uRise` uniform that scales the WHOLE alpha — fresnel included (corrected 2026-09-05: scrubbing `uFillOpacity` left the rim at full `FRESNEL_GAIN` from the first frame, so the fade was invisible under the glow and only the 7 cm lift carried the arrival; the shell leaves `uRise` at 1). The ghost's denominator counts slots, not glbs: the primitive standing in IS the part landed, so a slow part fetch never holds the shell. The fetch starts with the part's first render (`useGLTF`); nothing preloads — the only earlier moment would be idle, which § Priority forbids. **Highlight** — named by `partKeys` → the OPERATOR hue on its edges AND its fill, fresnel and scanlines (`buildBlueprint` `edge` + `paint`; `uEdge` drives fresnel and scanlines, holoFill.ts) — the callout hue the primitive already carries, not a third hue (§ The hologram is the light, "Truth before spectacle", corrected 2026-09-04); unnamed → blueprint blue, exactly the shell's. **Glow is not hue** (added 2026-09-05): a mesh part is the SHELL's matter — HDR-boosted edges, bloomed — while a primitive is the diagram's — flat `<Edges>`, never bloomed. The two may differ in glow, never in hue: an orange canister beside an orange mesh must read as ONE orange after Bloom; if the bloomed orange reads pink or white the part's edge boost comes down (`edgeBoost` on `buildBlueprint`, the shell's 1.7 untouched), the primitive is never brightened. **Loading fallback** — while the glb streams the primitive draws in its place (Suspense), so the reveal is never later or emptier than before. **Error fallback** — a 404 or decode failure falls back to the primitive for that part for the SESSION (corrected 2026-09-05; it said "the life of the reveal", but the loader caches the rejection, as it does for the shell), `MeshErrorBoundary`; the console error is the only signal. **Arrival flicker** — the glb landing is an arrival: its fill flickers once over `--at-dur-etch` (`HoloRunner`) as the shell's does, and its scanlines drift on the same clock sample the shell reads — one uniform write per mesh part per frame, only while a reveal holds (a hold is not idle; § Priority, cheap idle). **Reduced motion** — unchanged: cuts to landed (`uRise` = 1), no flicker, scanlines frozen. **Honesty check** — hide the canvas → the parts manifest still lists the reference and the rail still names the service; the mesh carries no datum the primitive did not, and nothing but the part's KIND selects it | `Assembly` (`MeshPart`) + `blueprintBuild` + `holoFill` |
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
cutting-mat workbench (`CuttingMat.tsx`, always mounted): a graphite/blue-grey surface, **never
green** — green is reserved for the health signal (PRODUCT.md "light is meaning"), so the floor material
can never be mistaken for a status read. The car itself keeps its blueprint look everywhere it
renders — the rung-b proxy (`CarProxy.tsx`), the rung-a real `.glb` (`blueprintBuild.ts`), and the
parc-grid spinning thumbnails (`ParcThumb`/`ParcLot.tsx`): a faint paint-tinted night-glass fill — a
shader that takes **no lighting input** at all (since 2026-09-04 a hologram shader, before that a
`MeshBasicMaterial`; neither ever responded to a scene light) — under glowing HDR-boosted, equally unlit,
electric-blue edges. Blueprint edges, the holo scan/pulse verbs, and the podium's rim/glow all carry
the signals this map defines.

**Corrected 2026-09-04 — the room is dark and the hologram is the light.** This section said the mat
is *"a lit, graphite/blue-grey surface"*, lit by a fixed two-light rig (`SceneLights`: ambient 0.6 +
one warm `#ffe0b0` directional at 0.9, `Plateau.tsx:160-168`), and that the podium glow was *"tuned
to read as a reflection on the lit mat floor rather than fog in a void"* — the stated reason for the
`PODIUM_INTENSITY` cut to 0.55. That rule was written when the void was the alternative and the mat
needed a key light to exist at all. It is dropped, for two reasons that survive on their own:

- **It contradicted the material.** The car is a projection — an unlit fill under unlit bloomed edges —
  while the floor beneath it was lit like a photographed object by a warm key from nowhere. Two lighting
  models on one stage, and the warm key was the one carrying no datum: nothing in the frame vocabulary
  ever changed it.
- **It spent the reference wrong.** Every source the redesign leans on (`docs/design-inspiration.md`
  § 7) keeps the room dark and lets the hologram be the only luminous matter, its one hue spilling
  softly onto the floor. A lit mat under a hologram reads as a desk lamp left on.

So: the warm key is **gone**; the room keeps a cool, dim ambient fill only (`ROOM_AMBIENT`, pinned in
`agentLight.ts`; low enough that the mat base reads as black mass and only its grid lines read at all).
The hologram's own light is what else reaches the floor — two point lights, cyan, breathing on
`breathe01` with the podium (§ "The hologram is the light"). Geometry the old wording ignored: the
turntable disc (r 3.2, `Plateau.tsx` `Turntable`) covers the mat under the car, so "the mat under the
car" was never a surface anyone could see. The acceptance is therefore: **the disc top reads a soft
pool; the mat ring beyond the disc (r ≈ 3.2–6, where the grid's own falloff is brightest,
`CuttingMat.tsx` `falloff`) keeps its grid faintly legible under a soft cyan tint**; the spill must
never bleach either. The mat is the dark that makes the light visible, and a floor that competes with
the car has stopped being a floor.

What does not move: the floor is never green; the podium's rim arcs, echo rings and shafts carry the
CAR's health. `PODIUM_INTENSITY` stays at 0.55 for now, but honestly: its stated reason is gone with the
lit mat, and the number is kept only because it has not yet been seen against the dark room. The first
screenshot decides it, as one dated correction here — not as a knob the implementer turns by taste.

**Decided 2026-09-04, on the first dark-room screenshots (`3d-rest.jpeg`, `review-rest.jpeg`): 0.55
holds.** The rim arcs read as the gauge against the dark room without bleaching the disc or the mat. What
those screenshots also showed — the pool "present but very faint", the disc top near-black — is not a
rim matter and is not fixed at the rim: the lever is the disc's diffuse term and the agent light's levels
(§ "The hologram is the light", "Also in this change", corrected the same day).

## The hologram is the light (added 2026-09-04)

The Stark-workshop reading, taken literally: the room is dark, the car is a **projection**, and the
only luminous matter on stage is the hologram and the emitters that project it. Three things follow,
and none of them is a new verb — they are the existing verbs (scan, pulse, ghost, the done filament,
the breathe) made physical:

1. **The hologram lights the stage.** Two point lights, one `useFrame`, one shared breath (`breathe01`,
   in phase with the rim and the podium), both lighting only what a scene light can light — the disc,
   the mat, the emitter housings; the fill, edges, rim, rings and assembly are all unlit and ignore
   them:
   - the **agent light** sits at the car (`AGENT_LIGHT_POS`), with a short reach (`distance` tuned to die
     at the rim, § numbers in `agentLight.ts`) so its pool is **confined to the disc's interior**. It
     carries the orb's **phase as intensity** (rest / quiet / working, each `base + amp · breathe`) and
     the orb's **result as colour** (cyan → `RESULT_LIGHT` on `done`, then back). It does **not** borrow
     the orb's exertion amber (`noyau.css:46-50` — the orb IS amber under load; the light stays cyan).
     Deliberate: amber on the stage is already spent twice, as health on the rim and as an amber
     result in the pool, and a third amber would be read as one of those. So the light carries the
     orb's state, not the orb's palette — that sentence replaces an earlier "the light is the orb's
     colour", which was false.
   - the **spill light** sits above the projection volume (`SPILL_POS`, roughly the shafts' height),
     wide reach, **always `HOLO_CYAN`**, at a fixed ratio of the agent light's intensity (`SPILL_RATIO`),
     on the same breath. It is the hologram's cast light on the mat ring — motif (c) — and carries no
     value: cyan is the agent's hue and never a signal, so a tint of it on the floor cannot be misread.
2. **The fill is a hologram, not a tinted box.** The `.glb` fill (`blueprintBuild.ts` `fillMat`, before
   a flat `MeshBasicMaterial`) becomes a `ShaderMaterial` with no lighting input: a view-dependent
   **fresnel** edge in the blueprint blue (the glass-like depth the references share), slow **horizontal
   scanlines** always drifting upward (a loop period — a frequency, exempt from the duration tokens like
   the breathe), a slight fade with camera depth, and a **noise flicker** that fires ONLY when a mesh
   arrives and inside the scan band while Le Noyau reads — never at rest. A hologram that flickers
   constantly is a hologram nobody can look at for six hours; the flicker is the moment of projection,
   not the texture of it (cheap idle, `docs/prompt-pilot-motion.md` hard rule 6). The paint tint and the
   ghost behaviour are unchanged in effect: `ghostDepth` still drives the fill to zero and the edges to
   `GHOST_EDGE_FLOOR` — now via the one `uGhost` uniform both shaders read, instead of a material opacity
   write.
3. **Emitters root the projection.** Four small housings on the disc, inside the rim, each with a lens
   that wears the agent light's colour: faint at rest, brighter under load, on the shared **breathe**
   (never on the per-step pulse — see the `status` row). They are the hologram's visible origin — the
   geometric reason the car is there — and they say who is projecting: the same light as Le Noyau, from
   the same state.

**Which surface carries what — the rule a still screenshot must satisfy.** Two signal systems light
one podium, so each owns a surface: the podium's **perimeter** — rim arcs (`RingSegment`), echo rings
and light shafts (`HoloPodium`, `podiumGlow`) — carries the **CAR's health**; the podium's **interior** —
the disc pool and the four lenses — carries the **AGENT's turn**, exactly as the orb does at the same
moment. The colour at the rim is the car; the colour inside the rim is the turn; a healthy car with an
amber finding is a green rim around an amber pool, and that is legible because the two never share a
surface. The spill on the mat ring is cyan and only cyan. The agent light's `distance` is what enforces
this — it must fall to near nothing at the rim — and is pinned in `agentLight.test.ts` for that reason.

**Truth before spectacle, checked.** Hide the canvas and nothing is lost: the lights and the emitters
carry no value that is not already the orb's `phase`/`result` (announced on the aria-live line, printed
as the signal word in the journal) and the rim's arcs. The result colour is the ONE place a signal hue
is allowed off the rim/podium, and it is allowed because it *is* the result — the same green/amber/red
the orb's filament shows, at the same moment, for the same reason. Nothing else on stage may take a
third hue: the fresnel is blueprint blue, the scanlines are blueprint blue, the emitters and both lights
are the agent's cyan. **Corrected 2026-09-04 (reveal part meshes; written 2026-09-05 at review):** a reveal
part named by `partKeys` wears the OPERATOR hue on its fresnel and scanlines as well as its edges — `uEdge`
drives all three in `holoFill.ts`, so a part built with `edge` = the callout colour cannot do otherwise.
That is not a third hue: it is the callout hue the primitive has carried since C3 (§ The reveal), on
standing state the operator asked to see. The SHELL's fresnel and scanlines stay blueprint blue — the
default `uEdge` is `BLUEPRINT_EDGE` and `blueprintBuild.test.ts` pins it so the shell cannot drift.

**Cyan, "never a fill" (`tokens/atelier.css:33`), checked.** The ration says cyan is Le Noyau's and never
a fill. A cyan pool is not a painted fill: it is **light cast by the agent** — the role the ration gives
cyan — falling on surfaces that stay their own dark colour, bounded by the disc for the agent light
and by distance falloff on the ring for the spill, and low enough that the mat reads as black with a
tinted grid (§ Stage look acceptance). The comment at `atelier.css:33` is amended in the same change
to say so ("never a painted fill; the hologram's cast light is light, not fill"), so the two documents
agree.

| Real frame / condition | Atelier motion | Owner layer |
|---|---|---|
| rest — no turn, `phase: 'idle'` or `'listening'` | the room is dark (`ROOM_AMBIENT` only, no key). The agent light breathes faint cyan on `breathe01` — the **disc under the car** shows a soft cyan pool dying at the rim; the spill tints the mat ring, grid still legible through it. The four emitter lenses glow dim cyan on the same breath. Scanlines drift on the shell; fresnel rims the silhouette; **no flicker** | `Plateau.tsx` (`AgentLightRig`) + `holoFill.ts` |
| `selectVehicle {id}` — a mesh **arrives** (the thumb, then the full `.glb`; each is an arrival) | the shell **flickers into being**: a per-scanline noise dropout decaying over `--at-dur-etch` (`FLICKER_SECONDS` — the hologram etches itself in line by line; § Motion scale says why etch and not fan), then rests. Fires on mount only, never on a clock or a re-render; the thumb→full swap is a second arrival and fires once more — caused by the data landing, so not theatre (hard rule 1). The arrival window expires on wall time even under reduced motion, so a later motion-on toggle cannot replay it | `VehicleMesh` (`HoloRunner`) |
| `turn {phase:'start'}` / first `status` — `phase: 'working'` | both lights **brighten** (working level, still on the breath) and the emitter lenses go hot — the projector is under load; colour stays cyan (point 1). The existing cyan scan-sweep now also **flickers the fill inside its band** (`uScanOn` gates it), so the sweep reads as the beam reading the shell rather than a stripe painted on it. Cross-fades from the rest level over `--at-dur-filament`, the orb's own filament step | `AgentLightRig` + `holoFill.ts` + `ScanRunner` |
| `status` — a narrated step (one orb pulse) | **nothing extra.** The lights and lenses are already at working level and stay on the breathe; a per-step flash would be the double-announcement the tool-step chips avoid. The region PULSE on the edges stays the per-step motion | — |
| `turn {phase:'end'}` — `phase: 'done'`, `result: green\|amber\|red` | the agent light and the lenses **take the result colour** (`RESULT_LIGHT`: `SIGNAL_HEX.green` / `SIGNAL_HEX.amber` / `NEON_RED`, the podium's own red) over `--at-dur-filament`, in step with the orb's filament, and hold it steady (`amp` 0 — the filament does not breathe) for the settle dwell. The verdict is inside the rim, briefly, in the colour already on the orb; the rim keeps the car's health; the spill stays cyan | `AgentLightRig` |
| `settle` — `phase` back to `'idle'` | the light **returns to cyan** over `--at-dur-filament` and falls back to the rest level. The result was never the light's to keep — the rim arcs are | `AgentLightRig` |
| **veille pilotée** — `driver: 'pilot'`, `quiet: true` | the projector **dims** (it was never amber, so it does not "cool"): both lights drop to the quiet level, between rest and working, and the lenses dim with them, over `--at-dur-filament`. Still lit above rest — the run is open — but no longer claiming to read. A pilot frame that clears `quiet` brings them back to the working level over the same `--at-dur-filament` | `AgentLightRig` |
| `revealAssembly` — `ghostDepth > 0` | the fill's body **and** its fresnel fade with the ghost (`uGhost` shared with the edges); scanlines fade with them. The lights are unaffected — a reveal is the operator looking inside, not the agent acting | `holoFill.ts` (`ghost.apply`) |
| `prefers-reduced-motion` | **state stays, attention goes.** The dark room, the pool, the emitters, the fresnel and the current colour (rest / working / result) are all static and remain; the breathe freezes at mid-swell like every other breathing element (`b = 0.5`); the scanlines **freeze in place** (a static texture, still the hologram); the flicker **does not fire** (attention, not state — the pulse's rule); colour and level changes **cut** instead of cross-fading | all |
| the Plateau prop | Le Noyau reaches the Canvas as **one** prop, `agent: AgentLight` — a primitive union (`'rest' \| 'working' \| 'quiet' \| 'done:green' \| 'done:amber' \| 'done:red'`) derived by the pure `agentLightOf({phase, result, quiet})`. A primitive, deliberately: `Plateau` is memo'd against per-keystroke re-renders (`Plateau.tsx`, `export const Plateau = memo(PlateauImpl)`) and an object prop rebuilt each render would defeat that. `scanning` stays as it is (it is derivable from `agent`; folding it is a later cleanup, not this change) | `SceneView.tsx` → `Plateau` |

**Also in this change, small.** A subtle **vignette** in the composer (after Bloom; `multisampling`
stays `0`, hard rule 3) — the dark room's edges go darker still, so the eye lands on the lit centre. The
turntable disc is re-tuned (colour, metalness, roughness — pinned in `agentLight.ts` as `DISC`) so the
cyan light gives it a specular edge instead of a black hole; a drei `Environment` was considered for it
and is **deferred**: `scene.environment` is global and would re-light the mat (a `MeshStandardMaterial`),
and a dark room wins over a pretty disc.

**Corrected 2026-09-04, at review — the pool is diffuse, not specular.** "A specular edge" was the wrong
target: `#232a33` is ≈ 0.02 linear albedo, `metalness 0.6` left 40 % of that for the diffuse term, and
an overhead light's specular lobe points away from the HOME camera — so the disc centre computed to
≈ 0.01 linear (black) and the screenshots agreed. The rest row's acceptance ("a soft cyan pool dying at
the rim") is now met by two numbers, both pinned in `agentLight.test.ts`: `DISC` is **matte**
(`metalness 0.2`, `roughness 0.7` — the diffuse fraction doubles) and `AGENT_LIGHT_LEVELS` rise
(rest 28 / quiet 36 / working 50 / done 50, `amp` unchanged) to put the disc centre at ≈ 0.05 linear at
rest. Confinement is untouched (`distance` is the pin) and the spill on the mat ring stays under
bleaching (≈ 0.25–0.45 irradiance at r 4.5). Two smaller readings from the same screenshots, same day:
the four **emitters read as four floating dots** — the housing (`#0f1216`, metalness 0.6) vanished into
the disc and the rest lens (0.55 × HOLO_CYAN's 0.77 luminance = 0.42) sat under Bloom's 0.5 threshold — so
the housing is lifted (`#2c3440`, metalness 0.3) and `LENS_HDR.rest` goes to 0.75 (0.58: a faint halo,
still far under working's 2.2); and the **fresnel whitened the shell** — at `FRESNEL_GAIN 0.55` a grazing
edge landed inside Bloom's smoothing band and half-bloomed into a pale haze — so the gain is 0.35
(`FRESNEL_POWER` unchanged), which keeps the rim clear of the band and the body reading as night glass.

**Out of scope, by decision** — two refused, two deferred pending a design of their own. Refused: depth
of field (a focus pull is a camera verb this map does not have, and the dolly already carries "where the
attention is"); a reflective podium (a second copy of the car under the car, doubling the luminous
matter the dark room exists to ration). Deferred: data-as-geometry stations around the stage (a new
surface — design before build, `docs/design-inspiration.md` § 6.6); detailed part meshes
(`public/mazda6-gh-web.glb` is one node; § "The reveal" already says why the diagram is authored
primitives, and a real part library is a content decision, not a motion one). **Corrected 2026-09-04,
same day:** the part-mesh decision is taken — § "The reveal", "Corrected 2026-09-04 — generic part
meshes". GENERIC meshes of a part's kind, drawn through this same blueprint pass; vehicle-specific
meshes (`engine-v6.glb`) stay refused for the reason the reveal section gives.

## Stations — data given a body (added 2026-09-05)

A **station** is what a surface does to the *scene*, not to the DOM. Until now every surface opened as a
panel over the same shot; the stage only changed for the reveal. The direction settled 2026-09-04
(`docs/design-inspiration.md` § 7, § "The hologram is the light") rejects props and a detailed hero car:
the only matter allowed on the disc is **data**. So a station gives one dataset a body on the disc,
projected in the hologram hue, and the DOM panel becomes its legend rather than its replacement. Binding
for every station:

- **truth first** — an unknown value has an unknown body (wire only), never a zero-sized or invented one;
- **one hue** — stations are part of the hologram (`HOLO_CYAN` fill + edges); the operator hue marks the
  one item the operator is pointing at, nothing else;
- **no in-canvas text** — the scene has no font asset; dates, amounts and labels stay in the DOM legend,
  the body carries shape, order and size;
- **the panel must not cover the station** — a station under a centred scrim is theatre nobody sees.

### History — the visit ring (2026-09-05)

**Correction, dated 2026-09-05:** the Carnet was a *centred glass popup, deliberately NOT a side drawer*
(`historylog.css` header comment). That held while the scene had nothing to say about history. With the
ring on the disc the popup would hide the very thing it describes, so the Carnet becomes a **right-docked
glass column** (same content, same glass, same z-order, no scrim over the scene). The shopping list
(`focusView {panel:'parts'}`, above) keeps its centred surface — it has no station. So does the documentation (`focusView {panel:'docs'}`, added 2026-10-02), and the document viewer it opens (`showDocument`, added 2026-10-05).

| Real frame / condition | Atelier motion | Owner layer |
| --- | --- | --- |
| Historique opens (`historyOpen` true) | **the visit ring rises.** One slab per visit stands on the disc on the outer echo-ring radius, upright, tangent to the ring, ordered by `performedAt` clockwise — oldest at the far end of the arc, newest nearest the camera. The arc is centred on the camera's home azimuth and spans at most ~200° so no visit hides behind the car; angular step shrinks with the count (≤ 14°). **Height is the visit's effective cost** (bill, else the parts-sum estimate — the same figure the Carnet prints), normalised to the dearest visit; a visit **without any cost is wire only** at the floor height — unknown is not zero. Slabs rise from the disc over `--at-dur-rise` each, staggered across `--at-dur-cascade` in total; the camera dollies (`--at-dur-dolly`) to a higher, wider pose that frames ring and car in the left part of the viewport, the Carnet column on the right. Reduced motion: slabs appear, camera cuts. **Note, 2026-09-06:** the ring told this story with nothing on screen saying so — the operator asked why the bars were there. A legend line (« une barre par visite · hauteur = coût · fil de fer = coût inconnu · la plus récente près de vous ») is now docked with the projection (`CarnetProjection`) / the Carnet (`HistoryLog`) whenever either stands open | `Plateau.tsx` (`VisitRing`) + `visitRing.ts` + `cameraRig.ts` |
| a Carnet entry is hovered or focused | its slab takes the **operator hue** edge; every other slab stays cyan. The ring points at what the operator reads, nothing more | `HistoryLog` → `SceneView` → `Plateau` |
| Historique closes | slabs sink over `--at-dur-rise` (reverse, no stagger), camera released as after a reveal | same |

### Data — the fact constellation (2026-09-05)

Second station. The Data chip opens the fiche (`FactsDrawer`): today a full-width bottom sheet under a
scrim, unrelated to the car body. Every `VehicleFact` gets a **node** in the volume, standing over the
part of the car it describes:

- **placement** — a fact's region is derived, never stored: `<type>_ref` keys reuse the service-type map
  (`anchors.ts` `TYPE_REGION`, `oil_filter_ref` → hood, `air_filter_ref` → bay); the remaining keys go through a small curated
  prefix table (`oil_`, `coolant_`, `engine_`, `battery`, `belt`, `spark`, `gearbox_`, `transmission_`,
  `power_steering`, `ac_`, `refrigerant`, `fuel_`, `alternator`, `starter` → bay; `brake_`, `tyre_`,
  `wheel` → frontAxle, `rear_*` variants → rearAxle; `wiper`, `windshield` → windshield). A key no rule
  places is **unplaced**: it stands on a sixth anchor above the roof centre and the legend says so
  (`sans emplacement`) — the station never guesses a location;
- **body** — nodes of one region stack in columns of at most eight above the anchor (first node 0.35 m
  up, 0.22 m step; the ninth starts a second column 0.3 m to the side, columns centred on the anchor),
  one thin leader per column from its foot to its lowest node — *corrected 2026-09-05 at first light: 45
  facts made a single column run off the screen*. A **verified** fact is a filled holo node; an
  **unverified** one is wire only — same law as the cost-less slab;
- **arrival** — nodes etch in over `--at-dur-etch` each, staggered across `--at-dur-fan` per column, all
  columns together; the camera takes the **data pose**: home azimuth, a lower tilt than the history pose
  so the stacks read against the dark room, target raised to the stack height, shifted right as for the
  ring. Close: nodes sink over `--at-dur-etch`, no stagger; camera released;
- **legend** — the fiche becomes a **right-docked glass column** like the Carnet (same width, no scrim);
  hovering or focusing a row marks its node in the operator hue. Rows keep their order and their inline
  edit; grouping the legend by region is in `FUTURE.md`.

**Correction, dated 2026-09-05:** the fiche was a bottom sheet (`factsdrawer.css`, "#5 Fiche technique
drawer"). Under the station rule the sheet's scrim and its position over the disc are theatre; it docks
right. Nothing else about editing facts changes.

| Real frame / condition | Atelier motion | Owner layer |
| --- | --- | --- |
| Data opens (`factsDrawerOpen` true) | the constellation etches in (above), camera to the data pose | `Plateau.tsx` (`FactConstellation`) + `factConstellation.ts` + `cameraRig.ts` |
| a fiche row is hovered or focused | its node takes the operator hue edge, nothing else changes | `FactsDrawer` → `SceneView` → `Plateau` |
| Data closes | nodes sink, camera released as after the ring | same |

### The stage under a legend (corrected 2026-09-05, at first light)

Both legends dock right and the camera shifts the car left — but the console, the orb above it and the
nameplate header stayed centred on the FULL viewport, so at 945 px the orb stood on the constellation and
the legend ate half the nameplate. The old `at-scene-dock--top` modifier (the console jumping above the
fiche when it was a bottom sheet) is void and removed. Rule: **while a legend is docked, everything that
centres on the stage centres on the stage that is left.** One token, `--at-legend-w: min(460px, 40vw)`,
sizes the legends AND the right padding of the console dock and the nameplate header, keyed with
`.at-scene:has(.at-hlog, .at-facts)` — no JS state, the legend's own presence is the signal. Transition
over `--at-dur-swap`; reduced motion cuts.

### Fluids — the volumes (2026-09-05)

Third station. « Capacités fluides » already projects the catalogue's fill volumes as `SpecNode`s into
the specs column (`fluids.ts`, `focus.panel: 'specs'`). Each **volume** gets a body: a **cylinder
standing on the disc in a row in front of the car**, facing the camera's home azimuth (row centre at
radius 2.3, 0.42 m apart, radius 0.15 m), **height = litres × 0.14 m, capped at 1.4 m; past the cap the
radius grows so the cylinder's volume stays proportional** — the body IS the volume, 7.5 L of coolant
visibly dwarfs 0.9 L of steering fluid and a 64 L tank is a squat drum, not a 9 m column (*corrected
2026-09-05 at first light*). `ml`, `cm³` and `cl` convert; a **mass** (`g`, `kg`,
refrigerant) is not a volume and stands **wire only at the floor height**, the legend carrying the number.
Catalogue-sourced nodes are verified, so cylinders fill; an info-only node (a grade like `DOT 4`) has no
body. Rise over `--at-dur-rise`, staggered across `--at-dur-fan`; the camera takes the **fluids pose**
(home azimuth, tilt lower than the data pose, closer, target on the row, shifted right by the specs
column's width, not the legend's). Hovering a constellation node marks its cylinder in the operator hue.
Close: sink over `--at-dur-rise`, camera released. **Precedence:** a station shows only when no legend is
docked — Carnet and fiche win over the specs column.

| Real frame / condition | Atelier motion | Owner layer |
| --- | --- | --- |
| `focus.panel === 'specs'` with volume nodes | the volumes rise (above), camera to the fluids pose | `Plateau.tsx` (`FluidVolumes`) + `fluidVolumes.ts` + `cameraRig.ts` |
| a specs node is hovered or focused | its cylinder takes the operator hue edge | `SpecConstellation` → `SceneView` → `Plateau` |
| the specs column closes, or a legend docks | cylinders sink, camera released | same |

### The legend is grouped like the body (2026-09-05)

The fiche listed facts in backend order while the constellation stacked them by region, so the eye had
nothing to match. The fiche now groups rows **by region, in anchor order** — capot, pare-brise, baie
moteur, train avant, train arrière, sans emplacement — each group under a tier-3 mono caption, rows
keeping their arrival order inside a group (the same order the column stacks them, lowest first). Facts
are edited exactly as before; only the order and the captions change. Owner: `FactsDrawer`.

### Picking — the body points back (2026-09-05)

Hover on a legend row marks its body in the operator hue (the rows above). The reverse now holds:
**pointing at a body marks it and brings its legend row into view.** Hover over a slab, a node or a
cylinder takes the operator hue on that body; a click scrolls the legend so the row sits in view
(`scrollIntoView`, centred, no smooth-scroll under reduced motion) and the row wears the same hairline
it wears on hover, for as long as the pointer stays on the body. Nothing is selected, nothing opens: a
pick is a look, not an action. Owner: the station components (`VisitRing`, `FactConstellation`,
`FluidVolumes`) → `Plateau` → `SceneView` → the legend (`HistoryLog`, `FactsDrawer`, `SpecConstellation`).

### Stations under both drivers (2026-09-05)

The three stations were keyed on the operator's chips (`historyOpen`, `factsDrawerOpen`, the fluids
chip's `nodes`). The agent and the pilot never touch those: `focusView {panel:'carnet'}` / `showHistory`
raise the **carnet projection** and `showFact {keys}` the **spec constellation**, both in the right
projection column — and no body rose on the disc for either. Under the driver the conference is about,
the stage stayed a DOM panel. Corrected: **a station belongs to the dataset, not to the surface that
opened it.** The projection column already is a legend (right side, no scrim), so nothing about the DOM
changes; the bodies follow:

| Real frame / condition | Atelier motion | Owner layer |
| --- | --- | --- |
| `focus.panel === 'carnet'` (agent or pilot; `showHistory` too) and no legend docked | **the visit ring rises for the visits the projection covers** — with `keys`, only the visits carrying those services (one shared filter, `filterCarnetRecords`, feeds both). The projection stays a summary: it lists the three most recent and STATES the count (« 16 visites, 15 sans coût »), so the ring's sixteen slabs and the legend's three never contradict — the count is the bridge, « tout l'historique » opens the full Carnet (*worded at build, 2026-09-05*). Same slabs, same heights, same camera **history pose**, but the target shift is the projection column's (0.8 m, as the fluids row), not the 40 vw legend's. Hover on a carnet row marks its slab; a pick scrolls the row. **Note, 2026-09-06:** legend line « une barre par visite · hauteur = coût · fil de fer = coût inconnu · la plus récente près de vous » docked with the projection (§ above) | `SceneView` + `CarnetProjection` |
| `focus.panel === 'specs'` with `keys` (stored facts, `showFact`/`focusView`) and no legend docked | **a constellation of only those facts** — the agent points at a few things in the volume, not the whole sheet; empty `keys` (the whole sheet) gives the full constellation. Data pose with the projection shift. Hover/pick on the constellation cards as for the fiche | `SceneView` + `SpecConstellation` |
| `focus.panel === 'specs'` with `nodes` | the fluid volumes, unchanged (row above) | — |
| a chip legend opens while a focus station stands | the legend's station replaces it (legends win, existing rule); on the legend's close the focus station returns if the focus still stands | `SceneView` |
| `tasks`, `visits`, `parts` focus | no body yet — the worklist and the ladder already speak through the pulse regions and the rail; a body for tasks is a later row | — |

## One stage — the lot and the podium (added 2026-09-05)

The lot (`#/`) and the car page are two render trees with two canvases; a hard swap between them read as
two apps. A single canvas across routes is the eventual answer and is NOT this section. This is the
continuity the row `selectVehicle` promised, built without gating anything:

| Real frame / condition | Atelier motion | Owner layer |
| --- | --- | --- |
| a lot card is clicked, or the agent selects a vehicle from the lot | **Corrected 2026-09-12:** the select cue starts first, then the hash changes in that same synchronous handler (hard rule 1: neither waits on sound or motion). A **ghost of the clicked card**, fixed at its own rect, drives toward the named **podium target** (the hero car's lower-centre stage point), growing (~1.6×) over `--at-dur-etch`, ease-out. It sheds the card's glass, labels and controls immediately; only the car silhouette and its under-ring carry continuity into the hero. The other cards recede: opacity to 0 over the same duration, no movement. The ghost unmounts only when its own transform transition ends (or at once under reduced motion). While it plays, the car page mounts underneath and fetches its bundle | `Atelier.tsx` (`Departure`) + `departure.ts` + `parclot.css` |
| the hero car's meshes mount on the podium | **the projector boots the car**: `uRise` ramps 0 → 1 over `--at-dur-cascade` (alpha, fresnel included), together with the existing arrival flicker (`FLICKER_SECONDS`). A car that comes back from the lot rises the same way; a mesh swap inside the fallback cascade (proxy → thumb → full) does NOT re-rise — the rise belongs to the arrival, not to the level of detail. Reduced motion: alpha 1 at mount | `VehicleMesh.tsx` / `holoFill.ts` |
| the lot itself | **one room**: the lot page paints the same dark ground as the stage, so leaving and returning never changes the room's light. Verified at build (2026-09-05): `parcpage.css` already carried the scene's exact gradient — the warm gradient this row first blamed belongs to the card glass, not the page. Cards keep their glass; the thumbs keep the hologram hue | `parcpage.css` |
| `← The fleet` | the car page unmounts, the lot mounts — no reverse ghost (nothing to drive back from; the row above is about the CAR arriving, and the lot's arrival is its own cascade of cards) | — |

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
| a continuous ambient bed — **reversed and restored the same day, 2026-09-11; see § "The bed that was built and removed"** | encodes no datum. It fails the one-sentence test outright, and it is the exact "overuse of sound" the guidance warns produces signals that "are ignored and can cause annoyance, interference, or confusion" (HFDS §7.2.1.8, discussion) |
| `turn {phase:'end'}` — the settle tone (ok/warn/alarm) | the operator is watching the thing that just settled. The done filament already pairs colour with a mono label and announces on the aria-live line; a tone repeats a value that is on screen at full precision. A needle that moves because a value moved is instrumentation; a needle that moves because the operator already saw the value is theatre |
| a write landing (`log_maintenance`, a `patchTask` merge) | the write produces the largest visual event the app has — the health arc animating old→new, the task striking and leaving the rail. It is also **bursty**: several frames per gesture. ISA-18.2 defines an alarm flood as more than ten alarms in ten minutes; a piloted burst of four intents in 300 ms would hit that on its own. Anything the burst window coalesces must not have a sound per member |
| the break of a long pilot silence (veille pilotée → under load) | it requires no response. EEMUA 191 / ISA-18.2 rationalisation is a single question — *does this need the operator to do something?* — and "the pilot resumed" does not. The frame's own motion is the cue, as the pilot table already says. Its steady-state budget is also wrong: fewer than one signal per ten minutes per operator position is the target, and a chatty session breaks that in a minute |
| severity mapped parametrically onto the tone | this is the documented failure of IEC 60601-1-8, whose eight high-priority alarms share one acoustic structure and one rhythm and differ **only** in pitch pattern. They are hard to learn, hard to discriminate, their urgency is misperceived, and nurses with a year of musical training identify them far more accurately than those without — a set that only musicians can read is not a set. The audio twin of **never colour-only** is **never pitch-only**: severity is read off the spine and the ember, in grayscale, on a still screen |
| speech ("échéance dépassée") — **re-opened 2026-08-16, see `docs/voice.md`**: that document keeps this rejection for the *signal* and asks the separate question of whether the agent's ANSWERS (the `toast` frames now retained as notice cards) should be spoken | genuinely better on the evidence — auditory icons and speech beat abstract earcons on identification speed, accuracy and learnability (McNeer et al. 2018; Edworthy et al. 2014, 2017) — and rejected **as the alert signal** anyway, on the grounds that survive: an alert has to fire on a screen nobody is watching, possibly offline, and a sentence is the worst shape for that. The *original* reasons (OS-dependent `SpeechSynthesis`, French a lottery, a bundled voice breaking the self-contained page) were **superseded 2026-08-16** — the voice is now a server-side call to a same-origin `/tts`, so none of them describe what exists. They no longer argue against speech for ANSWERS; see § "Audition — speech, for ANSWERS" below. What still stands, unchanged, is that a talking dashboard over a conference PA is a catastrophe: hence muted by default, everywhere, always. **Correction, 2026-09-11:** the manual toggle is removed — audition is now always on; only the mic (`listening`) still force-mutes it while dictation is open |
| recorded auditory icons (a real ratchet, a real chime) | the same evidence favours them, and the same constraint forbids them: no external CDN assets, the page stays self-contained. Synthesised oscillators cannot make a credible auditory icon, so the one signal is honestly an abstract earcon and is kept to **one** for that reason |

### The bed that was built and removed (2026-09-11)

The row above was reversed, built, auditioned and restored **inside one afternoon**, and the round trip
is kept here because the outcome is worth more than the original prose was.

The reversal argued that the one-sentence test governs *signals*, and that a bed declared as stage
rather than instrument — claiming no datum and incapable of being mistaken for one — falls outside it.
It was built on that reading and the constraints were met on measurement: 98 % of its energy below
300 Hz, 0.6 % across the whole 500–3000 Hz alerting band and 0.004 % at the `échéance` fundamental, so
it demonstrably could not mask the one signal; short-term RMS spread of 2.5 dB over 29.5 s, so it
contained no transient that could read as an event.

**Every one of those measurements was accurate, and none of them was the point.** The operator's report
on hearing it was that the bed was *"more annoying than anything else"* — which is the word the
reversed row already used, quoting HFDS §7.2.1.8, before any of this was built. A spectrum cannot
detect irritation, and a clean measurement was allowed to stand in for the judgement that mattered.

What this leaves behind, kept deliberately:

- **The falsification clause was the right instinct and the wrong criterion.** The reversal said it
  would be undone by a masking measurement or an operator mistaking the bed for an event. Neither
  happened. It was undone by simple annoyance, which nothing in the clause anticipated — so a sound
  defended only by measurements is not defended.
- **The generation recipe survives and is reusable** (`docs/voice.md` § The room bed): what a usable
  prompt looks like, the two-generation failure/success pair, the mastering steps, and the discovery
  that `loop: true` alone does not produce a seamless seam.
- **The shared `AudioContext` stays** (`noyau/audioContext.ts`). It was lifted out of `useVoice` for
  the bed and is load-bearing for anything non-speech: one context per tab, and a hold count so the
  audition's suspend-on-mute — which fires on every mic press — cannot stop another sound mid-play.

### Audition — transition cues (added 2026-09-11)

**Revised 2026-09-11 after the spatial-palette audition.** The four physical/library runtime sounds
and the 0.68 s loop described below are retained as the first implementation's decision record, but
they are replaced by the approved ten-sound spatial palette. The accepted `reveal-spatial` recording
is preserved; `panel-spatial-v3` is the darker replacement for the rejected high-pitched panel. The
stable runtime filenames are versioned (`/audio/spatial-2026-09-11/*.wav`) so an already-open rehearsal
cannot reuse a decoded first-audition file under the same URL.

| Committed visible transition | Cue | Binding rule |
|---|---|---|
| workshop reaches its first drawable settle | `opening` | once per tab; binds to the same first-settle effect as the opening ticker |
| local or agent selection changes the selected vehicle | `select` | navigation is committed first; a same-vehicle selection is silent |
| facts/history/tasks/parts surface actually opens or changes | `panel` | empty parts, repeated focus and data refreshes are silent |
| a valid assembly is committed | `reveal` | an unsupported/declined region is silent |
| a proposal actually rises in the hero | `card` | inbox-only deferral is silent |
| an operator explicitly closes a card, panel, reveal or notice | `dismiss` | auto-close, navigation cleanup and supersession are silent |
| a deterministic or direct mutation visibly succeeds | `complete` | not every `turn:end`, toast or read-only answer |
| a mutation or live turn is diagnosed as failed/stalled | `blocked` | not tone-mapped from every warning; the printed/spoken failure remains primary |
| microphone permission has resolved, before capture starts | `mic` | bounded; it finishes before `MediaRecorder.start()`, and failure to load/play never strands the stream |
| real work remains busy for 1.5 s | `working` | six-second quiet loop; no idle bed; it continues under speech at the speech-duck gain, and stops only on mic, end and teardown |

One-shots lead their committed transition immediately and a 120 ms window admits at most one guarded
overlap; it does not trail the last frame. Speech is the payload, so active/new transition cues duck to
one quarter of their configured gain while it speaks. An armed or recording microphone is stricter:
all normal cues and the loop stop, pending/decode completions are invalidated, and only the bounded
pre-capture `mic` cue is allowed. Loading is a preload, never a reason to play a one-shot late: if its
buffer is not ready at the event, that occurrence is dropped.

The spatial listening WAVs are capped at −12 dBFS rather than the first set's −3 dBFS. Runtime gains
therefore target the audition balance directly: one-shots at or below 0.50 (`select` and `dismiss`
quieter), loop at 0.12. At the loudest mastered asset this is a linear peak of about 0.126, below the
legacy `SIGNAL.peakGain = 0.14`; speech ducking lowers that to about 0.032. Quiet assets remain quiet —
no peak normalisation is added at playback.

**Level correction, 2026-09-17.** The numbers in the paragraph above are the ones that were auditioned,
and they were too quiet in the room: the palette sat a full 6 dB under its own ceiling, so on a workshop
screen at normal system volume the transitions were inaudible. Every gain **and** the ceiling are
multiplied by 2 — one-shots at or below `1.0`, loop at `0.24`, `SIGNAL.peakGain` at `0.28`. Nothing
about the balance the audition approved changes, because every ratio is preserved exactly; what changes
is where the whole palette sits against the system volume. The reason the ceiling moves with the palette
rather than staying at `0.14` is that `SIGNAL.peakGain` is not an absolute loudness, it is the line the
cues must stay under, and freezing it while the cues rose would have collapsed the hierarchy instead of
lifting it. The `échéance` earcon is still unbuilt, so `0.28` is what it will be built to.

`1.0` is the ceiling of this table, not a step on the way to a louder one: the assets are mastered to
−12 dBFS and quiet recordings are not boosted, so at unity the loudest cue plays its file untouched. A
further increase is a re-master of the WAVs, not an edit here. Pinned by `dashboard/src/noyau/cues.test.ts`
§ levels.

Operator decision, taken after the room bed failed: the Atelier sounds its **state changes**. This is a
deliberate departure from "one signal", and from the taste rule in `docs/prompt-pilot-motion.md` § Taste
that forbade a sci-fi UI blip outright — that rule is **retired in the same commit**, not contradicted
in silence. What is NOT retired is everything about the `échéance` signal: the cues sit under it in
level (`SIGNAL.peakGain`), and none of them may become an alert.

Four cues, not one per frame. Eight near-identical whooshes would be the IEC 60601-1-8 failure this
document already picks apart — *"a set that only musicians can read is not a set"* — so the set is kept
small enough that each member is a different shape rather than a different pitch.

| Real frame | Cue | Asset |
|---|---|---|
| `showFact`, `focusView`, `showParts`, `showHistory` — a surface arrives | **`panel`** | 2.00 s |
| `revealAssembly` — the shell ghosts and the schematic rises, the largest visual event the app has | **`reveal`** | 3.08 s |
| `selectVehicle` — the car changes | **`select`** | 0.48 s |
| `proposeActions` — a decision is raised on the cradle | **`card`** | 1.00 s |
| a turn is **working** and nothing is being said | **`working`** — a 0.68 s loop, quiet, under everything | 0.68 s |

**Where a cue fires: `applyRender`, never frame arrival.** The stage gate HOLDS a frame until the car
it names is on screen (§ the pilot gate), so a cue fired on arrival would sound seconds before its
visual — and *nothing is audio-only* means a sound whose visual has not happened yet is a sound with no
condition. It rides the same call that moves the camera.

**One cue per burst, and the LARGEST wins.** A piloted turn lands four intents in ~300 ms; four
overlapping cues is the bed's failure with a shorter attack. This follows the camera's existing
coalescer (`pilot.ts` `burstReduce`, `useRegionBurst.ts`) with one difference: the camera dollies to the
region the burst ENDED on, while the ear takes the burst's biggest event — `reveal` over `select` over
`panel` over `card`. A burst that ghosts the shell and raises a panel is, to the ear, a reveal.

**What does not sound, and why it would be doubling:** `toast` and `status` are already SPOKEN when the
audition is on. A cue on either is the same event announced twice in the same modality, which is the
objection that killed the settle tone. `turn` and `setVehicles` place no surface. `focusRegion` moves
only the camera, and in a burst it coalesces into the panel cue that caused it anyway.

**The working loop is the one cue that encodes a datum**, and it is the reason it survives the
no-theatre rule (`docs/prompt-pilot-motion.md` § Hard rules 1). It does not pad a gap to look busy: it
sounds only while a turn is genuinely in flight (`state.ts` `busy()`, which already excludes a QUIET
pilot run), and it stops when the work stops. Three constraints keep it from becoming the bed:

- **A threshold, not an onset.** It starts only once the silence passes `CUES.loopAfterMs`. Most turn
  steps resolve faster, and a loop that fired on every 400 ms step would be a stutter machine rather
  than a progress indicator. It appears only when there is a wait long enough to feel like one.
- **Speech outranks it.** It stops the instant a line starts — the voice is the payload.
- **It is the quietest thing the app plays**, well under the cue gain and far under `SIGNAL.peakGain`.

**Its own preference, `mpa.cues`, default OFF.** *Mute is a first path and silence is the default*
still binds: a preference we cannot read must not be guessed loud, and the bed proved within an hour
that an audible default is how a feature gets uninstalled. Separate from `mpa.audition` — one is "talk
to me", the other is "the machine makes noise".

**Correction, 2026-09-11:** the manual toggle is removed — cues are now always on, no persisted
on/off preference; `mpa.cues` no longer exists.

### Audition — speech, for ANSWERS (built 2026-08-16)

The row above rejects speech **as the alert signal**, and that rejection stands: a caution signal has
to work on a muted, offline, unattended screen, and a sentence is the worst possible shape for one.
This section is the *other* question — whether the agent's **answers**, which are already retained as
notice cards, should also be read aloud. They are, and the reasoning is that this is not a new claim
on the operator's attention: it is the same claim, in a second modality, for the moment they are under
a car and cannot look up.

**No new wire verb, deliberately.** The `toast {tone, message}` frame is what gets spoken — the very
frame the notice stack already renders. A `say()` tool would let the agent speak something that is not
on screen, which the binding rule below forbids outright, and the two surfaces could then drift into
disagreeing about what was said. One frame, two modalities, no third source of truth.

| Real frame / condition | The signal | Owner layer |
|---|---|---|
| any `toast {tone, message}`, **audition on** | the card's own sentence is **spoken once**, in the interface language, while its card is on screen. Never a summary, never a rephrase, and never a TRANSLATION — the spoken text and the printed text are the same string. **Corrected 2026-09-05:** it said "the same string"; it now means the same WORDS, pronounced. The spending summary read « · » chains as one run-on and « 250 347 » as two numbers, so `POST /tts` now runs the outgoing text through a pronunciation layer (`api/src/Speech/Verbalizer.php`) that rewrites symbols, units, digit grouping and separators for the voice and adds no content — like a screen reader's pronunciation dictionary. The card on screen is untouched; since the same day, standalone numbers are read as words (« deux cent quatre-vingts euros quarante-neuf »), identifiers such as « 5W-30 » or a plate are left as written | `voice.ts` + `useVoice.ts` |
| a second line arriving mid-utterance | it **waits**; one voice at a time, never a mix. Queue capped at **2** | `voice.ts` |
| a third line arriving while two wait | the **oldest pending** is dropped unspoken. Overrun keeps the newest: never hearing the latest answer is the worse failure, and the dropped line is still on screen at full precision | `voice.ts` |
| a line that waited longer than **10 s** | dropped **unread**. By the time it would be spoken it is no longer news, and a burst narrated a minute late is the alarm-flood failure with a delay bolted on | `voice.ts` |
| the **opening greeting** (added 2026-09-11) | spoken **once per session**, and never again. It is a one-shot ticker line (`voice.ts` `Utterance.once`, set by the `greet` event alone) and it is remembered in **`saidOnce`**, a list nothing prunes, so no later re-narration can queue it. A deliberate exception to the ticker rule in the row below — that rule exists because a tool step shown again later is genuinely news again, whereas a welcome is not. **Corrected 2026-09-11** (same day, second report — « once a sentence has been spoken we don't want to hear it a second time »): the first build put the one-shot line into `spoken`, which is the one field that gets cleared constantly — `follow()` prunes it to the texts still PRINTED as cards and a ticker line has no card, and the hook wiped the whole state whenever the audition went off, which it does on **every mic press** (`Atelier.tsx`: `audition && !listening`). So the welcome was forgotten seconds after it was said and re-spoken on the next click of the composer, which is exactly the symptom the `once` flag had just been added to fix. `saidOnce` survives both; only an autoplay REFUSAL takes a line back out of it, because a refusal is not a hearing | `voice.ts` + `state.ts` |
| the same `tone:message` already spoken | **not re-spoken while its card is still on screen** — the same dedup key the notice stack uses (`state.ts`), so a repeating pilot is one card AND one utterance. **Corrected 2026-09-05:** this row used to read "not re-spoken" with no qualifier, and `spoken` was in fact never pruned — a sentence was spoken once for the life of the TAB, not once per card. That silenced a second click on a deterministic chip (« ouverture du dossier… » said nothing the second time) and meant a generic StepNarrator line or a fixed acknowledgement could only ever be heard once per session. `follow()` now prunes `spoken` to the texts of the cards still printed before enqueueing, so a text is said only while its card is on screen — gone card, forgotten text, spoken again under a new one. The **ticker slot** (`NARRATION_ID`) is a further exception, also decided 2026-09-05: it never joins `spoken` at all — dedup'd only while speaking or pending, in `next()` — because it has no card to keep it "said"; a line the ticker shows again later is news again, the ear mirroring the eye. A one-shot line (the row above) is the exception to THIS exception, and it is remembered elsewhere — in `saidOnce`, never in `spoken` | `voice.ts` |
| the audition is **muted**, or the **mic opens** | everything PENDING goes: the line on the lips stops mid-word and the queue empties (`voice.ts` `silence`), because the lines behind it will be stale by the time it comes back and resuming them would read old news aloud. **What has already been SAID crosses the mute — both `spoken` and `saidOnce`.** A mute is not an un-saying, and treating it as one was heard twice over on 2026-09-11: first the welcome repeating on every mic press, then — since `follow()` re-enqueues every card still printed and it is `spoken` that holds those back — the whole visible notice stack read out again the moment the mic released. The audition goes off on **every** mic press, so "forgotten on mute" is not an edge case, it is the main path | `voice.ts` + `useVoice.ts` |
| **vu** on a card whose line is being spoken | the utterance is **cancelled mid-word** | `useVoice.ts` |
| audition switched off mid-utterance | the voice **stops at once** and the queue empties. A mute that finishes its sentence is not a mute | `useVoice.ts` |
| no vendor, no budget, no gesture, no network | **silence**, and nothing else — no toast, no badge, no retry | `useVoice.ts` |
| the **ticker line** (`narration`) — a `status` step, a non-alarm `toast`, a `concern` murmur — **audition on** (added 2026-08-17) | the sentence on the orb's live line is **spoken as it appears**. This is the commentary, not the conclusion: *"je relis l'historique"*, *"je compare les marques"*. Same string as the ticker, same language, never a rephrase. **Corrected 2026-09-05:** the step line used to land only when a garage tool call EXECUTED, at message end — a model that spent 17.7 s writing seven `plan_maintenance` calls in one message published nothing for that whole stretch, then all seven at once (measured turn `01M1SM291H06DJYFFRF0M8JT01`). It now lands when the model BEGINS the call (`ToolCallStart` on the stream, `App\Agent\Ui\EarlyStepNarrationListener`) — 18 s earlier on that turn — and the model-authored `narrate_step` line lands the moment its arguments are complete, not when the call runs. **Added 2026-09-05:** the generic step sentence is drawn from a pool of 3–4 variants per tool, never the same twice in a row within a turn; the pool is the catalog in `api/src/I18n/AgentMessages.php` | `voice.ts` + `useVoice.ts` |
| a newer ticker line while one is **waiting** | the waiting one is **replaced, unspoken**. The ticker never stacks (`state.ts:65` — "the single ticker line"), so neither does its voice: **one slot, latest wins**. The ear mirrors the eye — a step that was superseded on screen in 300 ms is not narrated 4 s later | `voice.ts` |
| a newer ticker line while one is **being spoken** | the spoken one **finishes**; the new one takes the slot. One voice at a time, and a sentence cut mid-word to start another is worse than a sentence that lands slightly behind the orb | `voice.ts` |
| a notice card and the ticker carrying the **same sentence** (every non-alarm `toast` sets both — `state.ts:351-357`) | spoken **once**. Dedup is on the TEXT, not on the frame identity, precisely because one frame legitimately lands on two surfaces | `voice.ts` |
| the notice queue and the ticker slot both holding a line | the **notice goes first**. A card is the answer and outlives the turn; the ticker is a heartbeat that is already gone | `voice.ts` |
| `play()` **refused** by the browser (Firefox with autoplay off for the origin, a background-tab policy) | the line goes **back where it was** and the audition **stops promoting** until the next real gesture, which re-arms it. Not marked spoken, not retried in a loop | `useVoice.ts` |
| a `proposeActions` card raised on the cradle — **audition on** (added 2026-08-17) | **the finding alone is spoken** when the card carries one, else the headline. **Corrected 2026-09-06:** this row used to read "its headline then its finding are spoken, in that order, as one utterance" — journal evidence (turn 21:58:34) caught the two saying the same thing twice: headline « Aucun entretien dans le carnet » atop finding « Aucun entretien n'a encore été enregistré pour cette voiture… » is one answer read twice, not two distinct lines. The headline restated the finding instead of naming a separate subject, so the voice now reads the finding alone; a headline-only card (no finding) still falls back to the headline. Both strings are still printed on the card — only what the VOICE reads changed | `voice.ts` + `useVoice.ts` |
| that card resolved, dismissed, or **superseded** by a newer proposal | the utterance is **cancelled**, same rule as a notice card's "vu". A superseded proposal is parked in the à-décider inbox, so its text is still printed — the ear simply stops mid-answer rather than narrating a card the cradle no longer holds | `useVoice.ts` |
| a `proposeActions` card **arrives** while notice lines are **waiting** (added 2026-09-06) | the waiting lines are **dropped unspoken**; the one being **spoken finishes** — never cut mid-word for a supersession (binding rule below); the card's finding is next. The card is the answer; a toast that already said the same thing is not read twice (`voice.ts` `supersedeQueued`, keyed on the CTA's own queued id) | `voice.ts` + `useVoice.ts` |
| a notice card and the **Data** panel on screen together, carrying the same subject | **both stay.** The panel is authoritative; the card is stamped with its AGE (`il y a 3 min` / `3 min ago`, the inbox's own vocabulary) and a `· see Data` mark. A card is a sentence written at a moment, the panel is the current reading — so the two are allowed to disagree, and the stamp is what makes the disagreement legible instead of messy. Decided 2026-08-17, after the operator reported "both don't have same info" | `NoticeStack.tsx` |
| a `showFact` / `focusView` panel raised (the **Data** surface) | **silent.** A panel is a table — a title and rows of values — and a table is the worst possible shape for the ear: "front tire dimensions, 16 pouces, tube dimensions, 16 pouces" is a recitation the operator cannot skip, arriving while the values sit on screen at full precision. Decided 2026-08-17, with speaking the title alone rejected too: a cue to look at a panel that just animated into the centre slot is a signal with no datum | — |
| the voice-select control (added 2026-09-06) | a picker sits beside the mute toggle in `Preferences.tsx`, shown **only while audition is on** — a voice choice with nothing to speak yet has nothing to demonstrate. Its list comes from `GET /tts/voices` (first entry is the backend's default), fetched once when audition turns on and again on a locale change, so it always names voices for whichever backend/locale is actually live; an empty list **hides the control**, never a picker with nothing in it. The choice persists in **`mpa.voice`**, beside `mpa.audition` (`voicePreference.ts`); `null` means the server default and is simply omitted from `POST /tts`, never sent as `voice: null`. A change **never cuts the line already speaking** — `useVoice` reads the preference at the moment a new utterance is QUEUED, not reactively, so switching mid-sentence finishes that sentence in the old voice and only the next line picks up the new one. **Correction, 2026-09-11:** audition has no off state any more, so the picker is now always eligible to render, gated only on the fetched voice list being non-empty; it still fetches on mount and on a locale change | `Preferences.tsx` (`VoiceSelect`) + `voicePreference.ts` + `useVoice.ts` |

**The `done`-label exception is gone (removed 2026-09-04).** The row above briefly carved out the
turn-end line as the one place the ticker's "same string, printed and spoken" rule did not hold — the
"posé · tout est à jour" visual label was nonsense read aloud, so a separate `resultSpoken` sentence
was split off for the ear. Turn-end no longer prints that label at all (§ "What a finished action
says"): `narration` at turn-end is now either a deterministic wayfinder's own closing sentence or a
real agent turn's own `toast`, either of which is already the one true string. The split, and the
exception it required, both collapse — there is nothing left for the ticker rule to except.

#### Binding rules specific to speech

- **The card is authoritative; the voice is best-effort.** These two caps differ on purpose —
  `NOTICE_CAP` is 3, the voice queue is 2 — so the stack can legitimately show a line that was never
  spoken. That is **by design, not drift**: three cards are readable at a glance, three spoken lines
  are a queue nobody can skip. Anything the ear misses is on screen; nothing is ever only spoken.
- **Speech is not a second announcement.** The stack carries no `aria-live` because the narration line
  already announces a non-alarm toast once and the inbox already carries an alarm. Speech is a THIRD
  rendering of that same sentence, which is exactly why it **ships muted and is never auto-enabled**:
  a screen-reader user who leaves it off hears their own reader, once. Turning it on is the operator
  saying they want the sentence twice, and that is their call to make, not a default to guess.
- **A dismissal is a cancellation.** Audio that outlives its own card is audio-only, and audio-only is
  forbidden. This is the reason the card list — not an event stream — is the input to the queue.
- **The ticker is a stream, so its voice may run one sentence behind.** The binding rule above is about
  a card being *dismissed* — the operator saying they are done with it. A `status` line being *replaced*
  is not a dismissal, it is the next heartbeat, and cutting the voice on every replacement would mean a
  pilot burst is heard as a stutter of half-words. So: a spoken ticker line finishes, and only the
  *pending* slot is overwritten. What this costs is that for ~2 s the voice can name a step the orb has
  already moved past; what it buys is that the sentence the operator does hear is a whole one. The
  10 s staleness rule still bounds the lag, and nothing here is audio-only — the journal holds every
  line the ticker ever showed (`state.ts` `journal`, kind `pulse`/`narration`).
- **Dedup is on the text, not on the frame.** A non-alarm `toast` writes a card AND the ticker line with
  the same string, so a frame-keyed dedup would speak every answer twice. The utterance's `id` still
  exists, but only for *cancellation* (which card's "vu" kills which line); what makes a line "already
  said" is the sentence itself.
- **One in-app turn has one closing toast — corrected 2026-09-12.** The server buffers bound-turn
  toasts and emits only the latest immediately before `turn:end`; the other render intents remain
  independent. An unbound pilot toast stays immediate. A terminal `proposeActions` discards the pending
  toast, because its finding is the answer.
- **A refused play is not a spent line.** Autoplay refusal is a property of the *page*, not of the
  sentence: the same line would play fine one gesture later. So a refusal puts the utterance back and
  latches the audition closed until a real gesture re-arms it — never a retry loop against a policy
  that will not change on its own, and never a line burned by a permission the operator can grant.
  A network/decode/unsupported failure is the *body's* fault, not the page's, so it spends the line and
  lets the queue advance: one undecodable body must never latch every line behind it closed.
  **How the refusal is read — corrected 2026-09-05.** With an `<audio>` element it was
  `HTMLMediaElement.error.code`: Firefox with autoplay disabled reported the refusal as an *abort* of
  the media load fired at the ELEMENT, not as a `NotAllowedError` rejection of `play()` (measured
  2026-08-17), and wiring `error` and `ended` to one handler had made the rule unreachable. The voice
  now plays through an `AudioContext` (§ "The voice starts before the browser asks"), and the signal
  is the context **staying suspended**: `speaker.ts` calls `resume()`, waits a bounded
  `SPEECH.resumeGraceMs` (Firefox leaves the promise *pending* rather than rejecting it, so it cannot
  be awaited) and reads `state` back. Not `running` → refused; a body the decoder cannot read →
  failed. `mediaOutcome()` and its media-error table are gone with the element.
- **The voice never seizes the session.** The `/tts` fetch deliberately does not use `authFetch`: that
  bounces to the login flow on a 401, and a garnish feature must never take the operator somewhere.
  A voice that cannot authenticate is a voice that stays quiet.
- **Autoplay is asserted, never assumed** (§ "The AudioContext trap"). A piloted session produces no
  gesture in this browser, so playback is unlocked from the operator's first real interaction. Until
  then a fresh line **waits in the queue** — it is not promoted, so it is not marked spoken. Corrected
  2026-08-17: the gate used to sit at the impure edge, *after* `next()` had already moved the line
  into `spoken`, so the first answer of every session was consumed in silence and unlocking later
  never brought it back — the one line most worth hearing was the one guaranteed to be lost. The
  10 s staleness rule still applies while locked: waiting on a gesture does not make old news new, so
  the lock can never open onto a backlog.

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
  one persisted toggle (`mpa.audition`, beside `mpa.recency`), in the **top-right chrome** beside the
  language switch (`Preferences.tsx`). It sat in the à-décider inbox until 2026-08-17, which made it
  unreachable at rest: the inbox is gated on `queue.length > 0` (`Noyau.tsx`) and force-closes when the
  queue drains (`SceneView.tsx`), so the mute only existed while a decision was pending — the opposite
  of a manual reset, and the requirement below is not conditional on unrelated state. A caution signal shall have manual reset and volume control
  (MIL-STD-1472F §7.2.9.11); WCAG 1.4.2 exempts audio under three seconds, which this is, but the
  affordance is not optional for that reason.

  **Correction, 2026-09-11:** the manual toggle is removed — audition is now always on, no persisted
  on/off preference; `mpa.audition` no longer exists. The mic still force-mutes audition while
  `listening` is true.
- **The voice follows the interface, and never translates it.** (Added 2026-08-16 with fr/en.) The
  spoken string is still the printed string, byte for byte — the audition may not put a translation
  layer between a card and its utterance, because then the room hears something the screen does not
  say, which is the one thing § "one signal" forbids. What the language DOES decide is pronunciation:
  the browser sends `Accept-Language` and `POST /tts` passes it as `language_code`, so a multilingual
  voice reads the same sentence the way that language reads. A card written in French by the agent is
  therefore spoken in French even while the chrome is English — correct, and the honest consequence
  of the same-string rule. Nothing about the queue, the cap, the staleness or the dedup key moves.
  **Corrected 2026-09-05.** It said: the spoken string is the printed string, byte for byte. It now
  says: the spoken string is the printed string's WORDS, pronounced — `POST /tts` runs the text
  through `api/src/Speech/Verbalizer.php`, a pronunciation layer that rewrites symbols, units, digit
  grouping and separators for the ear (like a screen reader's pronunciation dictionary) and adds no
  content, never rephrases. Why: real cards read badly aloud — « 250 347 km » as two numbers, a
  « · »-separated spending summary as one breathless run-on. This is not a translation layer (still
  no rephrasing, no summarising, no new content) and the card on screen stays byte-identical; only
  what reaches the vendor changes.
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
a vehicle), and that single unlock holds for the tab's lifetime. Since 2026-09-05 this is literally
the voice's own context — `useVoice.ts` creates and resumes it inside every `pointerdown`/`keydown`,
and `speaker.ts` asserts `state === 'running'` before each line (it used to be an `<audio>` element
blessed with a silent frame inside the gesture; same policy, same trap). It must then be **asserted**, not
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

## La dictée — the mic, for INSTRUCTIONS (planned 2026-09-11, latched 2026-09-11)

Everything above § Audition is the Atelier **answering** out loud. This section is the other
direction: the operator **instructing** it out loud, hands in an engine bay, because typing a plate
and a five-digit odometer reading on a laptop balanced on a wing is the gesture the whole product is
supposed to remove.

It is deliberately the smaller half. Dictation adds **no wire verb, no frame and no `UIAction`**: a
dictated line ends its life as the same `instruction` string that pressing Enter produces
(`Composer.tsx` → `Atelier.tsx` `send` → `POST /agent/turn`). The same argument that refused a `say()`
tool for the voice refuses a `dictate` intent here — the agent must not be able to tell the microphone
anything. The mic is an input method for the one composer, not a channel.

| Real gesture / condition | The motion | Owner layer |
|---|---|---|
| the operator **presses** the mic control in the composer | Le Noyau enters its existing **`listening`** phase — pupil dilated to `r: 22px`, the circumference ring tracing (`noyau.css:94-122`). This phase already exists and already means "a signal is being received"; until now it was dispatched on composer *focus*, i.e. as a metaphor for typing. Dictation makes it literal, and nothing new is drawn for it. **The mic has its OWN event since 2026-09-11** (`state.ts` `listening`, split from `typing`): an open microphone takes the orb from a **finished** turn and puts out its result lamp with it, where a caret landing in the composer does not. It still never interrupts a **running** turn — that turn owns the orb, and the mic is inert while busy anyway | `Composer.tsx` + Le Noyau (`state.ts` `listening`) |
| …and the browser has **not handed the stream over yet** (the permission prompt, or a first-use delay) | the control shows a **dimmed amber** `⏺` (`data-phase='arming'`, `scene.css`) — amber because a recording was asked for, dimmed and unglowing because the room is **not** being heard yet. Le Noyau's `listening` cue is held from the PRESS, not from the stream: a mic that opens mid-sentence would hear the Atelier finish its own line. The **cap does not run** during this — it is measured from the stream (`dictation.ts`) | `Composer.tsx` + `dictation.ts` |
| the operator **presses again while it is still arming** | the gesture is **cancelled**: no audio existed, so nothing is transcribed, and the stream that lands a moment later is closed instead of opened (`useDictation.ts` `pending`) | `dictation.ts` + `useDictation.ts` |
| **audition is on** and a line is being spoken when the mic opens | the utterance is **cancelled mid-word** and the queue empties, exactly as a `vu` does. The microphone must never transcribe our own voice, and a duplex Atelier is a feedback loop, not a conversation | `useDictation.ts` + `useVoice.ts` |
| the operator **presses again** while recording | the phase leaves `listening` and the **mic control** carries the wait (dimmed, glyph `⋯`) while the audio is in flight to `POST /stt`. Le Noyau does NOT enter `working` — no turn has started, nothing is being computed on the operator's garage yet. **Corrected 2026-09-11** (same day, before shipping): this row first put the pending treatment on the composer INPUT; the build puts it on the control instead and leaves the input fully usable, because a transcript that is merely in flight must not stop the operator from typing — the keyboard is the path dictation is a convenience over, and blocking it for 1–2 s would make the convenience a cost | `Composer.tsx` + `scene.css` |
| the transcript **arrives** | it **starts the turn**, immediately and on its own (`dictation.ts` `submission`, reversed 2026-09-11 — see the binding rule below). The line sent is the transcript **appended** to whatever was already typed, one space between, never replacing it — the operator may have begun typing and finished by voice, and destroying typed text is the one thing a convenience must not do (`dictation.ts` `merge`) — and the composer is cleared exactly as Enter clears it | `Composer.tsx` |
| the transcript arrives but **cannot** be sent — a turn is already running, or the composer is mounted inert (the playground) | it is written into the input as **editable text**, cursor at the end, and waits for Enter. The words are never dropped: clearing the composer into a `send` that no-ops while busy would lose a dictated sentence outright | `Composer.tsx` + `dictation.ts` |
| the transcript arrives **empty** (silence, a mis-press, a room too loud) | the input is left exactly as it was and **nothing is said, shown or logged**. A mis-press must cost one gesture, not a card to dismiss | `useDictation.ts` |
| `POST /stt` **refuses or is unreachable** (503, no backend, no network) | the input is left as it was, the mic control returns to rest, and the operator types. Same degraded mode as the voice: silence, no toast, no badge, no retry | `useDictation.ts` |
| the mic **permission is denied** (or the page is not a secure context) | the control **disappears** for the session rather than sitting there re-prompting. A browser permission the operator refused is an answer, not an error state to decorate | `useDictation.ts` |
| a recording running longer than the cap (**30 s**) | it **stops itself** and transcribes what it has. An instruction is one line; a mic the operator forgot to close is the failure this bounds — and it bounds more now the control latches than it did when a thumb had to stay on it | `dictation.ts` |
| a **turn is busy** | the mic control is inert, like the composer's own submit (`Atelier.tsx` `send` no-ops while busy). One instruction at a time, on both input paths | `Composer.tsx` |

**Why the mic outranks the done dwell — corrected 2026-09-11, reported live.** Both the caret and the
mic dispatched `typing`, which `state.ts` refuses while the phase is `working` **or `done`**. A turn's
result stays on the orb for `DWELL.settle` (1600 ms), so pressing the mic inside that window showed no
cue at all — and then `settle` fired and dropped the orb to **veille with the microphone still open**.
An open mic is a state the operator has a right to see; a finished turn's lamp is not worth hiding it
for. Auto-submit is what made this the ordinary path rather than a corner: finish a turn, press the mic,
go again.

**The red fault lamp could never finish a blink — same date, same report.** `nx-fault` ran
`0.9s … infinite` inside that same 1600 ms dwell, so the red reticle was chopped mid-fade at roughly
half opacity *every* time a turn settled red, which reads as a rendering artifact rather than a signal.
It is now `0.8s × 2` — two whole blinks landing exactly on the dwell — and `motion.test.ts` pins the two
numbers together so neither can drift alone.

### Binding rules specific to dictation

- **A transcript sends itself. REVERSED 2026-09-11**, hours after the rule below shipped and on the
  operator's own use of it.

  The rule was: *"Nothing is submitted unheard. The exact mirror of the voice's nothing is audio-only:
  a transcript becomes a turn only after it has been printed in the composer where the operator can
  read and correct it. Auto-submit was rejected on 2026-09-11, and not on feel: a turn **writes to the
  garage** — `log_maintenance` against a misheard vehicle, an odometer reading off by a digit — and
  there is no undo seam for a turn. The mic may save the typing; it does not get to skip the
  confirmation the keyboard has always required."*

  What retired it: the whole reason this mic exists is **hands that are in an engine bay**, and a
  gesture that still ends at a keystroke gives back most of what it was for. The operator, having used
  the Enter version, asked for the send.

  **The risk that was traded away is real and is not argued away.** A misheard plate or a digit lost
  from a five-digit odometer reading now reaches a turn that writes, with no beat in between. What is
  left standing in its place: the transcript is still **printed** — it goes out through the same
  `send()` the keyboard uses, so it lands in the journal and on the ticker; the turn is **cancellable**
  while it runs (the hero's ✕); and a line that cannot be sent (busy, or an inert composer) still waits
  in the input for Enter, so nothing dictated is ever dropped. If a misheard write does land, the
  repair is `edit_maintenance`, not an undo — and that is the cost the operator accepted.
- **One gesture, latched: press to start, press again to stop. No wake word, no always-listening, no
  VAD.** § Not in the talk rejected a talking dashboard over a conference PA; an *open microphone* over
  that same PA is the worse twin — it hears the audience and the presenter's own answer. The mic opens
  on a press and closes on the next one, and the operator can see it is open (§ the `listening` row
  above) for exactly as long as it is.

  **Corrected 2026-09-11**, hours after the push-to-talk build shipped and the first time it met a real
  microphone. The rule was *"push-to-talk only … the mic opens on a press and closes on a release"*, and
  it **cannot work in a browser**: `getUserMedia` resolves behind a modal permission prompt that takes
  the pointer, so the release always arrived before the stream. Every click transcribed a recorder that
  did not exist yet (reported as "clicking record records nothing") and left the stream that arrived
  afterwards open with nothing holding it. A hold is also the wrong gesture for a laptop trackpad, which
  is the hardware this is demonstrated on. What the original rule was actually protecting — nothing
  opens the mic but a deliberate gesture, no VAD, no wake word, and the open mic visible for exactly as
  long as it is open — **all of it survives the correction**; only "closes on a release" is reversed,
  and the 30 s cap is what now bounds a mic left open.
- **The mic is never on by default and never re-armed automatically.** Same posture as the audition:
  it ships off, one gesture turns it on, and it makes no claim on the operator's hardware until then.
  Unlike the audition it needs no preference toggle — the press *is* the opt-in, and the browser's own
  permission prompt is the durable one.
- **The composer stays the single input.** Both mounts (hero via `SceneView`, parc landing via
  `Atelier.tsx`) already render one `Composer`; the mic control goes **inside** it, not into
  `composerExtras`, so there is exactly one implementation and one place the busy rule is enforced.
  "The composer moves, it is not copied" — a second mic would be a copy.
- **The transcript is the operator's words, verbatim.** No rephrasing, no cleanup, no "did you mean".
  The voice has a pronunciation layer (`Verbalizer`) because the ear needs one; the agent does not — it
  reads the raw sentence, exactly as it reads a typed one. A transcript that says « cent quarante-deux
  mille kilomètres » is handed over as those words, and the agent's own parsing turns that into a
  number, as it already must for typed French.
- **Silence is a degraded mode, never a broken one.** Every failure path above ends in "the operator
  types". Dictation is a convenience over a keyboard that still works; it is never the only way to say
  something.

## The opening — the workshop speaks first (added 2026-09-04)

Until now the Atelier opened mute and still: a rail of vehicles and a composer, with no statement of
what it is or what it can be asked. The operator had to know. Two lines fix that, and they are
**deterministic** — no agent turn, no model call, no token.

| moment | what happens | where |
| --- | --- | --- |
| the tab's **first settle**, no vehicle selected | the welcome, drawn from `greeting.welcome`: « Bonjour Soyuka, sur quelle voiture travaille-t-on ? » — the operator's name **once**, then the question the parc is there to answer. Up the instant the data settles, because it is data, not a call | `Atelier.tsx` + `greeting.ts` |
| the tab's **first settle** with a car **already open** (a direct load of `#/v/<seg>`) | **one** line does both jobs, drawn from `greeting.welcomeVehicle`: « Bonjour Soyuka, on reprend la Mazda 6 ? ». A welcome followed half a second later by a vehicle line is the ticker talking over itself, which is exactly what the generated version did | `Atelier.tsx` + `greeting.ts` |
| a vehicle **becomes selected** later (rail click, URL hash, `select_vehicle` from a turn) | one ticker line naming it and offering the next move, drawn from `greeting.vehicle` — « Mazda 6 sélectionnée, que veux-tu savoir ? » — with the deterministic wayfinder chips already beside the composer as the suggestions. **No second hello**: a workshop that greets you by name every time you walk back is wallpaper | `Atelier.tsx` + `greeting.ts` |
| the same vehicle re-selected, or a re-render | **silent.** The greeting fires on a CHANGE of selection, never on a repaint — a workshop that says hello every render is a workshop nobody leaves open | `Atelier.tsx` |
| **back** to the parc, later in the session | **silent.** The operator is looking at every car they own, which answers "which one" already, and a line here would be on screen for as long as they browse — that is what got the old fixed « Sur quelle voiture travaille-t-on aujourd'hui ? » removed on 2026-09-11 (the parc heading used it as its fallback, so it was permanent wallpaper rather than a greeting) | `greeting.ts` |
| a greeting line while a turn is **working** | **not emitted.** The ticker belongs to the turn; a greeting is what the app says when it has nothing else to say | `Atelier.tsx` |

**The lines are POOLS, and a drawn line is not drawn again while a fresh one is left.** Four or five
sentences per pool, drawn with the pool's own bookkeeping (`greeting.ts` `drawGreeting`, `Drawn`), not
with a bare `Math.random()`. The reason is the audition, not taste: a sentence already spoken is never
spoken twice in a session (§ Audition, `voice.ts` `saidOnce`), so a repeat would print on the ticker and
then be **silent** — which reads as a broken voice rather than as a repeated line. When a pool is
exhausted the cycle starts over, minus the line that just spoke.

**Never two hellos, and never a name twice.** `greetingForSelection` distinguishes the tab's opening
(`previous === undefined`) from every later selection, and only the opening carries `hello(name)`.

**Why the ticker and not a toast.** A greeting is transient commentary, which is exactly what the
ticker is for (§ "Audition — speech, for ANSWERS": *"This is the commentary, not the conclusion"*). A
`toast` would mint a notice card — a dismissible thing that outlives the turn — and « Bonjour » is not
an answer anyone needs to keep or tick off. It also gets the audition for free: the ticker line is
spoken when the operator has it on, and it is journaled, so **nothing here is audio-only.**

**Its own event, `greet` — corrected 2026-09-04.** This row first said the greeting rides the existing
`status` event. It cannot: `status` sets `phase: 'working'` unconditionally, and nothing brings it back
to idle but a `turn:end` — which itself overwrites `narration` with the done-label. A greeting sent as
`status` would therefore pin `busy(state)` true forever, deadlocking the composer and every chip, and
then erase its own text. `concern` docks an alarm into the à-décider queue and `toast` mints a card,
so neither fits either. `greet` is the minimum: it sets `narration` and appends one `'narration'`
journal entry, and touches **neither phase, nor queue, nor worstTone**. It is a LOCAL `NoyauEvent`, not
a wire frame — `CONTRACT.md` is unaffected and no new agent verb exists.

**Why deterministic and not an agent turn — reversed 2026-09-11, then reversed BACK the same day.**

The model-written opening is **gone**, and so is `GET /agent/greeting` (`GreetingWriter`, `SpokenName`,
`GREETING_MODEL`, the `^/agent/greeting` access-control rule — all deleted, not deprecated). The lines
above are drawn from the i18n catalogs. The operator's verdict on the generated line, having seen it
live: *« the greeting sentence sucks »* — and, offered a stronger model (Gemini Flash) instead:
*« I think I want to rollback on a fixed list of different sentences instead of using LLM for it, it'll
be better in the end »*.

Why that is the right call and not a retreat: everything the generated line could actually buy was
**variety and the operator's name**, and a pool of five sentences with a name slot buys both — for zero
latency, zero tokens, no vendor, no beat of empty heading at arrival, and a line that is reproducible
from the code. What the model bought instead was unpredictability on the app's **first** sentence, which
is the one beat where the operator is deciding whether the thing works; it also needed a constraint list
(no vehicle, no fact, no persona, no examples) long enough to be evidence that the wanted output was
already a template. The two decisions below are kept because they are still true of what replaced it —
the reasoning that removed the fixed sentence, and the reasoning that keeps the garage out of the line.

<details>
<summary>The generated-opening decision as it stood for a few hours on 2026-09-11 (kept, not erased)</summary>

This section used to end here, with: *"A model call on arrival buys nothing the data does not already
answer — which vehicles exist, which one is selected — and costs 3–10 s of dead screen before the first
word, on the one beat where the operator is deciding whether the thing works."* The operator asked for
a generated opening anyway, having been shown that cost, and the reason it no longer applies is that
the generated line **does not gate the arrival**:

| moment | what happens |
| --- | --- |
| the data settles, a **vehicle selected** | its deterministic line goes up immediately — it acknowledges the click, so it cannot wait on a model |
| the data settles, **nothing selected** | the heading stays **empty** |
| once per tab, on the **first** greeting only | `GET /agent/greeting?name=` asks a small model (Haiku) for one line, given the operator's display name **and nothing else** |
| it answers (~1–3 s) | that line takes the ticker — one slot, latest wins, the rule the ticker already had |
| it fails, 503s, or a turn started meanwhile | **nothing happens.** No greeting at all that session |

What the reversal buys: a welcome that is different every session and addresses the operator by name.
What it costs, stated plainly: one Haiku call per tab, a greeting no longer reproducible from the code
alone, and — since the fixed line was removed on the same day — **a beat of empty heading at arrival**,
which is a smaller version of the objection the original decision raised. The operator asked for both
changes knowing that; a placeholder standing in for the removed sentence would have been the same
sentence wearing a different hat.

**The prompt knew nothing about the garage**, and that constraint is now a rule about the POOLS: no
line names a vehicle it was not handed, and no line states a fact — no number, no date, no service, no
fault, nothing due. Measured, not aesthetic: the operator does not want cars in a welcome message, and
when the vehicle names WERE in the prompt the model invented facts about them — « Soyuka, le TORO
attend ses roues ou tu commences par la MAZDA6 ? », written about cars it knew only the names of. A
`greeting.vehicle` line may name the selected car, because the caller hands it that name; it may say
nothing else about it. The endpoint's owner-scoped read went with the endpoint.

**No persona, and no examples that become the answer.** A model left alone introduces itself as an
assistant, which is the one thing this line must not do; and an intermediate version of the deleted
prompt listed example phrasings to set the register, which the model then copied word for word. Both
are why the replacement is a list of finished sentences rather than a better prompt — the pool IS the
register, and there is nothing left to echo.

</details>

**It never becomes a turn.** Still a local `greet` event: no `status` (which would pin `busy` forever),
no `toast` (which would mint a card for « bonjour »), no journal entry beyond the ticker's own, no wire
verb and now **no endpoint at all** — `CONTRACT.md` gains no row, because the line never leaves the
browser.

**The suggestions are the chips that already exist** (§ "Deterministic-first", `buildQuickActions`),
not a new surface. They keep their own rule: a chip whose data prerequisite is absent is **hidden**,
never shown disabled — so the offer is always real and there is no dead control to click.

**`prefers-reduced-motion`:** nothing to collapse. Two text lines have no motion, which is the point.

## The agent is on the home screen too (added 2026-09-04)

**This fixes a rule violation shipped hours earlier.** § "The opening" gave the parc landing a greeting
line — « Bonjour, on s'occupe de quel véhicule ? » — but the parc landing renders only its title, the
identity chrome and `ParcLot`. There is no `Noyau` and no composer there, so that greeting was
**dispatched into state with nothing on screen to show it**: spoken when the audition was on, never
printed. Audio-only, which the binding rules forbid outright. The greeting was right; the surface was
missing.

| moment | what happens | where |
| --- | --- | --- |
| the parc landing (`#/`) | it carries an **agent console**: the greeting as the largest line on the page, a composer beneath it, and Le Noyau's orb for turn feedback. The greeting is PRINTED here and spoken from the same string — one sentence, two modalities, which is the binding rule satisfied rather than worked around | `Atelier.tsx` |
| the greeting itself | « **Sur quelle voiture travaille-t-on aujourd'hui ?** » — replaces the static « Parc » heading rather than sitting beside it. A title naming what the page obviously is, next to a question inviting the operator to act, is two headings competing; the question does both jobs | `Atelier.tsx` |
| the operator answers in the composer (« la Mazda ») | a **normal turn**, from the parc. The agent has `list_vehicles` and `select_vehicle`, so its answer is to drive that car in — the question is answered by the UI moving, not by prose. This is the whole reason the console belongs here | `Atelier.tsx` |
| suggestions | **none added.** `ParcLot` already shows every vehicle, and the cars ARE the choices — § "The opening" already said the rail is the answer. A chip row duplicating the lot would be a second way to do the same thing | — |
| a turn working from the parc | the orb works exactly as in the hero, and the composer locks. One driver at a time, unchanged | `Atelier.tsx` |
| the agent selects a vehicle | the hash changes and the Atelier drives into the hero, the same as a click on the lot. No new navigation path | `Atelier.tsx` |

**The composer moves, it is not copied.** It is inlined inside `SceneView` today (which takes a
`bundle` and owns the 3D car), so it gets extracted into a component both surfaces mount. A second
composer implementation on the parc would be two inputs to keep in sync, and the busy/lock rule would
be enforced in two places.

## What a finished action says (added 2026-09-04)

Turn-end used to announce a label keyed on **tone**, not on what happened: « posé · tout est à jour ».
Two things were wrong with it, and only one was the wording.

- **It claimed something it had not checked.** Opening the Données panel is not a health assessment,
  yet it announced *tout est à jour* — an assertion about the vehicle, produced by an action that read
  nothing about the vehicle's condition. A tone is not a finding.
- **It was written to be seen, then got spoken.** « posé » is a visual metaphor about the orb settling
  and the `·` is silent, so aloud the line is a stray participle followed by a clause.

**Removed 2026-09-04.** Replaced by a sentence per action — what it actually did, addressed to the
operator, ending in an invitation where one makes sense:

| action | what it says when it lands |
| --- | --- |
| **Données** | « Voici les données collectées sur la Mazda 6. Cherchez-vous quelque chose en particulier ? » — names the car, and hands the turn back |
| any deterministic wayfinder | its own closing sentence, supplied by the chip, naming what was opened |
| a real agent turn | **nothing generic.** The agent's own `toast` is the answer; a second machine-written line after it is noise |
| a turn that narrated **nothing at all** | **silence.** There is no fallback sentence, deliberately: the signal derives from tone, and `resultFromTone(null)` is `green`, so a generic fallback would have a turn that did nothing — or failed — announce « Tout est à jour ». A verdict on the vehicle, invented by an action that never looked at it, in the one case where nothing is known. The journal row still records the **signal word** (`vert`/`ambre`/`rouge`), which is a fact about the outcome rather than a claim about the car |

The signal (green/amber/red) is unaffected and still carried as text, which is what the original a11y
fix was for (`noyau/CRITIQUE.md`): the orb's `aria-label` renders `t.noyau.signal[result]`
(`Noyau.tsx:63`) and the journal's done row renders the signal word. The removed label was a third
carrier of the same fact, never the only one — verified before deleting it.

## The voice starts before the browser asks (added 2026-09-04, corrected 2026-09-05)

Synthesis costs ~3 s for a short line and ~8 s for a medium one (`docs/voice.md` § Streamed). Where
that time sits decides whether the workshop feels alive or laggy, and until 2026-09-04 it sat in the
worst possible place: the `/tts` fetch lived inside the *"speak the promoted line"* effect, so a line
did not begin synthesizing until it reached the lips. A line queued behind another waited for its
predecessor to finish before starting — the delay compounded down a burst.

**Corrected 2026-09-05 — the wait was never the synthesis, it was waiting for ALL of it.** pocket-tts
streams: the first 80 ms of audio is on the wire ~250 ms after the request and the rest follows at
0.77× real time. `/tts` buffered that body to completion before answering, so the browser's first
byte arrived when the LAST one did — 3.2 s after the card for a short line, 10.4 s for a medium one
(measured through Caddy, `docs/voice.md`). The operator read the card and then waited; the gap read
as broken because it was three to ten times longer than any latency a listener forgives. The fix is
end to end: `/tts` relays each chunk as it lands (`SpeechController`, `SpeechStream`), and the ear
schedules each one on the audio clock as it arrives (`noyau/speaker.ts`, `noyau/pcm.ts`) instead of
handing a finished blob to an `<audio>` element. First sound is now 0.2–0.5 s after the card.

| moment | what happens | where |
| --- | --- | --- |
| a line enters the queue while another is speaking | its audio is **fetched immediately**, in parallel with the line being spoken, so it is ready at the handover. One synthesis in flight per waiting line, not one at a time | `useVoice.ts` |
| a line reaches the lips | it is **heard from its first chunk** — 24 kHz PCM pieces of 80 ms, each placed flush against the previous one on the audio clock, the first after 160 ms of audio is in hand so one late piece is a wobble and not a hole in the first word. Never a whole-body wait; never `setTimeout` (§ Binding rules) | `speaker.ts` + `pcm.ts` |
| ~~the server publishes a `toast`~~ — **removed 2026-09-05** | ~~synthesis is already under way before the frame reaches the browser — a prewarm job synthesizes into the cache so the browser's `/tts` is a hit~~. Measured false: the prewarm and the browser's own request left within the same few tens of ms and BOTH missed, so pocket-tts synthesized the line twice at once, and two concurrent syntheses run at ~1.1× real time each against 0.77× alone — the prewarm made the line the operator hears ~30 % SLOWER, and under streaming it would have made it stutter (generation behind playback). The first line of a turn is fast because it streams, not because it was guessed ahead | `api/` |
| the same sentence a second time | **no synthesis at all** — disk cache keyed `sha256(backend·model·voice·locale·text)`, written from the COMPLETED body once the stream has been consumed (a stream that dies part-way caches nothing). The Atelier repeats itself constantly (« Vidange enregistrée », « J'ai pris la Mazda ») | `api/` |
| a stream that stops early — the sidecar dies mid-line, the operator dismisses the card | the browser plays **what arrived** and the cache keeps **nothing**. A half-line answering forever would be worse than a miss | `api/` + `speaker.ts` |

~~**Prewarm is unconditional** (decided 2026-09-04)~~ — **the prewarm is gone (2026-09-05)**, message,
handler and routing with it, for the measured reason in the struck row above: it never won the race
with the browser and only ever doubled the CPU load on the one synthesis that mattered. What remains
of this section is the client-side fetch-ahead of WAITING lines, which does not race anything — the
line it fetches is not being asked for by anyone else — and the disk cache. Should a server-side
head start ever be wanted again, it has to be single-flight (the browser's request joins the
in-progress synthesis rather than starting a second), not a second request.

### The seam between two lines is gapless (added 2026-09-11)

Everything above is about the first sound of ONE line. This is the join between two, which until now
was a hole: the next line was promoted only when the previous had finished *playing*, so the seam cost
a React round-trip plus `primeSeconds` + `leadInSeconds` (≈190 ms) of fresh buffering, on top of
whatever trail-off the model had left. The operator heard it as a hesitation that got chopped —
« un "euh" entre les phrases » (2026-09-11) — and was right about the cause: *the sound was not cut,
the next sentence simply did not follow it*.

| moment | what happens | where |
| --- | --- | --- |
| a line's body is **fully placed** on the audio clock (~0.3 s into a 3.2 s line — the body arrives ~12× faster than realtime, measured `docs/voice.md`) | the next line is promoted **immediately**, while the first is still audible, and its first sample is scheduled **flush against the last sample of the previous one**. No silence, no re-priming — the two lines are one continuous signal | `voice.ts` `next` + `speaker.ts` |
| a line is **audible** vs merely **placed** | two different states, and they had been one. `onAir[0]` is what the operator is hearing — it is what `speakingId` reports, so a notice card's read-dwell still cannot start until its OWN line is off the lips. `onAir[1]` is committed behind it and is not yet "speaking" to anything else | `voice.ts` |
| a line already **on the clock** is superseded, goes stale, or is cap-evicted | **it plays.** Supersession, staleness and the cap apply to the QUEUE — to lines not yet committed. Un-scheduling a line that is already flush behind the current one would be a cut, and cuts are the thing this section removes | `voice.ts` |
| the next body is **not ready** when the current line ends | it lands late, exactly as before: the clock places at `max(now + leadIn, previousEnd)`, so a slow vendor degrades to the old seam instead of dropping the line | `speaker.ts` |
| a card is **dismissed**, the audition is muted, or the **mic opens** | both the audible line and the one behind it stop. A scheduled source can be stopped before it starts, so a committed line is still cancellable — committed means "will not be silently dropped", not "cannot be cut by the operator" | `voice.ts` + `useVoice.ts` |
| the two lines on the clock carry the **same id** (both ticker lines are `narration`) | they are still two lines. The hook files each one's fetch, its audio source and its « fully placed » signal under `utteranceKey` (id + text), never under the id, which stays purely what a dismissal cancels. Keyed by the id, the second ticker line was never started, never ended and held the head of `onAir` for the rest of the session: the pipeline sat full at two and every later answer was queued and never read aloud (found and fixed 2026-09-18) | `voice.ts` `utteranceKey` + `useVoice.ts` |

**At most two lines are on the clock**, never the whole queue. One line of lookahead is all a gapless
seam needs, and scheduling further would commit lines that the burst rules (§ Audition: cap 2, stale
at 10 s) are specifically there to be able to drop.

**No holding of the text.** It was on the table to delay the card until the audio was ready so the
two would land together. Not done: with first sound at 0.2–0.5 s there is nothing left to hide, and
hard rule 1 (*motion may overlap an arrival, never gate one*) stands unchanged.

## Asking the agent from the UI — the judgement chip (added 2026-09-04)

The deterministic wayfinders (§ "Deterministic-first") answer everything the stored data already
answers, with no turn and no token. This adds the other half: a chip that **does** run a turn, for the
questions data cannot answer.

**The dividing line, and it is the whole rule.** A chip is deterministic when the answer is a *value
or a surface* — open the carnet, list the references already chosen, show the oil capacity. A chip is
an agent chip when the answer is *language or judgement* — « tu as surtout dépensé en freinage cette
année, et la distribution est le prochain gros poste ». No panel can produce that sentence and no
amount of formatting turns a table into it. If a proposed agent chip could be answered by opening a
panel, it is a deterministic chip written wrong.

| moment | what happens | where |
| --- | --- | --- |
| an **agent chip** is clicked (« Résumer les dépenses ») | it posts a real turn with a fixed instruction, and the orb enters `working` for real — `turn:start`, the model's own `narrate_step` lines, then the answer as a `toast`. Identical to a typed question, minus the typing | `Atelier.tsx` |
| the answer lands, **audition on** | it is spoken, because it is a `toast` and toasts are spoken. This is the point of the feature: « demande à l'agent de résumer les dépenses » → you *listen* to the answer instead of reading a table | `useVoice.ts` |
| an agent chip while anything is **working** | **disabled**, not queued. One driver at a time — the rule the composer and the deterministic chips already share (`busyRef`) | `Atelier.tsx` |
| an agent chip whose data prerequisite is absent (no visits logged → nothing to summarise) | **hidden**, exactly like a deterministic chip. Never a dead control, and never an agent turn that can only answer "there is nothing" | `Atelier.tsx` |
| the turn fails, is cancelled, or returns no tool call | the existing turn-failure path, unchanged. An agent chip earns no special error handling — it is a turn | `Noyau.tsx` |

**It must look different from a deterministic chip.** They cost different things: one is instant and
free, the other spends a model call and takes seconds. An operator choosing between them on a
projector deserves to know which is which before clicking, so the agent chip carries a mark and its
own label register — never the same flat pill as « Données ».

**Why not `propose_actions`.** That already renders NL follow-up buttons `{label, instruction}` that
re-run a turn on click, and this deliberately reuses the same *mechanism*. The difference is who
offers it: `propose_actions` is the agent proposing a next step it chose, mid-conversation. This is the
UI offering a standing capability that exists before any turn has run — there is no agent yet to
propose it.

### Opening Historique also asks for a summary (added 2026-09-04)

A register of sixteen visits answers « qu'est-ce que j'ai fait ? » and cannot answer « où part mon
argent ? ». Opening it therefore does both: the panel at data speed, the reading a moment later.

| moment | what happens | where |
| --- | --- | --- |
| the Historique chip is clicked | the panel **opens instantly**, exactly as today — deterministic, no turn, no waiting. Hard rule 1: the model may overlap the arrival, never gate it | `Atelier.tsx` |
| at the same instant | a turn is posted asking for the spend reading. It lands as a `toast` when the model has it, so it is **spoken** and it is a card — never audio-only | `Atelier.tsx` |
| the summary arrives after the operator has closed the panel | the card still lands. It is an answer to a question they asked, and answers are not cancelled by looking away — the panel was never its only home | `state.ts` |
| Historique opened while a turn is **working** | the panel opens; **no turn is posted**. One driver at a time, the rule the composer and every chip share | `Atelier.tsx` |
| no logged visits | the chip is **hidden** already (its existing prerequisite), so neither half can fire | `Atelier.tsx` |

**This is the one place a deterministic chip also spends a model call**, and it is worth saying why
that is not a violation of "Deterministic-first": the panel is still deterministic and still instant.
The turn buys the sentence a table cannot say. If the summary is ever cut, the panel is unaffected —
the two halves fail independently by construction.

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
  The same split holds on the stage (§ "The hologram is the light", 2026-09-04): the dark room, the
  hologram's fresnel, the emitters' colour and the freed orb's size are state and stay; the breathe
  freezes mid-swell, the scanlines freeze in place, the flicker never fires, colour changes cut.
- **Cheap idle** (`docs/prompt-pilot-motion.md` hard rule 6; added 2026-09-04) also bounds what a
  hologram may do at rest. The stage's continuous motions all read ONE clock sample per frame
  (`clock.elapsedTime`) and each costs a uniform write: the breathe (one period, protected), the
  light-shaft drift (`HoloPodium`, already there), and now the hologram's scanline drift
  (`HOLO_SCAN_PERIOD`) — the drift earns its frame because it is motif (b), the one visual that says
  "projection" on a still car (`docs/design-inspiration.md` § 7). That is the whole idle budget: no
  constant flicker, no per-frame allocation, no light hunting a target it has already reached (the rig
  early-returns once its blend is complete), and no fourth continuous motion without its own row here.
  A reveal's part meshes (2026-09-04) add no motion to that list: they read the SAME scanline clock
  sample, one uniform write each, and exist only while a reveal holds — a hold is attention the operator
  asked for, not idle. The idle budget is unchanged.
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
