# Atelier choreography map — FROZEN in Stage B.0

The single binding of the **real** agent vocabulary (and Mercure domain frames) to Atelier motion.
Written in the verified `UIAction` / frame names only — never the `docs/ui.md` sketch names. Every
Stage-B/C component animates against THIS map; no agent invents a different mapping.

## Agent → UI intents (session topic)

| Real frame | Atelier motion | Owner layer |
|---|---|---|
| `selectVehicle {id}` | the car **drives forward** into Le Plateau; previous car recedes into the lot (~350ms cross-fade) | scene assembler |
| `showFact {keys, title?}` / `focusView {panel:'specs', keys?}` | the **spec constellation** fans the matching `vehicle.facts` into the RIGHT **projection column** (staggered **etch-in**). Empty `keys` ⇒ the whole sheet | scene overlay (`SpecConstellation`) |
| `focusView {panel:'tasks', keys}` | resolves the first key to a body region (`regionForFrame`) and **spotlights it by dollying the earned camera there** (D1) + a one-shot cyan pulse on the region's edges — the move itself is the spotlight, no separate glow/callout layer. The region's Entretien-rail row(s) light with the orange operator cue (`data-camera`) | scene assembler (`cameraFocus`) + `EntretienRail` |
| `focusRegion {region}` | same earned-camera dolly + pulse as above, addressed directly by region. **Client-synthesized today** (a contract seed — the client already dollies for a `focusView`/`showFact` that resolves to a region, so handling both is idempotent; not yet emitted by the live agent) | scene assembler (`cameraRig`) |
| `showParts {service, taskId, parts?}` | projects the **parts manifest** into the RIGHT projection column (rows **unfurl** staggered), primary **Ajouter** = a deterministic `patchTask` merge. **Client-synthesized today** by the "Pièces dispo" wayfinder chip — the identical shape a real parts-bearing `proposeActions` produces (see below), so chip and agent answer are visually indistinguishable | scene overlay (`PartsManifest`) |
| `showHistory` | raises the **carnet projection** (past visits + spend) into the same RIGHT column. **Client-synthesized today** by the "Carnet · dépenses" chip | scene overlay (`CarnetProjection`) |
| `proposeActions {headline, finding?, actions}` | a plain proposal → a CTA card **rises from Le Noyau's cradle** (behind the composer, never over it); a **parts-bearing** proposal (an `actions[].patchTask` with `parts`) projects instead as the **parts manifest** in the RIGHT column with the primary **Ajouter** = that deterministic PATCH. `patchTask` → **no agent turn**; `instruction` → new turn. A proposal that arrives while one is already pending, or with no hero open (nowhere to raise a cradle), is **deferred into the à-décider queue** instead of dropped (`review` item — replays the same actions later) | CTA layer / `PartsManifest` / à-décider inbox |
| `toast {tone, message}` | non-alarm → narration line (Noyau heartbeat); **alarm** → docks a `notify` item onto the à-décider queue + a real toast popup | Le Noyau |
| `status {message}` | one **Noyau pulse** (per narrated step, sparse) + the narration line etches; the same step also appends an **aria-hidden tool-step chip** (short verb + glyph) beside Le Noyau, collapsing into the journal on turn end — a silent companion, never a second announcement | Le Noyau |
| `turn {phase}` | `start` → Noyau busy/working (aperture focuses, scan locks) + a **cyan scan-sweep** slides across the car; `end` → settle → green/amber/red | Le Noyau + scene overlay |

`PanelKind` also has a `'carnet'` value, but only `showHistory`/the chip raise it client-side — the server's
`focusView` accepts `specs`/`tasks` only (CONTRACT.md).

## Le Noyau's à-décider queue (ember)

Hands-off concerns (the §9 overdue crossing), alarm toasts, and superseded/homeless `proposeActions`
proposals never vanish — they queue on the ember (`notify`/`review` `DecisionItem`s; a `question` kind is
reserved, not yet produced). The badge shows the live queue length. Opening the inbox **mutes** the badge
(`emberSeen`) but does **not** drain the queue — only resolving or dismissing every row does, so a closed-
without-resolving inbox re-opens with everything still there. Rows resolve deterministically (a review
row replays its `patchTask`/`instruction`; a notify row offers "vu" or "planifier ○") — never a transcript.

## Data around the turning car (option 2a)

The car IS the interface — no docked slot. Data is either **true of a body region** (échéances — the
persistent LEFT "Entretien" rail, two workload buckets: **À faire** = anything late, every row wearing its
red/amber TEXT tag, and **Planifié**, plus the untracked "non suivi" tail; the disc itself stays clean —
the ring pins were removed, indices read as a classification they weren't) or **fetched on
demand** (specs / parts / carnet, which project into the RIGHT **projection column** — one right-edge slot
so the operator learns a single glance direction for "the agent answered" — and clear on dismiss).

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
  instant cut (and "vue libre" an instant return).
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
