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

`/mcp` is currently open (no auth) → no real "user" yet. Build the value (write-back loop
+ provenance + miss-contract) on a SINGLE SHARED garage now; add per-user isolation +
promotion WITH auth, later. Seams that make later cheap (build these now):
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

- [ ] MCP Apps extension (`io.modelcontextprotocol/ui`) — modelcontextprotocol/php-sdk PR #281;
      needs mcp/sdk >= version carrying the extension (currently pinned by mcp-bundle 0.9 → sdk 0.5)
- [ ] Auth on `/mcp` + API (currently open — prototype only, do NOT expose publicly with tokens set)
- [ ] Rate limiting / per-client quotas before any public exposure
- [ ] MCP resources (e.g. `resource://mon-parc-auto/coverage` notes for LLMs)

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

CAVEAT: these give **part numbers + diagrams**, NOT fluid specs. "TORO 246-H spark plug?"
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
