# Handoff — Car page: "data around the turning car" (option 2a)

> **Status: 2a shipped, then evolved past this doc.** The PR sequence below landed (`HudRings`,
> spec constellation, deterministic chip strategy, parts manifest), but the on-car échéance
> callouts / `Hotspots.tsx` and the `CardinalReadouts` cardinals described in this handoff are gone
> — replaced by a split model: minimal **ring pins** (index + signal dot, `scene/EcheancePins.tsx`)
> paired with a **persistent left "Entretien" rail** carrying the prose (`scene/EntretienRail.tsx`,
> the renamed former `Checklist`), and santé folded into the `Cockpit` identity band. The spec
> constellation also flipped from the car's left to a single right-edge **projection column**
> shared with the carnet and parts manifest. See `EXPERIMENTS_AND_DATA.md` (§1 experiments log,
> E8+) for what replaced what and why. This body is kept as the historical design record — read
> it for the interaction model and tokens, not for the current component names.

## Overview
Reimagine the vehicle hero (`#/v/<seg>`) so the **turning car is the interface**: the floating
right-hand slot panel (`.at-scene-slot`) is removed. Data lives in two layers:

- **On the car** — what is *true about a body region* (échéances / open tasks) orbits **with** that
  region as indexed callouts.
- **Around the car** — what the agent *fetches on demand* (engine spec sheet, available parts,
  forecasts, search) is **projected** as HUD readouts that fan in and clear. Never a docked panel.

This is a refinement of the existing Atelier scene, **not a rewrite**. Almost everything maps onto
seams that already exist (`Hotspots`, `CardinalReadouts`, `focus`, `CtaCard`, `Noyau`,
`CHOREOGRAPHY.md`). Read that map (below) before touching code.

## Target codebase
`soyuka/mon-parc-auto.fr`, branch `feat/atelier`, app `dashboard/` (Vite + React + TypeScript,
`@react-three/fiber` / `drei` for the 3D). Reuse the existing components, tokens and the
`UIAction` contract. Do **not** port the HTML/CSS below verbatim.

## About the design file
`Car page - data around the turn.dc.html` in this folder is a **design reference** (an HTML/CSS
prototype of look + behavior), not production code. The interactive option is **`2a`** (scroll to the
`#2a` card). Options `1a`/`1b`/`1c` are earlier explorations kept for context; `2a` is what we build.
The prototype fakes the agent with a local state machine and a CSS-3D box car — in the app the car is
the real R3F `Plateau` and the states are driven by real frames.

## Fidelity
**Hi-fi** for the interaction model, layout intent, tokens and motion timings. It is **not**
pixel-final for the 3D car (the prototype uses a CSS box proxy; the app already has `Plateau` rung b
and will get a real `.glb` at rung a). Match the tokens, the surface behavior and the choreography;
let the real 3D and `drei <Html>` anchoring supersede the prototype's CSS tricks.

---

## The map: 2a → existing code

| 2a piece | Already exists as | Change |
|---|---|---|
| Échéance pins orbiting with the body | `scene/Hotspots.tsx` (drei `<Html>` anchored via `scene/anchors.ts → regionAnchors`) — billboards, occludes and distance-scales natively | **Restyle** into indexed callouts (`01/02/03` + region label + a checkbox). Wire the checkbox to `checklist.onSetStatus(task.id, …)`. This replaces the `tasks` mode of the slot. |
| Cardinal readouts (KM / SANTÉ / …) | `scene/CardinalReadouts.tsx` | Keep as-is. |
| Le Noyau (working / status / done), scan feel | `noyau/Noyau.tsx` + `CHOREOGRAPHY.md` | Keep. Optionally add a cyan scan-sweep leaf on `turn:start` (see Motion). |
| CTA rising from the cradle | `scene/CtaCard.tsx` (`proposeActions` → rises; `CtaAction.patchTask` = deterministic, no turn) | Keep. Parts "add to task" reuses `patchTask`. |
| Spec constellation | **driven by `focus`** (`focus.ts`) from `focusView{panel:'specs'}` / `showFact` | **New surface.** Data = `vehicle.facts` (`VehicleFact.key/value/verified`). Spotlight region via `anchors.ts → regionsForKeys`. |
| Concentric health / odometer rings | partially `scene/RimGauge.tsx` (floor arc) | **New DOM leaf** in screen space (recommended over 3D — crisper, cheaper; same pattern as `CardinalReadouts`). |
| Parts manifest (available parts + stock) | partially `PlannedTask.parts` (`PlannedPart.priceCents`) | **Only real contract gap** — see "Contract gap". |
| The prompt-chip state machine | already real: a chip = a **deterministic** local action; free text = `postTurn()` | See "Deterministic-first". |

