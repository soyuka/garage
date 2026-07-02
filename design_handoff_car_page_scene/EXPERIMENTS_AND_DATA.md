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
| E7 | **Échéances on the OUTER RING** — flat, severity-sorted (red→green), screen-space deterministic slots around the circle, alternating sides, two-line labels | **CURRENT / iterating** | `scene/echeances.ts` + `EcheanceRing.tsx`. |

### Open feedback (not yet built) — the current direction
- **Task list ALWAYS visible** (the `Checklist` was removed from the hero when the slot died; user wants it back, persistent).
- **More CTAs / buttons** — the user feels limited with only "Infos moteur".
- **Ring blocs legibility** — "we can barely read the blocs"; keep them on the outer ring but make them readable.
- **Desktop only** — stop spending effort on responsive/mobile.
- Clarified: **"Contrôle technique" is not checkable** because it's a *forecast prediction* (`ForecastDue`), not an open `PlannedTask`. Only tasks have checkboxes. Planning it (the ○) promotes it into the task list.

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
| **FactsSection** | `floor/FactsSection.tsx` | E specs/facts list + add/edit/remove, verified badge, citation link. | Lives inside TruthPanel (mirror). Also re-expressed as **SpecConstellation**. |
| **Checklist** ("Entretien en cours") | `scene/Checklist.tsx` | F tasks (grouped by milestone) + G parts; add task, check/reopen/dismiss/restore, edit, parts CRUD, archive done. | **Removed from hero** (slot killed). ← user now wants this **always visible**. |
| **FlatPlanList** ("Plan d'entretien") | `floor/FlatPlanList.tsx` | D forecast, flat list, full precision. | Rung-d fallback. |
| **CardinalReadouts** | `scene/CardinalReadouts.tsx` | B mileage · C santé% · "depuis l'entretien" · en retard count. | Kept (4 cardinals around the disc). |
| **Hotspots** (region pins/callouts) | ~~`scene/Hotspots.tsx`~~ | D échéances anchored per body region. | **Deleted** → replaced by EcheanceRing. |
| **RimGauge** | `scene/RimGauge.tsx` | C/D signal — one rim arc per tracked system. | Kept (3D floor ring). |
| **CtaCard** | `scene/CtaCard.tsx` | J proposeActions (headline/finding/actions). | Kept (rises from Noyau cradle; parts-bearing → PartsManifest). |
| **Noyau** | `noyau/Noyau.tsx` | J agent state — working/narration/done, ember. | Kept. |
| **DossierPanel** (📁 drawer) | `DossierPanel.tsx` | **H history** (visits: date, odo, cost, performer, notes, items+parts, **bills** download) + **I schedule** (rules cadence, add/edit/delete). | Kept (drawer). Rich, under-surfaced in the hero. |
| **JournalPanel** (🪵 drawer) | `JournalPanel.tsx` | J agent turns debug (status, tool calls, tokens, context). | Kept (drawer). |
| **Établi** (parts shelf) | *removed earlier* (commit 34314b6) | G parts workbench shelf. | Gone before 2a. |
| Identity / Credits chrome | `Atelier.tsx`, `scene/Attribution.tsx` | make/model/plate header, sign-out, 3D model credits. | Kept. |

### New surfaces added in the 2a refactor
- **HudRings** (E1) — health/projection/odometer rings.
- **SpecConstellation** (E3) — facts fanned beside the car (on-demand).
- **PartsManifest** (E5) — itemised parts (on-demand, right).
- **EcheanceRing** (E7) — forecast échéances around the outer ring (severity-sorted).
- **Holo scan wave** (E6c) — the "agent scanning" cue, in the car's edge material.

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

1. **Fluid specs + capacities** (FillVolume ★) — "4.3 L 5W-30, DOT 4, R134a 470 g" per car. Pairs with the spec constellation.
2. **Real parts catalog** (PartCategory→Article ★ / ServiceParts) — brand + part № choices per service → a genuine "pièces dispo" manifest (closes option-b).
3. **Full SIV identity** (Vehicle lookup ★) — power (kW/hp), displacement, cylinders, fuel, gearbox, weight, first registration, fiscal power. A rich "carte grise" module.
4. **Maintenance history + spend** (GarageMaintenanceRecord §H) — last visits, total cost, bills (PDF) — promote out of the drawer.
5. **Schedule cadences** (ServiceScheduleItem §I) — the rules behind the forecast — editable, promote out of the drawer.
6. **Motul oil grade** (get_motul_oil ★) — recommended viscosity.
7. **Agent journal** (AgentTurn §J) — turns/tokens/tools — already a drawer.
