# TODO — mon-parc-auto

Single source of truth for outstanding work. Backend detail that used to live in
`api/docs/TODO.md` is folded in here; that file is gone. Design docs that are still
active are referenced inline (`docs/next_plan.md`, `agent-findings.md`,
`api/docs/PLAN.md`, `api/docs/AUTH.md`, `api/docs/PARTSAPI.md`).

---

## Open — agent / LLM flow

### Service-recipe tightening (parts + fluids for "vidange"/entretien)
Free-text "pièces pour la vidange" (turn f65593) fetched fluids only (`get_vehicle_facts`)
and never the parts. The system prompt lists the tools but does not compel the recipe.
Encode a service recipe once, referenced from both the system prompt and a
`parts_for_service` / `oil_change` default-action prompt:
- oil change ⇒ oil_type + oil_capacity (`get_fill_volumes`) **and** oil filter part ref
  (`list_part_categories` → `list_articles`, carId) + drain-plug gasket.

### Agent cost: batch render intents to cut LLM round-trips
Tool loop emits **one tool per turn**. Of `lookup_plate → get_vehicle_facts →
list_vehicles → select_vehicle → suggest → toast → patch_panel → final`, only
`lookup_plate→get_vehicle_facts` is genuinely dependent. The 4 render intents
(`select_vehicle`/`suggest`/`toast`/`patch_panel`) have no inter-dependency yet each costs
a full round-trip. Fix: batch into ONE assistant message (Anthropic supports multiple
`tool_use` blocks). Check how Symfony AI emits tool calls / whether parallel tool_use is
enabled. ≈ 2-3 fewer calls ≈ 30-40% fewer round-trips. (Cost is round-trip count, not
config — caching verified healthy.)

Cost framing (from the retired model-usage-plan): with caching on, **output tokens dominate**
— model choice matters less than `effort` + `max_tokens` discipline. Pricing $/MTok (in /
cache-read / out): Opus 4.8 = 5/0.50/25, Sonnet 4.6 = 3/0.30/15, Haiku 4.5 = 1/0.10/5. The
garage agent runs Haiku 4.5; bump only if tool orchestration proves unreliable.

### Grounded maintenance recommendations
Design agreed in `docs/next_plan.md` (250k km milestone scenario). Core gap: no OEM
maintenance-schedule API → agent recommendations are ungrounded. Plan: persist web facts
with `source` URL → surface provenance in UI (`show_fact`) → expose `plan_maintenance`
(currently token-gated) → milestone-aware forecast → confirm loop (forecast →
`propose_actions` card → user confirm → tasks created). Web search = **Option B (custom
Brave/Tavily bridge)**, integration still deferred. Haiku 4.5 verified to support native
`web_search_20250305` (2026-06-18) as the alternative.

### Coverage variance (agent non-determinism)
`agent-findings.md`: Haiku plans 4-6 tasks run-to-run for the same prompt. Decide on the
`ai.yaml` program-first experiment (max_tokens 2048→4096, deterministic SERVICE RECIPE
candidate list) — pending a clean re-test once partsapi quota resets. Part-ref backfill on
transient catalog failure (opportunistic refill when catalog later succeeds) still open.

### MCP default-action prompts are dormant (not triggered by our UI)
Garage default actions are registered in `GarageActionPromptLoader.php`
(`list_vehicles`, `whats_due`, `next_to_maintain`, `identify_plate`, `log_service`) and
`FillVehicleInfoPromptLoader.php`, exposed over MCP (`prompts/list`/`prompts/get`) for
external hosts (Goose). **Our own pipeline never uses them** — `AgentTurnController` takes
free-text `instruction`; the dashboard never calls `prompts/list`. Add one of:
- **Dashboard host**: `prompts/list` → quick-action buttons → on click `prompts/get` →
  feed the seed message into `/agent/turn`.
- **Server shortcut**: `/agent/turn` accepts `{promptName, args}`, resolves via the MCP
  registry, runs that as the turn.

Also: save prompt history.

---

## Open — render / UI

