# TODO

## Persistence — DONE 2026-06-13 (all four items)

PostgreSQL stood up and everything migrated. Suite: 40 tests / 223 assertions green.

1. [x] `symfony/orm-pack` + DoctrineBundle/migrations. dama/doctrine-test-bundle for
   transactional test isolation — NOTE: `allow-contrib=false` ignored its recipe, bundle
   registered manually in config/bundles.php + PHPUnit extension in phpunit.dist.xml.
2. [x] postgres:16-alpine `database` service (compose.yaml, healthcheck, `database_data`
   volume); dev publishes fixed host port 5432 (compose.override.yaml — host tests connect
   to 127.0.0.1:5432; unrelated ap8034-pg sits on 5433). Test DB = `app_test` via doctrine
   `dbname_suffix` (no DATABASE_URL in .env.test). `pdo_pgsql` added to Dockerfile.
3. [x] ALL cache in Postgres: PSR-6 pools `cache.siv` + `cache.partsapi` on
   `cache.adapter.doctrine_dbal` (`cache_items` table, items never expire). Keys:
   siv = normalized plate (full envelope stored); partsapi = `<method>.<sha1>` with the
   prototype_1 signature algorithm (sha1s byte-identical), budget counter = `calls.<method>`.
   Legacy var/ data imported via `bin/console app:cache:import-legacy <dir>`
   (idempotent; `bin/import-prototype-cache.sh` is now a thin wrapper). The old
   anonymous-volume var/ deploy concern is moot — cache lives in the `database_data` volume.
4. [x] Garage phase-1 — see **## Garage** status below.

## Now (this prototype)

- [x] SivClient (plate lookup, PG cache pool `cache.siv`, prototype-compatible keys)
- [x] PartsApiClient (per-method keys, PG hard cache `cache.partsapi`, per-method budget)
- [x] Vehicle / Make / PartCategory / Article / FillVolume resources + MCP tools
- [x] Model / Car resources wired (501 until tokens provided)
- [x] MCP endpoint `/mcp` (HTTP transport only, no stdio)
- [x] Functional tests w/ fixture-backed mock HTTP client
- [x] Import prototype_1 cache — `bin/console app:cache:import-legacy <dir>` (idempotent,
      writes straight into the PG pools; `bin/import-prototype-cache.sh` wraps it).
      Already run against the local paid `var/` data (verified in `cache_items`).
- [x] Persist vehicles — Garage phase-1 shipped, see **## Garage** status below.
- [ ] Item Get operations (e.g. `/makes/{id}`, `/part_categories/{id}`) — JSON-LD item @ids
      currently 404 when dereferenced (clean NotExposed); back them with cached collection lookups.
      FillVolume worst case: synthetic positional id → `/fill_volumes/0` collides across cars;
      give it a real identifier (e.g. carId+index) before exposing items
