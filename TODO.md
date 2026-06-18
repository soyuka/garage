# TODO / investigations

## MCP default-action prompts are dormant (not triggered by our UI)

The garage default actions are registered in `api/src/Mcp/GarageActionPromptLoader.php`
(`list_vehicles`, `whats_due`, `next_to_maintain`, `identify_plate`, `log_service`) and
`FillVehicleInfoPromptLoader.php`. They are exposed over the MCP server (`prompts/list` /
`prompts/get`) so an external MCP host (Goose etc.) can surface them as quick-action buttons.

**Our own pipeline never uses them.** `AgentTurnController` takes a free-text `instruction`
and calls the agent directly; the dashboard does not fetch `prompts/list` and never calls
`prompts/get`. So clicking a "quick action" in our dashboard is impossible today — the seed
messages those prompts produce are unreachable from `mon-parc-auto`'s React UI.

Feature to add (one of):
- **Dashboard host**: `prompts/list` → render quick-action buttons → on click `prompts/get`
  (with args) → feed the returned seed user-message into `/agent/turn` as the instruction.
- **Server shortcut**: `/agent/turn` accepts `{promptName, args}`, resolves it through the MCP
  registry to the seed message, and runs that as the turn.

=> also save prompt history

## Service-recipe tightening (parts + fluids for "vidange"/entretien)

Free-text "pièces pour la vidange" (turn f65593) fetched fluids only (`get_vehicle_facts`) and
never the parts (`list_part_categories` + `list_articles`). The system prompt lists the tools
but does not compel the recipe. Encode a service recipe once and reference it from both the
system prompt and a new `parts_for_service` / `oil_change` default-action prompt:
- oil change ⇒ oil_type + oil_capacity (`get_fill_volumes`) **and** oil filter part ref
  (`list_part_categories` → `list_articles`, carId), + drain-plug gasket.

## Mileage / usage on the Vehicle entity

SHIPPED: `Vehicle.usageValue` + `usageUnit` (km|mi|h), the `update_vehicle` MCP tool, the
dashboard inline edit, and SERVICE RECIPE step 0 (persist plate/usage before the parts lookup).
Remaining: feed the stored reading into the maintenance-due math (see the forecast note under the
`patch_panel` split).

## Answer rendering: persisted checklist (SHIPPED)

Built as a **persisted `PlannedMaintenance` entity**, not an ephemeral render intent — it renders
the same way facts do (item refetch on Mercure fanout), so it survives reload and feeds the
"Tâches ouvertes" counter. The shopping list lives ON each task (`parts:[{ref,brand,label,qty}]`).

- `plan_maintenance` MCP tool (upsert keyed on vehicle+type+label) records intended work + parts.
- `log_maintenance` of a matching type auto-closes the open task.
- Tick-off: `PATCH /garage/planned-tasks/{id}` `{status:"done"}` (dashboard checkbox).
- Read: `GET /garage/vehicles/{id}` carries `openTasks` + `plannedTasks`; the checklist card is in
  `VehicleDetail.tsx`. Prompt SERVICE RECIPE step 5 makes it the required answer surface.

## Render vocabulary: split the generic `patch_panel` tool (DEFERRED)

`patch_panel(panel, data)` is the lone "general UI update" tool — the specific-intent design
(`select_vehicle`/`suggest`/`toast`) wants it gone. Split into specific render intents:
`patch_next_service` / `set_health` / `patch_cost`. Then:
- **Derive `nextService`** from `PlannedMaintenance` + the time-forecast instead of agent push —
  the data now has a real source.
- **Usage-based "due in X km" is now computable**: `update_vehicle` persists a live odometer
  reading (`vehicle.usageValue`), so the `MaintenanceInterval` premise ("we never store a live
  reading, so due-in-km is unknowable" — `MaintenanceInterval.php:11`) is stale. Revisit the
  forecast to use it.

## Agent cost: batch render intents to cut LLM round-trips

Profile `f65593` (cost ~$0.02 recomputed; reported $0.04 likely stale pre-haiku figure):
7 LLM calls, all `claude-haiku-4-5` (max_tokens=1024). Settings healthy — caching works
(system prompt 4755 tok written call 1, 37k cache-reads @0.1× across calls 2-7), no
duplicate tool calls, cheap model.

Cost driver = **round-trip count, not config**. Tool loop emits **one tool per turn**:
`lookup_plate → get_vehicle_facts → list_vehicles → select_vehicle → suggest → toast →
patch_panel → final`. Only `lookup_plate→get_vehicle_facts` is genuinely dependent. The 4
render intents (`select_vehicle`/`suggest`/`toast`/`patch_panel`) have no inter-dependency
yet each costs a full round-trip (calls 4-7).

Fix: batch render intents into ONE assistant message — Anthropic supports multiple
`tool_use` blocks per response. Check how Symfony AI emits tool calls / whether parallel
tool_use is enabled. Collapsing renders ≈ 2-3 fewer calls ≈ 30-40% fewer round-trips.

## EAV smell: VehicleFact — investigate MongoDB migration

`VehicleFact` (key/value/unit/source/verified/url rows, OneToMany on `Vehicle`) is a classic
EAV table. Disliked. Investigate migrating facts to a document model (MongoDB): facts as an
embedded subdocument array on the vehicle document, dropping the join + the per-row metadata
columns. Evaluate: API Platform ODM support, the shared-garage write-through resolver, the
Mercure fact/created events, and querying facts by key. Decision pending — spike first.

## User-editable cards in UI

Cards should be directly editable by the user. Example: vehicle card — user adds a picture or
changes mileage inline. Needs write endpoints + per-field edit affordance on the card. Pairs
with the mileage/usage entity work above (`set_usage`/`update_mileage`) and a picture-upload
field (Garage storage already shipped — see [[persistence-stack-shipped]]).

## PDF garage invoice → extract → client-validated form → submit

User uploads a PDF of a garage invoice. A Sonnet model parses photo/PDF, extracts maintenance
info (work done, parts, date, mileage, cost), then UI says e.g. "add maintenance work XXX on
car XXX". Key UX: **client-side validation** — the UI shows a pre-filled form, user reviews/
edits the extracted fields before submitting. The "check this filled form and submit" pattern
fits the app well (low-trust extraction + human confirm gate). Reuse for any
LLM-extraction-to-write flow.