### Render vocabulary: split the generic `patch_panel` tool (DEFERRED)
`patch_panel(panel, data)` is the lone "general UI update" tool — the specific-intent
design wants it gone. Split into `patch_next_service` / `set_health` / `patch_cost`. Then:
- **Derive `nextService`** from `PlannedMaintenance` + the time-forecast instead of agent
  push — the data now has a real source.
- **Usage-based "due in X km" is now computable**: `update_vehicle` persists a live
  odometer reading (`vehicle.usageValue`), so the `MaintenanceInterval.php:11` premise
  ("we never store a live reading, so due-in-km is unknowable") is stale. Revisit the
  forecast to use it.

### User-editable cards in UI
Cards should be directly editable (e.g. vehicle card: add a picture or change mileage
inline). Needs write endpoints + per-field edit affordance. Pairs with the usage work above
(`set_usage`/`update_mileage`) and a picture-upload field (Garage storage already shipped).

### PDF garage invoice → extract → client-validated form → submit
User uploads a PDF/photo of an invoice. A Sonnet model extracts work done / parts / date /
mileage / cost → UI shows a **pre-filled form** for review/edit before submit (low-trust
extraction + human confirm gate). Reusable pattern for any LLM-extraction-to-write flow.
See also TODO_UI for ranked UI cards.

---

## Open — data model & catalog

### EAV smell: VehicleFact — investigate MongoDB migration
`VehicleFact` (key/value/unit/source/verified/url rows, OneToMany on `Vehicle`) is a
classic EAV table. Spike migrating facts to an embedded subdocument array on a vehicle
document (MongoDB), dropping the join + per-row metadata columns. Evaluate: API Platform
ODM support, the shared-garage write-through resolver, the Mercure fact/created events,
querying facts by key. Decision pending — spike first.

### Item Get operations
`/makes/{id}`, `/part_categories/{id}` etc. JSON-LD item @ids currently 404 when
dereferenced (clean NotExposed). Back them with cached collection lookups. FillVolume worst
case: synthetic positional id → `/fill_volumes/0` collides across cars; give it a real
identifier (carId+index) before exposing items.

---

## Open — MCP / platform

- **MCP error mapping**: domain errors (missing token, budget, upstream, unknown plate)
  surface as JSON-RPC `-32603` internalError with the raw exception message — the
  501/429/502 `exception_to_status` contract is REST-only. Decide: catch in processors →
  `CallToolResult` `isError:true` with a sanitized message (don't leak env-var names /
  upstream bodies). Failure shape pinned by `McpServerTest::testPendingTokenToolSurfacesAsJsonRpcError`.
  Garage tools already solved this (misses/refusals = `CallToolResult` isError=false) — same
  pattern is the candidate fix.
- **E — compact MCP projection / dedup (PARTIAL).** Capped + grouped tools return plain
  projected arrays (`structuredContent:false`). Still open: (1) remaining default-Hydra
  tools (`get_fill_volumes`, `lookup_plate`) still serialize the JSON-LD envelope; (2)
  payload **duplication** — `StructuredContentProcessor` ships the data twice (full JSON in
  `content[0]` TextContent AND in `structuredContent`). Negligible under the cap, real 2×
  for non-capped tools. Fix: when `structuredContent` is set, make `TextContent` a short
  summary, not the full re-encode (touches the fork `StructuredContentProcessor`). Decide the
  general policy: which tools emit Hydra vs plain structured arrays.
- **D-follow-up — mandatory filters (pending).** Could make `search` REQUIRED on
  `list_part_categories`/`list_makes`. NOT done: cap + offset pagination + hint already
  prevent the explosion while preserving discovery. Revisit only if blind listing proves a
  problem. (`carId` is already mandatory.)
- **Live end-to-end run inside Goose Desktop** against the OAuth-protected `/mcp`: DCR
  against Casdoor, browser login, prompt for a vehicle's maintenance history. Also unused so
  far: `ui/message` (push chat summary) + `tools/call` refresh in the MCP Apps bridge.