**Honesty guardrail (binding — `CHOREOGRAPHY.md`).** Every value a motion conveys must also exist at
full precision in `floor/TruthPanel.tsx` + `floor/FlatPlanList.tsx`. So **do not delete them** — they
become the rung c/d + `prefers-reduced-motion` fallback and the accessible mirror. "Hide the WebGL
canvas → no value lost" must stay true.

---

## Deterministic-first (this is the strategy, not a shortcut)
Reserve LLM turns for free-text in the composer. The quick-action chips resolve **client-side, no
`postTurn`, no tokens** — the seam already exists:

- **"Infos moteur"** → `setFocus({ panel: 'specs', keys: ['engine','oil_viscosity','oil_standard','tyre_size'] })`
  over the already-loaded `vehicle.facts`. Instant, free.
- **"Pièces dispo · vidange"** → a plain catalog GET (see gap) or the pre-wired `patchTask` merge.
- **"Prépare la vidange"** → build a local `Cta` with `CtaAction.patchTask` (deterministic parts merge)
  — no agent turn.
- **Free-text composer** → `postTurn(instruction, …)` (the only LLM path). Its `focusView` /
  `showFact` / `proposeActions` frames drive the **same** surfaces, so the user sees no difference.

The scan-sweep + `Noyau` narration replay identically for deterministic and agent-driven answers.
Optionally the agent may *suggest* these chips ("suggested next") while they still resolve locally.

---

## Screens / Views

### Vehicle hero — `#/v/<seg>` (rung a/b: 3D present)
Rendered by `scene/SceneView.tsx`, driven by `Atelier.tsx`.

**Layout (full-bleed, z-order per `scene.css`):**
`Plateau` canvas (z0) → screen-space HUD rings + cardinals (z4-5) → orbiting `Hotspots` callouts
(z30, portaled) → agent-projected surfaces (new, ~z6 in DOM overlay above rings, below dock) →
dock: `CtaCard` → `Noyau` → composer (z40-50).

**Persistent elements**
- **Car** — `Plateau` (turntable auto-rotate; keep `OrbitControls` autoRotate). Body edges tinted by
  `vehicle.health.status` (green/amber/red); `BodyGlow` for sick regions stays.
- **Cardinals** — `CardinalReadouts`, unchanged (KILOMÉTRAGE / SANTÉ / DEPUIS L'ENTRETIEN / EN RETARD).
- **HUD rings (new leaf, e.g. `scene/HudRings.tsx`)** — screen-space SVG centered on the car:
  - inner **health arc**, `stroke = signal color`, arc length = `health.score/100`. r≈158, stroke 7,
    round cap, `-90°` start, `drop-shadow(0 0 7px <signal>)`.
  - middle **projection ring** r≈200, dashed `2 8`, faint — the plane the projected surfaces attach to.
  - outer **odometer ring** r≈258, dashed `1.5 12`, slow spin (~110s). The reading itself stays in the
    top-right cardinal/odometer readout.
- **Échéance callouts** — restyled `Hotspots`, one per `forecast.due` item (or per open `PlannedTask`),
  anchored via `regionForType(due.type) → regionAnchors[region]`. Content: index `NN` + `REGION_LABEL`
  (mono, `--dim`), a **checkbox** (○ open / ✓ done, toggles `checklist.onSetStatus`), the
  `serviceLabel(type)` + horizon (`d.horizon` from `derive.ts`, `--amber`/`--red`). A coverage gap
  (`forecast.gaps`) renders dashed/grey with "· non suivi", no checkbox.
- **Le Noyau + composer** — `Noyau` + composer input, unchanged. Chips row above the composer (new):
  the deterministic quick-actions.

**On-demand agent surfaces (new — mounted from `focus` / `cta`)**
- **Spec constellation** (`focus.panel === 'specs'`): fan of nodes to the car's left, each a small
  card `{ label (mono `--dim`), value, ✓ if `fact.verified` else "· non vérifié" (`--dim`) }`, a hair
  leader pointing toward the car. Nodes = `vehicle.facts` filtered to `focus.keys` (empty ⇒ all).
  Etch-in staggered.
- **Parts manifest** (`focus.panel === 'parts'`, new — see gap): right-side readout column: header
  "PIÈCES DISPO · <service>", rows `{ label, ref+brand (mono `--dim`), priceCents→€, availability dot
  (green "en stock" / amber "sous 48 h") }`, total, and a primary "Ajouter à la tâche →" button that
  fires the deterministic `patchTask` merge. Rows unfurl staggered.
- **Plan/CTA** (`proposeActions`): `CtaCard` rising from the cradle (existing) + an orange pulse marker
  on the relevant region. Primary action = `patchTask` (orange, `--orange`), secondary = ghost.

### Fallback — rung c/d + reduced-motion
`SceneView` already branches on `rung`. Keep rung d = `TruthPanel` + `FlatPlanList`. The new surfaces
are rungs a/b only; rung c (SVG silhouette) shows the truth-panel. Under `prefers-reduced-motion`,
sweeps/etches/unfurls collapse to instant state (existing rule).

---

## Interactions & Behavior
- **Chip click** → deterministic: set `Noyau` to working, play narration steps, then set `focus`/`cta`.
  No `postTurn`. **Free text** → `postTurn` (the frames set the same `focus`/`cta`).
- **Choreography timings** (from the prototype; tune to taste): step-1 narration immediate;
  step-2 narration ≈ **+950ms**; answer materializes ≈ **+1900ms** (`turn:end` → settle Noyau).
- **Car-pin click** → `onFocusRegion(region)` already sets `focus` to that region's tasks (keep;
  now it lights the region + brightens its callout instead of raising the slot).
