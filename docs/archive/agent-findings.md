# Garage agent — maintenance-flow findings (2026-06-18)

Investigation of the garage agent (`ai.agent.garage`, `claude-haiku-4-5`) on the canonical
prompt (`prompt.md`):

> Ma Mazda immatriculée "xb-301-zb" viens de passer les 250 000 km! Quoi prévoir pour mon entretien ?

Agent runs async on the `messenger:consume` worker (`RunAgentTurnHandler`). Config: `api/config/packages/ai.yaml`.

## TL;DR

- The original recipe was **catalog-first**: it priced the 4 fixed `get_service_parts` buckets
  (oil_change/filters/brakes/spark_plugs) and never established the manufacturer **schedule**, so it
  missed the 250 000 km headline items (timing belt/chain, coolant, brake fluid, gearbox oil).
- **TecDoc was being called** (lookup_plate → kType, get_service_parts → real part refs). The premise
  "parts came from web search" was wrong — parts are TecDoc; web was barely/not used.
- Reworked to **program-first** (web the schedule → price catalog services → plan all). Conceptually
  right, but the first attempt **regressed** (5 redundant searches, blew the token budget, planned
  only oil_change). After guardrails it improved to **6 tasks incl. coolant** (a schedule-only item).
- Root issue was **control, not model IQ**: Haiku's search queries were on-target (engine pinned);
  it failed on *execution control* (no stop condition) and *budget*.

## Shipped to `main`