- **Rate limiting / per-client quotas** before any public exposure.
- **MCP resources** (e.g. `resource://mon-parc-auto/coverage` notes for LLMs).
- **Auth follow-ups** (Casdoor, see `api/docs/AUTH.md`):
  - Rename `ZitadelUserProvider` → Casdoor; its Zitadel roles claim is inert → everyone gets
    `ROLE_USER`. Remap via Casdoor groups/roles → a claim the provider reads.
  - Configure Casdoor social-account linking by VERIFIED email so one human = one Casdoor
    user (prevents Google-UUID ≠ GitHub-UUID split identity).
  - Wire per-user cars: set `Vehicle.owner` (+ `MaintenanceRecord.owner`) = `sub` when
    leaving the phase-1 shared garage. Key on `sub` (immutable), NOT email.
  - MCP `400 "valid session id REQUIRED"` on initialize — pre-existing mcp-bundle handshake
    quirk (not auth; reproduces with a valid token). Likely hits Goose.

---

## Known bugs

- **JSON-LD ItemNormalizer drops read-only props** added to an existing resource — stale
  metadata cache `cache:clear` does NOT invalidate; only `rm -rf var/cache/<env>` fixes it
  (worker restart alone is a no-op). Full diagnosis + mechanism in
  `api/docs/resolved/jsonld-readonly-props-dropped.md`. Upstream:
  https://github.com/api-platform/core/issues/8332. Red test:
  `GarageVehiclePatchTest::testItemGetExposesFactsWithProvenance`. Durable fix (track
  resource paths as content-sensitive, or non-persistent metadata pool in dev/test) pending.

---

## Ops / deployment

### Deploy MUST rebuild the cache AND restart the worker
The `messenger:consume` worker (`api/compose*.yaml`, service `worker`) is a long-lived
process: it loads the compiled container (service wiring, compiler passes like the
agent tool-call cap) **and** the API Platform metadata/tool schemas into memory at boot,
then holds them for its whole life. Class *bodies* autoload fresh from disk, but the
compiled container and cached schemas do **not** — so shipping code without rebuilding
the cache and restarting the worker leaves it advertising the OLD tool schema while
running NEW handler code.

Incident (2026-06-21): `log_maintenance` moved from `{type, partsUsed}` to `{items[]}`.
The worker kept advertising the old schema, so Haiku sent `type`/`partsUsed`, the fresh
processor rejected the empty `items`, and the agent looped 46 times burning ~8.5k tokens
before the (also-stale, so absent) cap could fire. Cleared by `rm -rf var/cache/<env>` +
`docker restart worker php`.

Deploy step (encode in the pipeline / worker entrypoint):
1. `rm -rf var/cache/$APP_ENV` (NOT `cache:clear` — see Known bugs: it is a no-op for the
   metadata cache) then `bin/console cache:warmup`.
2. Restart the `worker` (and `php`) container so it boots on the fresh cache.

Candidate durable fix: bake `rm -rf var/cache/$APP_ENV && cache:warmup` into the worker
container entrypoint before `messenger:consume`, so every (re)start is self-healing.

---

## Pending tokens / data from user

- [ ] `PARTSAPI_KEY_getModels` — activates `/makes/{makeId}/models` + `list_models`
- [ ] `PARTSAPI_KEY_getCars` — activates `/makes/{makeId}/models/{modelId}/cars` +
      `list_cars`
- [ ] Decide make/model browse → kType flow UX (getCars returns modifications w/ kType)

### Phase 2 (each = a new partsapi token, see `api/docs/PARTSAPI.md`)
- [ ] `getArticleMedia` — photos/PDF for an article
- [ ] `getArticleCriteria` — article attributes/dimensions (also unblocks intra-brand
      variant disambiguation, e.g. MAHLE OC1182 vs OC1641)
