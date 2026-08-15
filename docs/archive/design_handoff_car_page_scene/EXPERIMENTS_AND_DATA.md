# Car page — experiments log + data/module inventory

Working notes for the `#/v/<seg>` hero redesign ("data around the turning car", option 2a). Two parts:
**(1)** what we've tried and the verdicts, **(2)** the full catalogue of data types + the modules that
existed *before* this refactor — the menu of things the UI can surface, so the next layout is designed
against all of it, not a subset.

Branch `feat/atelier`, app `dashboard/`. Desktop-only from here on (per direction).

---

## 1. Experiments log

| # | Experiment | Verdict | Notes |
|---|---|---|---|
| E1 | **HudRings** — screen-space concentric SVG (health arc r158 = score%, projection ring r200, odometer ring r258 slow spin), centred on car | **KEPT** | `scene/HudRings.tsx`. No contract change. |
| E2 | **On-car callouts** — échéances as drei-`<Html>` cards anchored per body region, grouped (Moteur/Contrôle/Filtration), compact-by-default + expand-on-focus | **REJECTED** | Overlapped badly as the car auto-rotates (anchors cluster at some angles); region grouping *buried the urgency*. Deleted `callouts.ts` + `Hotspots.tsx`. |
| E3 | **Spec constellation** — a `specs` focus fans `vehicle.facts` as small cards beside the car, verified ✓ / non-vérifié, staggered etch-in | **KEPT** | `scene/SpecConstellation.tsx`. Driven by the deterministic "Infos moteur" chip + agent `showFact`/`focusView(specs)`. Curated to engine keys (dumping all ~24 facts was unreadable). |
| E4 | **Deterministic chips** — quick-action chips above composer resolve client-side (no `postTurn`), replay the full Noyau choreography | **KEPT** | `runLocalAnswer` in `Atelier.tsx`. Only "Infos moteur" wired so far — user wants **more CTAs**. |
| E5 | **Parts manifest** — right-side itemised parts card (option a: renders parts an agent `proposeActions.patchTask` already carries; "Ajouter" = deterministic merge) | **KEPT (untriggered)** | `scene/PartsManifest.tsx`. Needs the catalog read (option b) or an agent turn to populate. |
| E6a | **Scan cue = flat DOM bar** sweeping in front of the car | **REJECTED** | "weak", reads as an overlay, not part of the car. |
| E6b | **Scan cue = 3D holo, whole-car glow** | **REJECTED** | "the car just glows" — uniform, not a moving wave. Bug: patched the fill mesh (per-mesh local coords) + swept the short axis. |
| E6c | **Scan cue = 3D holo travelling WAVE** — cyan band injected into the EDGE material, sweeps the longer horizontal axis, triangle-wave ping-pong front↔tail while Le Noyau works | **ACCEPTED ("perfect")** | `blueprintBuild.ts patchScan` + `VehicleMesh.tsx ScanRunner`. |
| E7 | **Échéances on the OUTER RING** — flat, severity-sorted (red→green), screen-space deterministic slots around the circle, alternating sides, two-line labels | **SUPERSEDED** | `scene/echeances.ts` survives as the shared merge+sort; the ring-only layout (`EcheanceList`/`EcheancePanels`) is gone — see E8. |
| E8 | **Ring pins + persistent left rail (the split)** — the ring shrinks to a minimal marker per échéance (index `NN` + signal dot + hair leader, `layoutSlots` in `ringEcheances.ts`); its prose (label, horizon, checkbox/plan/gap) moves to a screen-locked "Entretien" rail on the hero's left edge. One model (`scene/rail.ts buildRail`, wraps `buildEcheances`) drives both; pin and row share index `NN`, hover/focus on either brightens the pair | **REVISED (user pass)** | Pins **removed entirely** — the `NN` indices read as a classification they weren't, and carried no info hover didn't already give. `EcheancePins.tsx`/`ringEcheances.ts` deleted; the disc stays clean, zoom lives on the rail rows. `scene/EntretienRail.tsx` + `scene/rail.ts` remain, restructured: the rail splits into **À faire** (anything late — planned or not — every row red/amber text-tagged; fixes "only one en retard" where late-but-planned rows rendered neutral) and **Planifié**, gaps at the tail. |
| E9 | **Right projection column (B2)** — ONE right-edge DOM slot for everything Le Noyau projects (spec constellation, carnet, parts manifest), so the operator learns a single glance direction for "the agent answered"; the left rail never moves | **KEPT** | `.at-projection` in `scene/SceneView.tsx`. Agent-placed content gets a cyan tick + ✕ dismiss (`at-proj-tick`/`at-proj-dismiss`); operator-pinned facts share the same column with neither. The spec constellation flipped from the car's left (2a doc) to this right column. |
| E10 | **Carnet projection** — on-demand rollup of the last 3 visits (date, cost, service items, odometer) + total spend + visit count, from client-held `MaintenanceRecord[]`, no fetch | **KEPT** | `carnet.ts rollupCarnet` (pure) + `scene/CarnetProjection.tsx`. Reachable via the "Carnet · dépenses" chip (`focus.panel:'carnet'`) or the agent's `showHistory` action (`focus.ts focusForAction`) — both client-synthesized, see `CONTRACT.md`. |
| E11 | **Tool-step chips** — one small typed chip per tool call as Mercure `status` frames arrive during a turn, inside Le Noyau, replacing a single narration line with a visible trace of what's being fetched | **KEPT** | `noyau/state.ts` (`ToolStep[]`, reset on `turn/start`) + rendered in `noyau/Noyau.tsx`. |
| E12 | **Narration exit crossfade** — the outgoing narration line fades up-and-out (`aria-hidden`) on the same etch grammar as the incoming line's etch-in, instead of a hard cut | **KEPT** | `.noyau-narration-exit` span, `nx-etch`/`nx-etch-out` keyframes in `noyau/Noyau.tsx` + `noyau.css`. Closes the "narration exit animation" item in `noyau/CRITIQUE.md`. |
| E13 | **À-décider ambient inbox** — a compact queue anchored off the Noyau ember (LangChain ambient-triad pattern: notify/question/review rows), NOT a modal/drawer, no transcript; review rows replay their CTA (`patchTask` or `instruction`), notify rows offer "vu" (dismiss) or "planifier ○" (promote to task) | **KEPT** | `noyau/AmbientInbox.tsx` + `noyau/state.ts` (`DecisionItem`/`DecisionKind`, `queue`). Includes a focus trap + opener-return-focus pass (WCAG 2.4.3) layered on afterward. |
| E14 | **Region pulse** — a single localized cyan flare on one region's edges for the duration of a tool call (the agent "touching" that part of the car), reusing the scan wave's swept-axis coordinate | **KEPT** | `scene/holo.ts` (`pulseEnvelope`, `regionAxisFraction`) merged into the same edge-material patch as the scan wave — `blueprintBuild.ts patchHolo` (renamed from `patchScan`) now takes both `ScanControl` and `PulseControl` uniforms. |
| E15 | **Earned camera dolly** — clicking a pin/rail row (or the agent's region touch) dollies the view TO that body region (re-target + pull in to a fraction of the home radius, sine ease ~900ms) instead of dimming the rest of the stage; a drag breaks the hold immediately (user input wins), Escape or the "vue libre" button returns home | **KEPT** | `scene/cameraRig.ts` (pure: `derivePreset`, `gateReduce` intent-gate reducer) + wired in `Atelier.tsx` (`cameraFocus` state, `dollyToRegion`) and `scene/SceneView.tsx` (Escape handler, `.at-vuelibre` button). `UIAction.focusRegion{region}` is the contract seed (client-only today — see `CONTRACT.md`). |
| E16 | **Deterministic wayfinder chip shelf** — five chips above the composer (Infos moteur, Capacités fluides, Pièces dispo · `<service>`, Fiche technique, Carnet · dépenses), each hidden when its data prerequisite is absent (never a dead control), each replaying the full Noyau choreography with zero `postTurn` | **KEPT** | `Atelier.tsx buildQuickActions` (uses `runLocalAnswer`). "Capacités fluides" and "Fiche technique" are real catalog reads (`garage.ts getFillVolumes` → `fluids.ts fluidNodes`; `getSivVehicle` → `siv.ts carteGriseNodes`); "Pièces dispo" is still option-a (itemises the task's own parts, `scene/parts.ts pickPartsTask` — the TecDoc catalog read is still a TODO, see §6). |
| E17 | **Provenance marks** — a shared glyph (✧) next to any value the agent itself composed (`source: 'llm'` or `'web'`), distinct from a catalog-verified ✓; user-entered and catalog/log sources stay unmarked | **KEPT** | `provenance.ts isAgentAuthored` (pure predicate) + `AgentMark.tsx` (the glyph), consumed from `floor/FactsSection.tsx` / `scene/SpecConstellation.tsx`. |
| E18 | **Cutting-mat stage** — a lit workbench look, tried as two parts behind a `stageVariant` A/B flag: (1) a procedural cutting-mat **floor** and (2) a matte **porcelain car shell** replacing the tinted night-glass fill | **SPLIT VERDICT** — floor **VALIDATED, KEPT**; porcelain car **REJECTED** ("only mesh is good") | Floor: `scene/CuttingMat.tsx`, always mounted, no toggle. `scene/HoloPodium.tsx`/`Plateau.tsx` `PODIUM_INTENSITY` (now a flat `0.55` constant, no variant) re-tunes the rim/podium additive glow to read as a reflection on the lit mat rather than fog in a void. Porcelain: the fill swap in `scene/CarProxy.tsx`/`scene/blueprintBuild.ts` (`fillParamsFor`) and the parc-grid thumbnails (`ParcThumb.tsx`/`ParcLot.tsx`, plus the `ambientLight` it needed) was removed outright — the car keeps its original unlit paint-tinted night-glass fill + glowing HDR edges everywhere. The `stageVariant` A/B machinery (`scene/stageVariant.ts`, the `#/scene` "plateau: mat/nuit" toggle) is deleted entirely: the mat floor is not a mode, it's the only stage. |

### Open feedback (not yet built) — the current direction
- ~~**Task list ALWAYS visible**~~ — **RESOLVED** by E8: `EntretienRail` renders whenever a `checklist` prop is supplied, independent of `focus`/overlay state.
- ~~**More CTAs / buttons**~~ — **RESOLVED**, then user-revised: **Données** (editable facts popup — "the car's database", full read/edit/delete) and **Historique** (full visit log) are back as the lead chips, with the same Noyau replay choreography; "Infos moteur" was dropped (it dumped the full fact sheet read-only into the projection column — regressed E3's curation lesson). Fetched chips (fluides / fiche technique SIV / pièces / carnet·dépenses) stay.
- ~~**Ring blocs legibility**~~ — **RESOLVED** by E8: the ring pin carries only an index + signal dot (no prose to misread); the readable text moved to the rail.
- **Desktop only** — still the standing direction; no responsive/mobile work done in this pass either.
- Clarified: **"Contrôle technique" is not checkable** because it's a *forecast prediction* (`ForecastDue`), not an open `PlannedTask`. Only tasks have checkboxes. Planning it (the ○) promotes it into the task list. Still true — `EntretienRail` keeps the same task-vs-prediction-vs-gap row split as the old `Checklist`.
- **New, from this pass:** the design tension noted below (task list + ring + on-demand surfaces competing for screen edges) is resolved by construction — rail = fixed left, pins = on the disc, one projection column = fixed right. The 2a doc's "ONE swappable slot" model has quietly evolved into a *stack*: the constellation (pinned facts), the carnet, and the parts manifest are independent booleans (`showConstellation`/`showCarnet`/`showManifest` in `SceneView.tsx`) and can render together in `.at-projection` (e.g. pinned facts + an open carnet at once) — not documented as a deliberate decision anywhere, worth confirming it's intended before it's load-bearing.

### Design tension to resolve
Task-list-always-visible **+** échéance blocs on the ring **+** on-demand specs/parts surfaces all compete
for the same screen edges. Layout (where the persistent task list docks, and which arc the ring may use)
is the open decision.

---

## 2. Data-type catalogue (the menu the UI can show)

All shapes from `dashboard/src/types.ts`. This is *everything* a vehicle carries — group these into
modules however the layout wants.

### A. Identity — `VehicleSummary`
`make`, `model`, `year`, `engineVariant` (finition), `engineCode`, `kType` (TecDoc id), `vehicleType`
(car/moto/cv/groundscare), `vin`, `plate`, `source`, `addedAt`.

### B. Usage / odometer
`usageValue` + `usageUnit` (`km` | `mi` | `hours`). The single running reading.

### C. Health rollup — `MaintenanceHealth` (item GET only)
`status` (`green`|`amber`|`red`|`unknown`), `score` (0–100, null if unknown), `overdue` (count),
`dueSoon` (count), `tracked` (services with a baseline), `gaps` (recurring services with no baseline).

### D. Forecast — `VehicleForecast` = `ForecastDue[]` + `gaps[]`
Per service `ForecastDue`: `type`, `action` (change/replace/clean/inspect), `component`, `overdue`,
`daysUntilDue`, `usage` {`last`, `due`, `remaining`, `unit`}, `dueAt`, `projectedDueAt`, `lastPerformedAt`.
Derived (`derive.ts`): `signal`, `healthPct`, `horizon` ("dans ~2 mois" / "DÉPASSÉ de 3 200 km"), `until`.
`gaps` = recurring types with a cadence but no logged baseline ("non suivi").
`NextDue` = the compact one-liner on the parc collection.

### E. Specs / facts — `VehicleFact[]` (+ derived `FluidSpec`)
`key`, `value`, `unit`, `source`, `verified` (catalog ✓), `url` (citation). Real keys seen live:
`oil_type`, `oil_capacity`, `oil_viscosity`, `coolant`/`coolant_type`/`coolant_capacity`,
`brake_fluid`(_type/_capacity), gearbox/power-steering/AC fluids, and the `{service}_ref` family
(`oil_filter_ref`, `air_filter_ref`, `cabin_filter_ref`, `fuel_filter_ref`, `spark_plug_ref`,
`timing_belt_ref`, `battery_ref`, `tires_ref`, water pump / thermostat / tensioner refs…). ~20+ per car.

### F. Planned tasks (the checklist) — `PlannedTask[]`
`id` (IRI), `type`, `label`, `target` {value, unit}, `dueDate`, `status` (`open`|`done`|`dismissed`),
`parts[]`. The actionable to-do list (checkable). Grouped by milestone (`tasks.ts groupByMilestone`).

### G. Parts (shopping list) — `PlannedPart[]`
`ref`, `brand`, `label`, `qty`, `priceCents` (+ `spec` on a derived fluid line; future `availability`).
Rolls up to a manifest total.

### H. Maintenance history (past visits) — `MaintenanceRecord[]`
`performedAt`, `usageValue`+`usageUnit` (odo at the visit), `costCents`+`currency`, `performedBy`,
`notes`, `items[]` {`type`, `parts[]`}, `attachments[]` = **bills** (`filename`, `contentType`, `size`,
owner-gated `url`). One record = one VISIT carrying many service items.

### I. Service schedule (recurring rules) — `ServiceRule[]`
`type`, `action`, `component`, `everyUsage` {value, unit}, `everyMonths`, `firstUsageValue` (break-in),
`source`, `verified`, `url`. The per-vehicle cadences that drive the forecast.

### J. Agent surfaces (the hidden-chat → UI vocabulary) — `UIAction`
`selectVehicle`, `showFact`/`focusView`, `proposeActions` (`CtaAction`: `instruction` | deterministic
`patchTask`), `toast`, `status` (narration), `turn` (lifecycle). Plus the debug **journal**: turns with
status, tool calls (args+results), web searches, token usage, built context.

> Sections A–J above = what the **client** (`types.ts`) mirrors today. Section 5 below adds everything
> the **server** exposes that the client does NOT yet consume — the real expansion menu.

---

## 3. Modules that existed BEFORE this refactor (surfaces = data groupings)

What the hero / drawers actually rendered pre-2a. Use as the shopping list of "modules" to keep, drop,
or relocate.

| Module | File | Shows (data groups) | Status after 2a |
|---|---|---|---|
| **TruthPanel** (authoritative glass) | `floor/TruthPanel.tsx` | A identity · B usage · C health · last intervention · D forecast per-system rows · E facts · delete. Editable (InlineEdit). | Demoted to rung-c/d + reduced-motion **mirror** (removed from the 3D hero). |
| **FactsSection** | `floor/FactsSection.tsx` | E specs/facts list + add/edit/remove, verified badge, citation link. | Lives inside TruthPanel (mirror). Also re-expressed as **SpecConstellation**; now wears an `AgentMark` (✧) beside agent-composed values. |
| **Checklist** ("Entretien en cours") | ~~`scene/Checklist.tsx`~~ → `scene/EntretienRail.tsx` | F tasks (grouped by milestone) + G parts; add task, check/reopen/dismiss/restore, edit, parts CRUD, archive done. | **Renamed + restored to the hero, always visible** (E8): the persistent left "Entretien" rail. Milestone grouping dropped for severity order (shared with the ring pins via `scene/rail.ts`); task-row CRUD machinery carried over verbatim. |
| **FlatPlanList** ("Plan d'entretien") | `floor/FlatPlanList.tsx` | D forecast, flat list, full precision. | Rung-d fallback. |
| **CardinalReadouts** | ~~`scene/CardinalReadouts.tsx`~~ | B mileage · C santé% · "depuis l'entretien" · en retard count. | **Deleted before this range** (base commit `d29cfc7`, "holo podium + cockpit band absorbs cardinals") — santé moved into `Cockpit.tsx`'s `HealthStat`; not part of this delta. |
| **Hotspots** (region pins/callouts) | ~~`scene/Hotspots.tsx`~~ | D échéances anchored per body region. | Deleted before this range too; its outer-ring successor (`EcheanceList`/`EcheancePanels`) is what this delta itself supersedes — see E7→E8 above. |
| **RimGauge** | `scene/RimGauge.tsx` | C/D signal — one rim arc per tracked system. | Kept (3D floor ring). |
| **CtaCard** | `scene/CtaCard.tsx` | J proposeActions (headline/finding/actions). | Kept (rises from Noyau cradle; parts-bearing → PartsManifest). |
| **Noyau** | `noyau/Noyau.tsx` | J agent state — working/narration/done, ember. | Kept + grown: tool-step chips (E11), narration exit crossfade (E12), the ember now opens the à-décider inbox (E13). |
| **DossierPanel** (📁 drawer) | `DossierPanel.tsx` | **H history** (visits: date, odo, cost, performer, notes, items+parts, **bills** download) + **I schedule** (rules cadence, add/edit/delete). | Kept (drawer), still the full-precision source. §H now ALSO gets a compact hero surface — see **CarnetProjection** below. |
| **JournalPanel** (🪵 drawer) | `JournalPanel.tsx` | J agent turns debug (status, tool calls, tokens, context). | Kept (drawer). |
| **Établi** (parts shelf) | *removed earlier* (commit 34314b6) | G parts workbench shelf. | Gone before 2a. |
| Identity / Credits chrome | `Atelier.tsx`, `scene/Attribution.tsx` | make/model/plate header, sign-out, 3D model credits. | Kept. |

### New surfaces added in the 2a refactor (through this delta)
- **HudRings** (E1) — health/projection/odometer rings.
- **EcheancePins** (E8) — minimal ring markers (index + signal dot), spatial half of the split.
- **EntretienRail** (E8) — persistent left rail, prose half of the split; always visible.
- **SpecConstellation** (E3) — facts fanned in the right projection column (on-demand or operator-pinned).
- **PartsManifest** (E5) — itemised parts (on-demand, right column).
- **CarnetProjection** (E10) — last-3-visits + spend rollup (on-demand, right column).
- **AmbientInbox** (E13) — the à-décider queue off the Noyau ember.
- **Holo scan + pulse** (E6c, E14) — the "agent scanning" wave and the per-region "agent touching" flare, one shared edge-material patch (`patchHolo`).
- **Earned camera** (E15) — region dolly + "vue libre" return, `scene/cameraRig.ts`.

---

## 4. Gaps — data owned but never well-surfaced in the hero
- **H maintenance history / bills** — only in the 📁 drawer; no at-a-glance "last visits / total spent".
- **I schedule cadences** — only in the drawer; the forecast is shown but not the *rules* behind it.
- **G parts / cost** — no persistent "what will the next services cost" roll-up.
- **A identity detail** (VIN, engine code, kType) — only in the (now-demoted) TruthPanel.
- **J journal/agent history** — drawer only.

---

## 5. Server API — full resource catalogue (Symfony / API-Platform)

Every backend resource + field. **★ = server-only, NOT in the client `types.ts` today** → net-new data
the UI could surface. Traversal spine of the TecDoc catalog: `Make → Model → Car(kType) → PartCategory → Article` (+ `FillVolume`), all keyed by **kType** (`carId`).

### 5.1 Vehicle (SIV plate lookup) ★  — `GET /vehicles/{plate}`
Rich technical identity from the French registration + TecDoc — far beyond `make/model`:
`plate`, `country`, `make`, `model`, `modelEn`, `version`, `vin`, `engineCode`, `firstRegistration`,
`energy` (fuel), `bodyType`, `genre` (carte grise J.1: VP/CTTE/CAM), `fiscalPower`, `powerKw`, `powerHp`,
`engineCapacityCcm`, `cylinders`, `gearbox` (M/A), `seats`, `weightKg`, `carId` (kType), `carType` (PC/CV),
`tecdocManuId`, `tecdocModelId`.
→ ★ energy, bodyType, genre, fiscalPower, powerKw/Hp, displacement, cylinders, gearbox, seats, weight,
firstRegistration, TecDoc ids — none surfaced client-side.

### 5.2 GarageVehicle  — `/garage/vehicles[/{id}]` (GET·POST·PATCH·DELETE + facts POST/DELETE)
Client-mirrored (§A/B/C/D/E). Server fields: `id`, `make`, `model`, `year`, `engineVariant`, `engineCode`,
`vehicleType`, `source`, `kType`, `plate`, `vin`, `usageValue`, `usageUnit`, `factCount`, `facts[]`,
`health{status,score,overdue,dueSoon,tracked,gaps}`, `nextDue{…}`, `addedAt`.

### 5.3 VehicleForecast  — `GET /garage/vehicles/{id}/forecast`
`vehicle` (IRI), `due[]` {type, action, component, rule, lastPerformedAt, dueAt, projectedDueAt, overdue,
daysUntilDue, usage{last,due,remaining,unit}}, `gaps[]`. (Client-mirrored §D.)

### 5.4 GarageMaintenanceRecord (history)  — `/garage/vehicles/{id}/maintenance-records` + `/garage/maintenance-records/{id}` (GET·POST·PATCH·DELETE)
`id`, `items[]{type,label?,parts[{ref,brand?,label?,qty?,priceCents?}]}`, `attachments[]{id,filename,contentType,size,url}` (bills),
`label`, `performedAt`, `usageValue`, `usageUnit`, `notes`, `performedBy`, `costCents`, `currency`, `source`, `addedAt`.
(Client-mirrored §H — but only shown in the 📁 drawer.)

### 5.5 PlannedTask  — `/garage/vehicles/{id}/planned-tasks` + `/garage/planned-tasks/{id}`
`id`, `type`, `label`, `target{value,unit}`, `dueDate`, `parts[]`, `status`, `vehicle` (IRI),
★`createdAt`, ★`completedAt`. (§F; the timestamps are server-only.)

### 5.6 PlannedTaskPart  — `/garage/planned-tasks/{taskId}/parts[/{id}]` (POST·PATCH·DELETE)
`id`, `task` (IRI), `ref`, `label`, `brand`, `qty`, `priceCents`. (§G.)

### 5.7 ServiceScheduleItem (cadence rules)  — `/garage/vehicles/{id}/service-schedule[/{id}]` (GET·POST·DELETE)
`id`, `vehicleId`, `type`, `action`, `component`, `everyUsage{value,unit}`, `everyMonths`,
`firstUsageValue` (break-in), `source`, `verified`, `url`. (§I — drawer only.)

### 5.8 TecDoc catalog chain ★ (parts + fluids — the big net-new block)
- **Make** `GET /makes?carType&search` → `{id (manuId), name}`
- **Model** `GET /makes/{makeId}/models?carType` → `{id (modelId), name}`
- **Car** `GET /makes/{makeId}/models/{modelId}/cars` → `{id (kType), name}`
- **PartCategory** `GET /cars/{carId}/part-categories?leafOnly&search` → `{id (strId), parentId, level, name, path, leaf}` — the product-group TREE
- **Article** `GET /cars/{carId}/part-categories/{categoryId}/articles` → `{id, number (part №), brand, productGroup, productTypeId, supplierId}` — actual buyable references
- **FillVolume** ★ `GET /cars/{carId}/fill-volumes` → `{id, label, originalTitle, volume, unit, info}` — fluid **capacities + specs** (engine oil, gearbox, brake fluid, coolant, A/C refrigerant…): e.g. volume 4.3 + unit "liter", or spec info "ACEA E3|E4|E5" / "DOT 4" / "R134a".
→ ★ NONE of this catalog is in the client today. Enables: real "pièces dispo" manifests, fluid capacities/specs, brand choices per service.

### 5.9 AgentTurn (journal)  — `/agent/turns` (GET)
Turn records: status (`completed`/`capped`/`failed`/`noop`), tool calls (args+results), web searches,
token usage, built context, timestamps. (§J — drawer only.)

### 5.10 MCP-only tools (no HTTP resource, but data the agent can fetch on demand) ★
- **ServiceParts** `get_service_parts` — one call bundles part-categories + articles + fluid specs FOR a service (e.g. everything for a vidange). ★ ideal backing for the parts manifest.
- **MotulOil** `get_motul_oil` — recommended engine-oil SAE grade (5W-30…). ★
- **Maintenance / Garage** tools — log/edit/plan/dismiss/history/forecast/facts/update (mirror the HTTP ops).
- **ArchiveTasksOutput** `POST …/planned-tasks/archive` → `{archived: int, records: [IRIs]}` (done tasks → history).

### 5.11 Enums (canonical server values)
- **VehicleType**: `car` · `moto` · `cv` · `groundscare`
- **UsageUnit**: `km` · `mi` · `h`
- **MaintenanceAction**: `change` · `replace` · `clean` · `inspect`
- **PlannedMaintenanceStatus**: `open` · `done` · `dismissed`
- **CarType** (TecDoc): `PC` · `CV`
- **AgentTurnStatus**: `completed` · `capped` · `failed` · `noop`
- **Provenance source** (facts/rules): `partsapi`★ · `siv`★ · `plate_lookup`★ · `catalog`★ (verified) · `user` · `agent` (unverified)

---

## 6. The expansion menu (what we could add to the UI, ranked by "owned but unsurfaced")

1. ~~**Fluid specs + capacities**~~ (FillVolume ★) — **BUILT**: the "Capacités fluides" chip (E16) GETs `/cars/{kType}/fill-volumes` and projects through `fluids.ts fluidNodes` into the spec constellation.
2. **Real parts catalog** (PartCategory→Article ★ / ServiceParts) — brand + part № choices per service → a genuine "pièces dispo" manifest. **Still open** — "Pièces dispo" (E16) ships option-a only (itemises a task's own already-planned parts, `scene/parts.ts`); the TecDoc catalog read (option-b) is an explicit `TODO(catalog option-b)` in that file.
3. ~~**Full SIV identity**~~ (Vehicle lookup ★) — **BUILT**: the "Fiche technique" chip (E16) GETs `/vehicles/{plate}` and projects through `siv.ts carteGriseNodes` (power, displacement, cylinders, gearbox, seats, weight, first registration, fiscal power) into the spec constellation.
4. ~~**Maintenance history + spend**~~ (GarageMaintenanceRecord §H) — **PARTLY BUILT**: the "Carnet · dépenses" chip (E10) promotes a compact rollup (last 3 visits + total + count) into the hero's right projection column; the 📁 drawer (`DossierPanel.tsx`) stays the full-precision source (all records, bills, notes).
5. **Schedule cadences** (ServiceScheduleItem §I) — the rules behind the forecast — editable, promote out of the drawer. **Still open** — untouched by this pass, still drawer-only.
6. **Motul oil grade** (get_motul_oil ★) — recommended viscosity. **Still open** — no chip/surface added.
7. **Agent journal** (AgentTurn §J) — turns/tokens/tools — already a drawer. **Still open** — unchanged.