- [ ] MCP error mapping: domain errors (missing token, budget, upstream, unknown plate) surface
      as JSON-RPC `-32603` internalError with the raw exception message — the 501/429/502
      `exception_to_status` contract is REST-only. Decide: catch in processors → CallToolResult
      `isError: true` with sanitized message (don't leak env-var names / upstream bodies to clients).
      Failure shape pinned by McpServerTest::testPendingTokenToolSurfacesAsJsonRpcError
      (Garage tools already solved this for their domain: misses/refusals return
      CallToolResult isError=false — same pattern is the candidate fix here.)

## Garage — self-strengthening vehicle store (core architecture)

Decision 2026-06-12: MCP is NOT the source of truth. It's **structured memory + an
authoritative-data gateway**; the LLM is the sourcing agent. Catalog misses get filled
once by the LLM (web/general knowledge), persisted, and served from cache next time.
Chosen scope: **hybrid** (per-user garage + promotion to a shared layer) — but PHASED.

### Phase-1 status: SHIPPED 2026-06-13

Everything in the "Now" column below exists and is pinned by tests/Functional/McpGarageTest.php:
- `App\Entity\Vehicle` (nullable `owner` seam, `vehicleType` IS part of identity,
  `year` part of identity when given) + `App\Entity\VehicleFact` (`fact_key` column —
  `key` is reserved in PG; UNIQUE(vehicle_id, fact_key), re-add = update-in-place).
- `App\Garage\GarageResolver`: shared garage → upstream (GetFillVolumes write-through,
  source=partsapi verified=true, via FillVolumeFactMapper) → miss-contract result.
- MCP tools `get_vehicle_facts` + `add_vehicle_fact` on `App\ApiResource\Garage`.
  Misses/refusals are `CallToolResult` with isError=false — never the -32603 path.
  GOTCHA (saved to agent memory): heterogeneous tool payloads must return CallToolResult
  directly (plain arrays get reshaped into Hydra Collections) + `structuredContent: false`.
- Precedence enforced: verified partsapi/siv facts refuse llm/user overwrite (structured
  refusal naming the existing fact).

### Phasing (decided 2026-06-12): build the loop now, defer multi-tenancy

`/mcp` is now Casdoor-OIDC protected (auth SHIPPED 2026-06-15, see "## MCP / platform"), but
multi-tenancy is still deferred: `owner` is nullable/null → SINGLE SHARED garage. Build the
value (write-back loop + provenance + miss-contract) on the shared garage now; add per-user
isolation + promotion (set `owner` = the authenticated `sub`) later. Seams that make later
cheap (build these now):
- Provenance fields from day 1 (`source`/`verified`/`addedAt`/`url`) — needed for trust
  now anyway; `verified` doubles as the future promotion marker.
- Nullable `owner`/`addedBy` column now (`null` = shared/global). Per-user later = backfill
  + non-null + scope resolver. Migration, not rewrite.

| Now (2-layer resolver) | Later (with auth → 3-layer) |
|---|---|
| `Vehicle` + `VehicleFact` entities | `owner` scoping (non-null) |
| provenance fields | `promote_fact` workflow |
| resolver: shared garage → upstream → miss | private overlay layer above shared |
| write-through cache | per-user isolation |
| `add_vehicle_fact` (writes `verified=false`) | `verify_vehicle_fact` → promote |
| miss-contract result | |


### Three stores + read resolver (precedence)

1. **User garage** (private, per-user) — user's vehicle list + facts sourced for them,
   incl. unverified LLM facts before promotion, and personal corrections.
2. **Shared layer** (global) — write-through cache of upstream hits [source=partsapi,
   verified] + user facts promoted after verification. Benefits all users.
3. **Upstream** (partsapi/TecDoc/SIV) — cold, authoritative, pay-per-call (already cached).

Read order: user garage → shared layer → upstream (→ write-through into shared) →
**miss** → structured `not_in_catalog` result (NOT an error).

### The miss contract (tool-result-as-next-prompt)

On total miss, return an actionable result, e.g.:
```json
{ "status": "not_in_catalog",
  "vehicle": { "make": "...", "model": "...", "year": null, "type": "moto" },
  "missing": ["oil_type", "oil_capacity"],
  "how_to_add": { "tool": "add_vehicle_fact", "schema": { } } }
```
LLM reads it, gathers the data, calls the add tool. Reliable — don't rely on tool
descriptions alone to trigger the gather+write loop.

### Provenance & trust (non-negotiable — or the DB rots)

- Every fact tagged: `source` {partsapi|siv|user|llm|web:<url>}, `verified` bool,
  `addedAt`, `addedBy`, optional `url`.
- **Precedence: partsapi (verified) NEVER silently overwritten by an LLM-sourced fact.**
  User garage may override for that user only (personal correction). Unverified LLM facts
  are overlay/fallback.
- On read, **always surface provenance**: "5W-40, ~3.5 L — general knowledge, NOT
  manufacturer-verified" vs "per TecDoc". The tag is what stops a hallucinated capacity
  becoming a confident lie.
- **Promotion gate** (per-user → shared): requires explicit user confirmation (or
  moderation). Nothing reaches the shared layer unverified → prevents poisoning.

### Data model

- `Vehicle` (identity): make, model, year, modification, kType?, VIN/plate?,
  vehicleType {car|moto|CV|groundscare}, source.
- `VehicleFact`: typed `key` + `value` + `unit` + provenance fields. Keys e.g. oil_type,
  oil_capacity, spark_plug_ref, tire_size, maintenance_item, part_number.

### Tools

- existing lookup tools → garage-first resolver (write-through on upstream hit)
- `add_vehicle` (identity → user garage)
- `add_vehicle_fact` (typed fact → user garage, source=llm/user, unverified)
- `verify_vehicle_fact` / `promote_fact` (user confirms → shared layer, verified)
- `list_garage`, `get_vehicle`

### Why this matters for out-of-catalog (TORO, vintage Cagiva)

These never enter partsapi. The garage is the ONLY way they ever get answered fast: first
ask = LLM sources + writes; every later ask = cache hit. Turns the LLM's general knowledge
into persistent structured data. No need to license agroparts/Kramp for the long tail.

## Pending data/tokens from user

- [ ] `PARTSAPI_KEY_getModels` — activates `/makes/{makeId}/models` + `list_models` => d74b4f69cc26eccd6e4447aa1f4ea5db
- [ ] `PARTSAPI_KEY_getCars` — activates `/makes/{makeId}/models/{modelId}/cars` + `list_cars` => 7155c2e603d7fb82eb14c85ac2eb028e
- [ ] Decide make/model browse → kType flow UX (getCars returns modifications w/ kType)

## Phase 2 (each = new partsapi token, see docs/PARTSAPI.md)

- [ ] `getArticleMedia` — photos/PDF for an article
- [ ] `getArticleCriteria` — article attributes/dimensions
- [ ] `getArticleCrosses` / `getCrosses` / `tecdocCrosses` — OE ↔ aftermarket crosses
- [ ] `getPartsbyVIN` — direct parts by VIN (coverage < kType route)
- [ ] `VINdecode` — fallback when plate lookup fails / non-FR
      - Free alt: NHTSA vPIC https://vpic.nhtsa.dot.gov/ (no key, batch decode endpoint).
        US-centric coverage but worth evaluating before paying for partsapi VINdecode.
- [ ] Coolant spec stays Russian free-text (cosmetic, unmapped)

## MCP / platform

- [x] mcp/sdk bumped to **0.6.0** (2026-06-15) — carries the MCP Apps extension primitives:
      `Mcp\Schema\Extension\Apps\McpApps` (EXTENSION_ID `io.modelcontextprotocol/ui`, URI_SCHEME
      `ui`), `UiToolMeta` (`{resourceUri?, visibility?}` = the `_meta.ui` descriptor),
      `Builder::enableExtension()`. Neither mcp-bundle 0.9 nor api-platform/mcp 4.3 support sdk 0.6
      on any released/dev branch (both pin/call removed 0.5 symbols: `Schema\Resource`→
      `ResourceDefinition`, `ArrayLoader`→`ReflectedElementLoader`, registry `$isManual`/`clear()`/
      `getDiscoveryState()` dropped). Installed via **path-repo symlinks to local forks**:
      `symfony/mcp-bundle` ← `~/forks/mcp/ai/src/mcp-bundle` (branch `bump-mcp-sdk-0.6`, requires
      `mcp/sdk ^0.6`); `api-platform/mcp` ← `~/forks/core/src/Mcp` (branch `fix/mcp-sdk-0.6-compat`).
      Suite green on 0.6 (50 tests / 303 assertions).
      DEPLOY CAVEAT: path symlinks point at `~/forks` → absent in Docker. Before any container
      deploy, either merge the fork PRs upstream + use tags, or commit composer patches, or bake the
      forks into the image.
- [x] MCP Apps extension (`io.modelcontextprotocol/ui`) WIRING — SHIPPED 2026-06-15, pinned by
      tests/Functional/McpAppsUiTest.php (initialize advertises the extension; tool links + serves
      the app). All app-side, no fork changes (forks stay pure 0.6-compat):
      (1) `App\Mcp\EnableMcpAppsExtensionPass` (registered in `Kernel::build`) adds
      `enableExtension(new McpApps())` to the `mcp.server.builder` definition → `initialize` announces
      `capabilities.extensions["io.modelcontextprotocol/ui"]`.
      (2) `get_maintenance_history` McpTool sets `meta: ['ui' => ['resourceUri' => …]]` — `Tool::
      jsonSerialize()` emits it under `_meta`, so the host reads `_meta.ui.resourceUri`. NO core change.
      (3) `App\Mcp\UiResourceLoader` (a `mcp.loader`) registers the `ui://mon-parc-auto/
      maintenance-history` ResourceDefinition (mimeType `text/html;profile=mcp-app`, descriptor
      `_meta.ui` = `McpApps::resourceMarker()` stdClass — done via a loader, not `#[McpResource]`,
      because PHP attribute args can't hold a `\stdClass`). Handler serves the HTML.
      (4) UI bundle: `resources/mcp-ui/maintenance-history.html` — self-contained service-timeline
      cards (type icon, date, usage, cost, performedBy, parts chips, provenance badge), vanilla JS,
      no external loads (CSP-friendly). Resource-content `_meta.ui` carries `prefersBorder` via
      `UiResourceContentMeta` (CSP omitted = same-origin default, which suits the no-external app).
      App-bridge implements the REAL ext-apps protocol (spec 2026-01-26, verified against the local
      goose fork at ../goose): JSON-RPC over postMessage to the host — `ui/initialize` (→ hostContext
      theme), `ui/notifications/initialized`, `ui/notifications/size-changed`, and renders on the
      `ui/notifications/tool-result` notification (reads `params.structuredContent` = our
      get_maintenance_history envelope). Theme also rides `prefers-color-scheme` (Goose's outer-proxy
      patches matchMedia). `window.__MOCK__` = standalone-preview fallback. Verified in headless
      Chrome by simulating the host handshake + tool-result push (cards rendered).
      TEST TARGET: Goose (../goose) supports `io.modelcontextprotocol/ui` — connect via the OAuth
      flow (RFC 9728 metadata already wired) and prompt for a vehicle's maintenance history.
      REMAINING POLISH (deferred): live end-to-end run inside Goose Desktop against the OAuth-
      protected `/mcp`; `ui/message` (push a chat summary) + `tools/call` refresh are unused so far.
- [x] Auth on `/mcp` — **Casdoor OIDC, SHIPPED 2026-06-15** (replaced Zitadel). Stock Goose
      (rmcp) needs anonymous RFC 7591 Dynamic Client Registration; Zitadel has no DCR, Casdoor
      does. `CasdoorTokenHandler` (src/Security/) validates JWTs locally (JWKS + RS/ES sig +
      iat/nbf/exp + iss) but DROPS the audience check — Casdoor sets `aud`=client_id (dynamic
      per DCR) and ignores RFC 8707, so no stable audience to pin. AuthZ boundary = issuer +
      signature + ZitadelUserProvider find-or-create on `sub`. dev+prod use it; test keeps the
      fake handler. Verified live end-to-end (DCR 201, JWT passes firewall, bad/missing → 401,
      compose casdoor boots on Postgres with discovery on :8000). See docs/AUTH.md.
      Casdoor `sub` = user's immutable UUID; one Casdoor user links many social providers
      (google/github fields) → same sub regardless of login method. Setup: org `dcrPolicy=open`
      (disabled by default) — manual step, see AUTH.md.
      FOLLOW-UPS:
        - [ ] Rename `ZitadelUserProvider` → Casdoor; its Zitadel roles claim
              (`urn:zitadel:iam:org:project:roles`) is inert against Casdoor → everyone gets
              ROLE_USER. Remap via Casdoor groups/roles → a claim the provider reads.
        - [ ] Configure Casdoor social-account linking by VERIFIED email so one human = one
              Casdoor user (prevents split identity: Google-signup UUID ≠ GitHub-signup UUID).
        - [ ] Wire per-user cars: set `Vehicle.owner` (+ MaintenanceRecord.owner) = `sub` when
              leaving the phase-1 shared garage. Key on sub (immutable), NOT email (mutable).
        - [ ] MCP `400 "valid session id REQUIRED"` on initialize — pre-existing mcp-bundle
              handshake quirk (not auth); reproduces with a valid token too. Likely hits Goose.
- [ ] Live end-to-end run inside Goose Desktop: point Goose at `http://localhost/mcp`, let it
      DCR against Casdoor, complete the browser login, prompt for a vehicle's maintenance history
- [ ] Rate limiting / per-client quotas before any public exposure
- [ ] MCP resources (e.g. `resource://mon-parc-auto/coverage` notes for LLMs)

## MCP ergonomics (from XB-301-ZB session 2026-06-16)

Triggered by a real Mazda6 session: `list_part_categories` returned 97k chars →
exceeded the tool-result token cap → dumped to file → model forced to `grep`.
`list_articles` called 4× (one per filter category). ~7 calls to answer "what oil/filter
at 250000 km". A/B/C being implemented now; D/E/intent deferred below.

- [x] **D — item-count cap at the data root — SHIPPED 2026-06-16.** Root cause measured: the
      97k dump was 497 part categories × 219 chars (NOT primarily the structuredContent
      duplication — that is secondary). `list_makes` (~536 makes) was the same trap waiting.
      Fix: `App\Mcp\CappedResult::of()` caps catalog list tools at `DEFAULT_LIMIT = 10`,
      MCP-side only (REST keeps full lists + its own pagination), returning a plain
      `{status, totalItems, offset, returned, truncated, items, nextOffset?, hint?}` payload.
      The page size is FIXED server-side and NOT caller-overridable (a caller `limit` would
      let the LLM pass a huge value and defeat the cap); callers page with `offset` instead
      (returns `nextOffset` while more remain). Applied to `list_part_categories` and
      `list_makes`; `list_articles`/`get_service_parts` cap articles per category via
      `App\Mcp\ArticleGroup::of()` (keeps `totalArticles`/`truncated`). Pinned by
      tests/Functional/McpCatalogTest.php. Pairs with A (search) to narrow before the cap.
- [x] **F — article cap hid premium brands; now ranked by brand tier — SHIPPED 2026-06-18.**
      Root cause (XB301ZB): `ArticleGroup::of()` `array_slice`d the raw partsapi order, which is
      **strictly alphabetical by brand**, so cap=10 surfaced only leading-A/digit no-names. VERIFIED
      from the cached Mazda6 run (carId 19107): Oil Filter = **220 articles / 126 brands**; the
      recognizable refs were buried — BOSCH pos 29, MAHLE 106, **MANN-FILTER W 712/73 pos 109**,
      **PURFLUX LS357 pos 164**. (ALCO SP-1296, one of the shown, is a cross-ref of W712/73 —
      correct part, unfamiliar label → poor auto-doc hits → looked like "wrong API". API was right.)
      Fix: `App\Catalog\BrandTier` (curated, ORDERED tier-1/tier-2 lists, normalized brand key,
      `rank()`/`classify()`); `ArticleGroup::of()` now groups by brand, ranks by tier+priority,
      keeps the top `MAX_BRANDS=6` brands **each with ALL its fitting refs** (PURFLUX → LS357+LS1176),
      and reports `totalArticles`/`totalBrands`/`truncated`. Fallback is automatic (all-tier-3 →
      top-6 alphabetical). MCP/agent path only; REST collection stays full+paginated. FREE — runs on
      the already-cached raw response. Pinned by `tests/Unit/Catalog/BrandTierTest.php`,
      `tests/Unit/Mcp/ArticleGroupTest.php` (against the real 220-article fixture
      `tests/Fixtures/partsapi/getArticles_19107_PC_100259.json`), `McpCatalogTest`.
      OPEN: variant disambiguation within a brand (which of MAHLE OC1182/OC1641) needs
      `getArticleCriteria`/`getArticleCrosses` (Phase-2 token) — for now show all fitting variants.
      Tier lists are hand-curated OE/aftermarket knowledge (getArticles has no quality signal);
      extend `BrandTier::TIER1/TIER2` as needed.
- [ ] **D-follow-up — mandatory filters (decision pending).** Could make `search` REQUIRED on
      `list_part_categories`/`list_makes` so the LLM cannot list blind. NOT done: the cap +
      offset pagination + hint already prevent the explosion gracefully while preserving
      discovery (the model can still page the whole set to learn the vocabulary). Revisit only
      if blind listing proves a problem in practice. `carId` is already mandatory (typed, no
      default → validation error when absent).
- [~] **E — compact MCP projection / skip JSON-LD envelope — PARTIALLY DONE.** The capped
      tools (`list_part_categories`, `list_makes`) and the grouped/intent tools
      (`list_articles`, `get_service_parts`) now return plain projected arrays via
      `structuredContent: false` — no `@context`/`@id`/`@type` per item. STILL OPEN:
      (1) the remaining default-Hydra MCP tools (e.g. `get_fill_volumes`, `lookup_plate`)
      still serialize the JSON-LD envelope; (2) the DUPLICATION — `StructuredContentProcessor`
      (and our `CappedResult`/`ToolResult`) ship the payload twice: the full JSON string in
      `content[0]` (TextContent) AND the same data in `structuredContent`. With the cap this is
      now ~10 items × 2 (negligible), but for the non-capped tools it is a real 2× cost. Fix:
      when `structuredContent` is set, make `TextContent` a short summary, not the full
      re-encode (spec-clean; touches the fork `StructuredContentProcessor`).
      INTENT NOTE: same lever as the `structuredContent: false` workaround (Garage phase-1
      GOTCHA). Decide the general policy: which tools emit Hydra vs. plain structured arrays.
      TOON encoder was considered for the text half but rejected for now — `structuredContent`
      must stay JSON (MCP schema), so TOON would only help text-reading hosts and only the text
      half; revisit after the duplication + remaining-envelope fixes land.

## Agri / groundscare segment (future product line)

Wanted: extend beyond automotive to agricultural + groundscare machinery (tractors,
harvesters, mowers, snow throwers like TORO 246-H). TecDoc/partsapi does NOT cover this
— automotive only. Out-of-scope queries (TORO oil/spark plug) need a different source.

- [ ] **agroparts** (agroparts.com, LexCom Informationssysteme GmbH, München) — world's
      biggest multi-brand agri spare-parts platform. Right data type (parts + fitment).
      INVESTIGATED 2026-06-12: **no public/developer API**. Only "standard interfaces" for
      after-sales partners (B2B/EDI-grade, gated). Free reg = browser/app lookup only;
      programmatic data = negotiate a LexCom partnership (contract + fee, partner interface),
      NOT a self-serve API key. ToS likely forbids scraping. ~22 brands: MTD Products,
      Cub Cadet, WOLF-Garten, Robomow, Kubota, Krone, Pöttinger, Kverneland, Valtra, Grimme…
      **NO TORO** (own ecosystem) → would NOT answer the TORO 246-H query anyway.
      => Business-development task, not engineering. Pursue only if agri/groundscare becomes
      a committed product line worth a vendor deal. Sales contact via agroparts.com/Contact.
- [ ] **AGCO Parts API** — OAuth2, single-vendor (Massey Ferguson, Fendt, Valtra, Challenger,
      22 brands). Proven API exists. Narrower than agroparts but openly documented.
- [ ] Until a source exists: MCP returns clean "not in catalog" for non-automotive →
      LLM falls back to general knowledge + OEM manual. Don't error, don't fake a kType.
      (Now handled structurally by the **## Garage** miss-contract: LLM sources + writes back.)

### Gated dealer platforms (same wall as agroparts — skip for personal use)

- **Kramp** (kramp.com) — Europe's largest, 500k products / 4,000 brands, agri +
  forest/grasscare, strong FR presence. Has a real API ("Kramp Connect": real-time
  stock/price/product) but dealer-account + DMS gated. Best coverage, still a partnership.
- **Granit Parts** (granit-parts.com) — 250k parts, agri + garden/forestry + forklift +
  hydraulics. Dealer webshop, no open API.
- **AGCO Parts API** (parts.agcocorp.com) — OAuth2, documented, single brand-group
  (Massey Ferguson/Fendt/Valtra). Narrow.

### Free scrapable parts-lookup sites (the groundscare/small-engine long-tail win)

Personal project = redistribution-IP concerns moot; scraping these on demand to fill a
garage miss is viable (be gentle on their servers). All are model → exploded-diagram →
OE part-number lookups, no login:

- **Toro official** — toro.com/en/parts (genuine Toro diagrams + OE part #s by model)
- **PartsTree** — partstree.com/brands/toro (Toro + many groundscare/small-engine brands)
- **Jack's Small Engines** — jackssmallengines.com (exploded views by model)
- **eReplacementParts** — ereplacementparts.com
- **RepairClinic** — repairclinic.com
- **agro-specs.com** — lawn/garden tractors, farm tractors etc. SPEC layer (NOT parts):
  engine make/model + displacement/bore/stroke/hp, transmission, dims/weight, electrical,
  PTO, production years. Complements the part-# sites above (which lack specs). URL shape:
  `agro-specs.com/lawn-tractors/<id>-<make>-<model>.html` (also farm-tractors/ etc.).
  TODO: build a scraper → parse spec table → feed `add_vehicle_fact` (source=`web:<url>`,
  verified=false). Verified shape on Wheel Horse 246-H (2026-06-16): clean key/value spec
  table, easy to parse. Still NOT a manufacturer-verified source → unverified garage facts.

CAVEAT: the part-# sites (Toro/PartsTree/Jack's/eReplacement/RepairClinic) give **part
numbers + diagrams**, NOT fluid specs. "TORO 246-H spark plug?"
→ ✅ OE plug part #. "TORO 246-H oil type/amount?" → ❌ comes from the *engine* maker
(Tecumseh/B&S/LCT) manual → LLM territory, store as unverified garage fact.
Future: a scraper feeding `add_vehicle_fact` would auto-fill the part-number facts.

## Data-sourcing findings (don't re-investigate)

- GitHub open datasets (searched 2026-06-12): only **identity/spec layer** exists —
  `vbalagovic/cars-dataset` (proprietary sample), `abhionlyone/us-car-models-data`
  (US, frozen). That's the layer an LLM already knows → low marginal value.
- **No open parts/fitment/OE-cross/fill-volume/maintenance-schedule dataset exists**
  and won't: that data is licensed commercial IP (TecDoc et al.), can't be legally
  published open. The moat layer is a **vendor problem (partsapi, in hand)**, never an
  open-dataset problem. Stop hunting GitHub for it.

## Non-goals for now

- stdio transport (explicitly not needed)
- Hosting TecDoc dump (120 GB) — partsapi covers it
- SIV direct license (see prototype INIT.md — bulk dump, not a lookup API)