| commit | what |
|---|---|
| `3a403e9` | `ServerToolUseLogger` — platform decorator logging Anthropic server-tool use (web_search) per call. Server tools leave no Toolbox call / domain write / message-bag trace (ResultConverter drops the block), so the raw response is the only seam. |
| `f7cc7cb` | render web_search **queries** in the log (default `HttpKernel` logger prints a non-scalar context value as literal `[array]` → join to string). |
| `7427ee4` | **web_search capped to 1/turn**. `WebSearchBudget` (per-turn), fed by the decorator (only seam that sees a search ran), consumed by `WebSearchInputProcessor` (withdraws the tool once spent), reset by `RunAgentTurnHandler`. Also fixes the decoration: `ai.platform.anthropic` is a lazy proxy, so the decorator must be `lazy: true` + carry the proxy tag, else `TraceablePlatform` gets a proxy tag without lazy and the container fails a clean build. |
| `ec3eb47` | **MCP tool upstream failures recoverable**. A handler that throws (e.g. `get_service_parts` on TecDoc HTTP 401) returns JSON-RPC `INTERNAL_ERROR`; `McpToolbox` mapped *every* error to `ToolNotFoundException` (model told the tool doesn't exist; raw exception could escape the loop). Now: `METHOD_NOT_FOUND` → `ToolNotFoundException`; anything else → `ToolExecutionException` (the type `FaultTolerantToolbox` turns into a recoverable error result). |

### Logging note

Worker runs `messenger:consume -v` (SHELL_VERBOSITY=1) → default `HttpKernel\Log\Logger` floors at
**NOTICE**. `info` is dropped; server-tool-use logs at `notice` on purpose. No MonologBundle installed.

## Program-first experiment

Held **uncommitted** in `api/config/packages/ai.yaml` (kept as-is for the next clean re-test):

- `max_tokens` 2048 → **4096** (web result bloats loop context; the turn must still synthesise
  schedule → `add_vehicle_fact` + `plan_maintenance` afterwards).
- WEB SEARCH section reframed: web = the **schedule** source (what/when), one call per turn; catalog
  prices parts.
- SERVICE RECIPE rewritten program-first with a **deterministic candidate list** (so Haiku does not
  derive the service set from scratch):
  - CATALOG (priced by get_service_parts): oil_change, filters, brakes, spark_plugs.
  - SCHEDULE-ONLY (web-confirm, plan without parts): timing belt/chain, coolant, brake fluid, gearbox oil.

### Results (same prompt, fresh DB each time)

> **Correction (facts count):** earlier rows reported `facts: 0` — that was a **measurement error**.
> Facts are `EmbedMany` *inside* the vehicle document (`Vehicle.facts`, keyed map), NOT a
> `vehicle_facts` collection. Counting `db.vehicle_facts` always returned 0. Read correctly, the
> agent **does** persist web facts. A guarded run (even with TecDoc 401) produced:
> `oil_capacity=4.3 liter [web]`, `oil_type=ACEA A3/B3 ou A5/B5 [web]`,
> `distribution_type=courroie de distribution [web]`.

| version | planned tasks | coverage | facts (corrected) |
|---|---|---|---|
| catalog-first (original) | 4 | oil + 3 filters | not re-measured |
| program-first, no guards | 2 | oil_change only (collapsed) | not re-measured |
| program-first + guards | 4–6 (varies) | oil, filters, brakes, **coolant** | **web facts persisted** |

`coolant` + `distribution_type=courroie` are the proof the approach works — schedule items the
catalog can never surface, captured from the single web search (one fact, one task). Web cap
confirmed: 1 search (was 5). Engine pinned in the query
(`Mazda 6 2007 2.0 MZR147 ... distribution courroie chaîne ... liquide refroidissement`).

`add_vehicle_fact` adherence is **fine** — the earlier "model skips facts" conclusion was the
measurement bug, not real behaviour.

## Open issues / next steps

1. **Coverage variance.** Run-to-run the agent plans 4–6 tasks; it does not always create
   brakes/spark_plugs/timing-belt tasks. Haiku non-determinism in how far it works the candidate list.
2. **Schedule items typed as `other`. [FIXED]** `distribution_type=courroie` was captured as a *fact*,
   but the matching task was planned as type `other` because `PlanMaintenanceProcessor` did
   `MaintenanceType::from($data->type)` — which **throws** on any non-enum string, forcing the agent to
   the closed set. Maintenance `type` is now a **free string** end-to-end (entity, planner, repos,
   processors, DTO schema); `MaintenanceType` stays a soft classifier for forecasting/icons
   (`tryFrom` — unknown types just get no interval). The agent can now plan `timing_belt`, `gearbox_oil`,
   etc. as themselves. DTO descriptions list common values as suggestions, not a constraint.
3. **Part-ref backfill on transient catalog failure.** A task planned while TecDoc 401s gets
   `parts: []` (proven — the need survives, the ref does not). `plan_maintenance` is an upsert, so a
   later turn that re-runs the recipe with a reachable catalog refills the parts — but that depends on
   the model re-planning. Deterministic opportunistic backfill (fill open empty-parts tasks when
   `get_service_parts` succeeds) makes it model-independent. **Caveat:** needs a
   `MaintenanceType → ServiceKind + catalog-category` mapping (the model does this mapping today when
   it attaches parts); the multi-category `filters` service (air/cabin/fuel) makes it a name-match,
   the fragile part.
4. **Live validation blocked.** `partsapi.ru` enforces a **per-IP request quota**; repeated test turns
   exhausted it (`HTTP 401: Exceeded the number of requests from the current IP address`). External,
   unknown reset (~hours/next day). Real agent turns hit live partsapi; tests use the fixture
   (carId `500200` → 401 sentinel). Re-run the prompt once the quota resets.
5. **Decide on ai.yaml.** Commit program-first after a clean run confirms coverage/backfill.

## How to re-test (after quota reset)

```sh
# from api/
docker compose exec -T mongodb mongosh app --quiet --eval \
  'db.vehicles.deleteMany({}); db.vehicle_facts.deleteMany({}); db.planned_maintenance.deleteMany({}); print("cleared")'
docker compose restart worker
docker compose exec -T php sh -c 'curl -sk -X POST https://localhost/agent/turn \
  -H "Content-Type: application/json" \
  -d "{\"instruction\":\"Ma Mazda immatriculée xb-301-zb viens de passer les 250 000 km! Quoi prévoir pour mon entretien ?\",\"sessionId\":\"retest\"}"'
# watch: server-tool-use + tool activity
docker compose logs -f worker --since 5s | grep -iE "server tool|web_search|Failed|exception|error"
# check coverage + facts — facts are EMBEDDED in the vehicle doc (Vehicle.facts), NOT a collection
docker compose exec -T mongodb mongosh app --quiet --eval \
  'const v=db.vehicles.findOne(); print("facts:"+Object.keys(v.facts||{}).length+" planned:"+db.planned_maintenance.countDocuments()); for(const k in (v.facts||{})){print("  "+k+"="+v.facts[k].value+" ["+v.facts[k].source+"]")} db.planned_maintenance.find({},{type:1,_id:0}).forEach(d=>printjson(d))'
```