- **Checkbox on a callout** → `checklist.onSetStatus(task.id, done?'open':'done')` (optimistic; the
  bundle re-reads — existing `afterTaskWrite`).
- **Manifest "Ajouter"** → `onCtaPatch({ id, parts })` (existing deterministic path).
- **Navigation** → unchanged (`#/` parc grid → `#/v/<seg>`; back link).

## State management
Lives in `Atelier.tsx` already: `bundle` (`VehicleBundle`), `focus` (`Focus | null`), `cta`
(`Cta | null`), `Noyau` state via `useNoyau`. The frame handler (`onFrame`) already maps
`focusView`/`showFact` → `setFocus(focusForAction(frame))` and `proposeActions` → `setCta`. New:
- add `panel: 'parts'` handling to `focus.ts` (extend `PanelKind` already `'specs' | 'tasks'` →
  add `'parts'`, or map parts through a dedicated state).
- deterministic chip handlers in `Atelier` that call `setFocus`/`setCta` directly (no `postTurn`).

## Contract gap — the one backend/type addition
"Available parts + stock" is **not** in `types.ts`. Two options:
- **(a) no backend, ship first:** render the manifest from a `proposeActions` whose `CtaAction.patchTask`
  carry the parts (refs/labels/prices). Availability is faked/omitted. Zero contract change.
- **(b) proper:** add a catalog read (e.g. `GET …/parts?service=oil_change`) returning
  `{ ref, brand, label, priceCents, availability: 'in_stock' | 'lead_time', leadDays? }`, and either a
  new `UIAction` member (`{ type:'showParts'; service:string; parts:[…] }`) or reuse `focusView` with a
  parts payload. Add the type, extend `onFrame`, and add `availability` rendering.
Recommend shipping (a) behind the flag, then (b).

---