- [ ] `getArticleCrosses` / `getCrosses` / `tecdocCrosses` — OE ↔ aftermarket crosses
- [ ] `getPartsbyVIN` — direct parts by VIN (coverage < kType route)
- [ ] `VINdecode` — fallback when plate lookup fails / non-FR. Free alt: NHTSA vPIC
      (https://vpic.nhtsa.dot.gov/, no key, US-centric) — evaluate before paying.
- [ ] Coolant spec stays Russian free-text (cosmetic, unmapped)

---

## Architecture — Garage store (multi-tenancy still deferred)

Decision 2026-06-12: MCP is NOT the source of truth. It's **structured memory + an
authoritative-data gateway**; the LLM is the sourcing agent. Catalog misses get filled once
by the LLM, persisted, served from cache next time. Scope = **hybrid** (per-user garage +
promotion to a shared layer), PHASED.

**Phase-1 SHIPPED 2026-06-13** (pinned by `tests/Functional/McpGarageTest.php`): `Vehicle`
(nullable `owner` seam) + `VehicleFact` (`fact_key` col, UNIQUE(vehicle_id, fact_key));
`GarageResolver` (shared garage → upstream write-through → miss-contract); `get_vehicle_facts`
+ `add_vehicle_fact`; verified partsapi/siv facts refuse llm/user overwrite.

**Still deferred — multi-tenancy** (`/mcp` is Casdoor-protected but `owner` is null → single
SHARED garage). Build the value loop now; add per-user isolation + promotion later (set
`owner` = authenticated `sub`) as a migration, not a rewrite. Seams already in place:
provenance fields (`source`/`verified`/`addedAt`/`url`; `verified` doubles as promotion
marker), nullable `owner`/`addedBy`.

**Target 3-store read resolver**: user garage → shared layer → upstream (write-through into
shared) → **miss** → structured `not_in_catalog` result (NOT an error). Promotion gate
(per-user → shared) requires explicit user confirmation. On read, ALWAYS surface provenance
("5W-40 ~3.5 L — general knowledge, NOT manufacturer-verified" vs "per TecDoc"). Tools to
add: `add_vehicle`, `verify_vehicle_fact`/`promote_fact`.

**Why it matters for the long tail** (TORO, vintage Cagiva): these never enter partsapi.
The garage is the only way they get answered fast — first ask = LLM sources + writes; every
later ask = cache hit.

---

## Backlog — Agri / groundscare segment (future product line)

Extend beyond automotive to agricultural + groundscare machinery (tractors, harvesters,
mowers, snow throwers like TORO 246-H). TecDoc/partsapi is automotive only.

- **agroparts** (LexCom, München) — biggest multi-brand agri platform, right data type.
  INVESTIGATED 2026-06-12: **no public API** — B2B/EDI partner interfaces only, negotiate a
  LexCom partnership (contract + fee). ~22 brands (MTD, Cub Cadet, Kubota, Krone, Pöttinger,
  Valtra…), **NO TORO**. Business-development task, not engineering.
- **AGCO Parts API** — OAuth2, documented, single brand-group (Massey Ferguson/Fendt/Valtra).
  Proven but narrow.
- Gated dealer platforms (skip for personal use): **Kramp** (best coverage, dealer+DMS
  gated), **Granit Parts** (no open API).
- **Free scrapable sites** (the groundscare long-tail win — personal project = redistribution
  concerns moot, scrape gently): Toro official (toro.com/en/parts), PartsTree, Jack's Small
  Engines, eReplacementParts, RepairClinic — all model → exploded-diagram → OE part-# lookups,
  no login. **agro-specs.com** = SPEC layer (engine/transmission/dims, NOT parts), URL shape
  `agro-specs.com/lawn-tractors/<id>-<make>-<model>.html`. TODO: scraper → parse spec table →
  feed `add_vehicle_fact` (source=`web:<url>`, verified=false). Verified parseable on Wheel
  Horse 246-H (2026-06-16).
- CAVEAT: part-# sites give **part numbers + diagrams**, NOT fluid specs. "TORO 246-H spark
  plug?" → ✅ OE plug #. "oil type/amount?" → ❌ (comes from the engine maker
  Tecumseh/B&S/LCT manual → LLM territory, store as unverified garage fact). Until a source
  exists, MCP returns clean "not in catalog" → LLM falls back to general knowledge.

---

## Reference — don't re-investigate

- **No open parts/fitment/OE-cross/fill-volume/maintenance-schedule dataset exists** and
  won't: that data is licensed commercial IP (TecDoc et al.), can't be legally published
  open. The moat layer is a **vendor problem (partsapi, in hand)**, never an open-dataset
  problem. GitHub has only an identity/spec layer (`abhionlyone/us-car-models-data` US/frozen,
  `vbalagovic/cars-dataset` proprietary sample) — the layer an LLM already knows.