## Design tokens (exact, from the prototype)
```
--ink   #ece6db     text
--dim   #8f887b     labels / muted
--mono  #a6b5c1     cool telemetry text
--hair  rgba(190,205,220,.16)   hairlines / borders
--green #63e6a2     signal: ok        (map to health 'green')
--amber #f2b23c     signal: due soon  (health 'amber')
--red   #fb6f6f     signal: overdue   (health 'red')
--cyan  #5fe0d9     RATIONED to Le Noyau (orb + scan sweep) only
--orange #ee4322    Les-Tilleuls brand — operator focus + primary action ONLY
card bg  radial-gradient(120% 95% at 50% 12%, #1a1813 0%, #131210 58%, #0b0a08 100%)
```
- **Fonts:** `Ubuntu` (UI/body), `Space Mono` (telemetry, labels, numbers). Ubuntu ships with the
  Les-Tilleuls tokens; add Space Mono (or the repo's existing mono) for telemetry.
- **Color rules (binding):** signal colors = the car's health only; **cyan = Noyau only**; orange =
  operator focus / primary CTA only. Never mix these roles.
- **Radii:** callout tag 8px · constellation node 9px · manifest 13px · chips/pills 999px ·
  reticle corner bracket 12px.
- **Surface fills:** tag `rgba(13,14,16,.82)`, node `rgba(13,14,16,.85)`, manifest `rgba(13,14,16,.86)`,
  all `border: 1px var(--hair)`, `backdrop-filter: blur(3–4px)`, `box-shadow: 0 8–16px 22–44px rgba(0,0,0,.5–.6)`.
- **Rings:** health r158 stroke7 round-cap arc = score%, `-90°` start, glow `drop-shadow(0 0 7px signal)`;
  projection r200 dashed `2 8`; odometer r258 dashed `1.5 12`, spin ~110s.

## Motion
```
etchin   opacity 0→1, translateY 8→0, blur 2→0     .45–.5s ease   (specs nodes, staggered .08/.16/.24s)
unfurl   opacity 0→1, translateX 16→0              .4s ease       (manifest rows, staggered .06/.13/.2s)
sweepy   cyan bar translateX -150→150, fade edges  1.5s ease-in-out (scan on turn:start / working)
workspin border-top-transparent ring spin          .8s linear     (inside Noyau orb while working)
cta rise translateY 22→0 + scale .97→1              360ms spring   (existing at-cta-rise)
pinpulse opacity/box-shadow glow pulse              1.4–1.6s       (urgent/plan marker)
```
All gated by `prefers-reduced-motion` → instant state.

## Data mapping (types.ts)
- Facts → `VehicleFact { key, value, verified, source, url? }` (✓ when `verified`).
- Échéances → `ForecastDue` (`type, overdue, daysUntilDue, usage{remaining,unit}, dueAt/projectedDueAt`),
  labeled via `labels.ts → serviceLabel`, horizon via `derive.ts → dueDescriptor`.
- Tasks/checkbox → `PlannedTask { id, type, status, parts[] }`, toggled via `checklist.onSetStatus`.
- Parts → `PlannedPart { ref, brand, label, qty, priceCents }` (+ new `availability` for the manifest).
- Region mapping → `scene/anchors.ts` (`regionForType`, `regionsForKeys`, `typesForRegion`,
  `regionAnchors`, `REGION_LABEL`, `RIM`).
- Health/signal → `MaintenanceHealth { status, score, overdue, dueSoon }`, `Signal`.

## Suggested PR sequence (each shippable, behind a rung/flag for A/B vs current)
1. **`HudRings` DOM leaf** in `SceneView` (health arc + odometer + projection ring). No contract change.
2. **Restyle `Hotspots`** → indexed callouts + checkbox (tasks move onto the car). Remove the `tasks`
   mode from `.at-scene-slot`.
3. **`focus` → spec constellation** around the car (from `vehicle.facts`); `TruthPanel` becomes the
   fallback, not the primary surface. Add deterministic chip handlers.
4. **Parts manifest** — ship option (a) via `proposeActions`/`patchTask`, then (b) the catalog contract.

## Acceptance / guardrails
- Hiding the WebGL canvas (rung d) loses **no** value — `TruthPanel` + `FlatPlanList` still complete.
- `prefers-reduced-motion` → no sweeps/etches; instant states.
- Cyan appears **only** on Le Noyau; orange **only** on focus/primary actions; signal colors **only**
  for health. Grep for regressions.
- Quick-action chips never call `postTurn`. Only the composer free-text does.
- All panel animations yield to Le Noyau's narration (existing rule).

## Files
- `Car page - data around the turn.dc.html` — the design reference (interactive option **`2a`**).
- Real code to touch (branch `feat/atelier`, under `dashboard/src/`):
  `scene/SceneView.tsx`, `scene/Hotspots.tsx`, `scene/anchors.ts`, `scene/CardinalReadouts.tsx`,
  `scene/RimGauge.tsx`, `scene/CtaCard.tsx`, `scene/Checklist.tsx`, `focus.ts`, `Atelier.tsx`,
  `floor/TruthPanel.tsx`, `floor/FlatPlanList.tsx`, `types.ts`, `CHOREOGRAPHY.md`. New:
  `scene/HudRings.tsx` (+ optional `scene/ScanSweep.tsx`, `scene/PartsManifest.tsx`,
  `scene/SpecConstellation.tsx`).
```