- **auto-data.net Car Specs API** (api.auto-data.net) — EVALUATED 2026-06-21, parked as a
  *potential future* spec/fluids source. Commercial, pay-per-parameter. ~55k modifications,
  hierarchy brand→model→generation→modification(engine), 14 langs incl. FR. Delivery =
  **bulk XML/JSON dump you store + re-download ~every 5h** (NOT a per-vehicle real-time API →
  clashes with our on-demand tool arch; needs Mongo import + make/model/gen/modif→vehicle
  matching layer). HAS: engine oil spec + capacity, coolant/fuel/AdBlue capacity, power/
  torque/dims/emissions. LACKS the three things we actually need: ❌ service/maintenance
  **intervals**, ❌ OEM **part numbers**, ❌ **VIN** lookup. Only real overlap = structured
  oil capacity + fluids (could replace brittle Motul `__NEXT_DATA__` scrape) but Motul still
  wins on exact SAE grade. Revisit ONLY if fluid-capacity/spec enrichment becomes a priority;
  not worth the import+matching cost for forecasting (no intervals). Automotive analogue of
  the agri-segment `agro-specs.com` spec layer noted above.

## Non-goals (for now)
- stdio transport (explicitly not needed)
- Hosting TecDoc dump (120 GB) — partsapi covers it
- SIV direct license (bulk dump, not a lookup API)

---

## Shipped (changelog)

- **2026-06-18** — `BrandTier` ranking: article cap was hiding premium brands (alphabetical
  partsapi order → cap surfaced only A/digit no-names; MANN-FILTER buried at pos 109). Now
  groups by brand, ranks tier+priority, keeps top 6 brands each with all fitting refs. Tests:
  `BrandTierTest`, `ArticleGroupTest`, `McpCatalogTest`.
- **2026-06-18** — Agent hardening: `ServerToolUseLogger` decorator, web search capped 1/turn
  (`WebSearchBudget`), MCP upstream failures recoverable (`ToolExecutionException` not
  `ToolNotFoundException`). See `agent-findings.md`.
- **2026-06-16** — MCP item-count cap (`CappedResult::of()`, `DEFAULT_LIMIT=10`, MCP-side
  only, offset pagination, NOT caller-overridable); article-per-category cap via
  `ArticleGroup::of()`. Pinned by `McpCatalogTest`.
- **2026-06-15** — Auth on `/mcp`: **Casdoor OIDC** (replaced Zitadel; Casdoor has anonymous
  RFC 7591 DCR that stock Goose needs). `CasdoorTokenHandler` validates JWTs locally
  (JWKS + sig + iat/nbf/exp + iss; audience check DROPPED — Casdoor sets `aud`=dynamic
  client_id). Verified live. See `api/docs/AUTH.md`.
- **2026-06-15** — MCP Apps extension (`io.modelcontextprotocol/ui`) wired (app-side only):
  `EnableMcpAppsExtensionPass`, `get_maintenance_history` `_meta.ui.resourceUri`,
  `UiResourceLoader` serving `maintenance-history.html` (real ext-apps postMessage protocol
  vs the goose fork). Pinned by `McpAppsUiTest`. mcp/sdk bumped to 0.6.0 via path-repo
  symlinks to local forks (DEPLOY CAVEAT: forks absent in Docker — merge/tag/bake before
  container deploy).
- **2026-06-13** — Persisted `PlannedMaintenance` checklist (renders like facts via item
  refetch on Mercure; survives reload; feeds "Tâches ouvertes"). `plan_maintenance` upsert,
  `log_maintenance` auto-closes matching open task, tick-off via PATCH.
- **2026-06-13** — `Vehicle.usageValue`/`usageUnit` + `update_vehicle` tool + dashboard
  inline edit + SERVICE RECIPE step 0 (persist plate/usage before parts lookup).
- **2026-06-13** — Garage phase-1 (see Architecture section above).
- **2026-06-13** — Persistence: PostgreSQL stood up, all cache (siv + partsapi) on PG pools
  (`cache_items`, prototype-compatible keys), legacy `var/` data imported via
  `app:cache:import-legacy`. dama/doctrine-test-bundle for transactional test isolation.
