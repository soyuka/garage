# Global Code Review — 2026-06-19

mon-parc-auto.fr · API (Symfony / API Platform, ~10k LOC PHP) + dashboard (React 18 / TS, ~1.7k LOC).

Method: per-subsystem review across performance / correctness / security / architecture, then every finding adversarially re-verified against source by a second agent. **56 raw → 46 confirmed, 10 refuted.** Verifiers downgraded most severities (the first pass over-rated); the numbers below are the adjusted ones.

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High     | 5 |
| Medium   | 13 |
| Low      | 27 |

By dimension: security 13 · correctness 15 · performance 13 · architecture 5.

---

## The two root causes

### Root 1 — Auth posture: `main` firewall is `security: false`

Only `^/mcp` is gated (OAuth/Casdoor). Everything else — the entire REST surface the dashboard uses — runs anonymous (`config/packages/security.yaml:28-29`). Source of 7 of 13 security findings. Two buckets:

- **LIVE NOW:** garage writes (plate/usage/facts/tasks) + `/agent/turn` are anonymously callable today. Fix first.
- **LATENT:** IDOR, public Mercure, client-supplied `owner`, plate/VIN PII reads — only exploitable once per-user auth + non-null `owner` land. Documented Phase-1 tradeoff (`Vehicle.php:19`), but landmines for the multi-tenancy work.

### Root 2 — No tenant scoping: every query hardcodes `owner = null`

`VehicleRepository` reads/writes all filter `owner == null`; `owner` is only ever set from a client-supplied DTO field, never from the authenticated `sub`. The multi-tenancy seam is wired backwards — the value the agent sends wins, not the token. Before the "owner backfill" ships: derive `owner` from the token in the write processors, flip reads to scope by it, remove `owner` from `UpdateVehicleInput`.

---

## Critical

### 🔴 Garage write endpoints fully unauthenticated
`config/packages/security.yaml:28-34` · `src/ApiResource/GarageVehicle.php` · `PlannedTask.php` — security

Anonymous client can `PATCH /garage/vehicles/{id}` (plate, usage), `POST .../facts`, `PATCH /garage/planned-tasks/{id}` (status + arbitrary parts). No operation declares `security:`; processors do zero ownership checks. MCP mirror of these writes is OAuth-protected — the REST path is the gap.

**Fix:** add an `api` firewall mirroring `mcp` (stateless + access_token handler) + `access_control { path: ^/garage, roles: IS_AUTHENTICATED_FULLY }`, or per-operation `security:` expressions. Keep reference catalog reads public if intended.

---

## High

### 🟠 `/agent/turn` unauthenticated, accepts attacker-chosen sessionId + instruction
`src/Controller/AgentTurnController.php:41-63` — security

Anonymous POST drives the LLM with the full garage toolset (writes included). `sessionId` is concatenated unsanitised into the Mercure topic → cross-session render-intent injection (publish `select_vehicle`/`toast` into another user's dashboard). No rate limit → unauthenticated cost amplifier (web_search bounded to 1/turn, but request count uncapped).

**Fix:** gate behind authenticated firewall; derive session topic from authenticated principal not client input; add per-user rate limit.

### 🟠 `isEngine()` hard-matches English "Engine" but client now queries the French (fr-FR) Motul catalog
`src/Motul/MotulCategory.php:27-30` — correctness

Commit `f069fd1` repointed MotulClient to fr-FR (engine category renders "Moteur"). `isEngine()` does `strcasecmp($name,'Engine')` → always false → no SAE grade returned for any French vehicle. Masked because `FixtureHttpClient` keys fixtures by id only, ignoring locale, so tests serve the en-US "Engine" fixture. *(One external assumption — fr-FR label = "Moteur" — unverified vs live site.)*

**Fix:** key on a locale-stable category id from `__NEXT_DATA__` (like FillVolumeFactMapper keys on Russian originalTitle), or match a per-locale label set. Re-capture a fr-FR fixture and assert grade extraction.

### 🟠 No per-user tenant isolation — every user shares one `owner=null` garage
`src/Repository/VehicleRepository.php:34,57,88,104` — security (Root 2)

Multiple distinct principals can authenticate via `/mcp` (Zitadel find-or-create per `sub`), all read/write the same global parc. Intentional Phase-1 per the entity docblock, but a live cross-tenant exposure the moment >1 user authenticates.

**Fix:** set `Vehicle::owner` from token `sub` on create; scope `findShared/findByIdentity/findCandidates` by current owner. Until then restrict to a single trusted user + document at the firewall.

### 🟠 French plates + VINs returned on public unauthenticated GET
`src/ApiResource/GarageVehicle.php:33-34,68-70` — security (Root 1)

Whole shared garage enumerable with no token; `plate`/`vin` are PII. Combined with the critical write gap = full anonymous read+write of every vehicle identity.

**Fix:** gate the reads, and/or drop `vin`/`plate` from the public serialization group; decide explicitly public vs authenticated per field.

---

## Medium (13)

| Dim | Issue | Location | Fix |
|-----|-------|----------|-----|
| corr | Failed agent turn retried 3× → replays `log_maintenance` writes (duplicate history rows) + re-spends LLM/web_search. `budget->reset()` runs on every retry. *(usage-reading dup refuted — monotonic guard)* | `RunAgentTurnHandler.php:47-75` | `max_retries:0` for RunAgentTurn, or idempotency key; don't reset budget on retry |
| corr | Transient MCP handshake failure caches empty tool list for worker lifetime — agent silently loses all garage tools until restart | `Mcp/Agent/McpToolbox.php:59-82` | only memoise non-empty; treat empty/error as failure (throw), or separate `?bool $loaded` flag |
| perf | N+1: facts projection lazy-loads PlannedMaintenance once per vehicle in the list endpoint (only `factCount` kept, facts discarded) | `State/GarageVehicleCollectionProvider.php:35-40` | single `$group` aggregation, denormalized `factCount`, or prime the ReferenceMany |
| perf | `findCandidates` `$or` over kType/plate/vin has no supporting index → scans owner+vehicleType subset on every dedup write | `Repository/VehicleRepository.php:54-76` | add `{owner,vehicleType,kType}`, `{owner,plate}`, `{owner,vin}` (sparse-unique) |
| sec | `fact.url` rendered into `href` with no scheme check → `javascript:` XSS (URL is agent/web-influenceable) | `dashboard/src/components/facts.tsx:34-38` | allowlist `/^https?:\/\//i` via `safeHref()` before emitting anchor; validate backend too |
| sec | Mercure SSE unauthenticated, topics public — full vehicle read-model (plate/VIN/facts) broadcast to anyone who knows the topic IRI *(latent multi-tenant)* | `dashboard/src/useMercure.ts:42-50` | `private: true` updates + subscriber JWT cookie + per-owner topics |
| corr | `resolve()` write-through doesn't catch `UpstreamException` (sibling `backfillCatalogSpecs` does) → partsapi hiccup 500s a vehicle miss | `Garage/GarageResolver.php:58-70` | same catch as backfill, return null → graceful `not_in_catalog` |
| corr | Motul recommendations cached even when page yields zero categories — transient empty/WAF-blocked page pinned forever (TTL 0) | `Motul/MotulClient.php:63-65` | cache only after parse yields ≥1 category, else skip save / short-TTL negative marker |
| perf | Unknown plates never cached → every repeat lookup of a non-existent plate re-hits the paid SIV API | `Siv/SivClient.php:81-84` | cache negative sentinel for "plate not in SIV"; keep transient errors uncached |
| arch | No retry/backoff on any external client — one transient 429/503 fails the user-facing op (finding mis-filed on cache.yaml; real evidence in the 3 client classes) | `SivClient · MotulClient · PartsApiClient` | `RetryableHttpClient` scoped per upstream, retryable 429/5xx/conn-reset only, not 4xx |
| arch | GET item provider does external HTTP fetch + Mongo `flush()` as side effect (also the PATCH provider) — 20s cold-cache stall, breaks GET idempotency. *(bounded: once per vehicle)* | `State/GarageVehicleProvider.php:43` | move backfill to write-time or async Messenger job; never flush in GET |
| arch | Motul fixtures keyed by id only, ignore locale → test green-washes the live fr-FR path (enables the high-sev bug above) | `tests/Mock/FixtureHttpClient.php:128-140` | key fixtures by locale+id, add fr-FR capture, pin test locale |

*Note: two N+1 findings on GarageVehicleCollectionProvider are the same defect reported twice (lines 35-40 / 40-44).*

---

## Low (27 — selected highlights)

| Dim | Issue | Location |
|-----|-------|----------|
| sec | JWT audience check intentionally dropped — any token from the issuer authenticates `/mcp` (defense-in-depth; Casdoor can't pin `aud` per RFC 8707) | `Security/CasdoorTokenHandler.php:132-141` |
| corr | Email overwritten with `null` when claim absent on existing user → silent data loss | `Security/ZitadelUserProvider.php:41-50` |
| sec | Item GET/PATCH providers resolve by ULID with no owner scoping → latent IDOR *(Root 2)* | `State/GarageVehicleProvider.php:32-51` |
| sec | Multi-tenancy `owner` is client/agent-supplied, not derived from token *(Root 2)* | `State/UpdateVehicleProcessor.php:86` |
| sec | Full SIV envelope (VIN) cached forever, keyed by plate, no TTL — GDPR retention/minimization (disclosure-channel angle refuted) | `Siv/SivClient.php:46-49` |
| corr | PartsApi call-budget counter is non-atomic read-modify-write (only races if worker scaled >1; single worker today) | `PartsApi/PartsApiClient.php:108-126` |
| corr | Toast auto-dismiss only schedules the last toast; stacked toasts drain LIFO slowly, countdown resets | `dashboard/src/components/Toasts.tsx:18-23` |
| corr | `clearDoneTasks` uses `Promise.all` → partial dismiss on first failure, generic error, no refetch | `dashboard/src/App.tsx:148-165` |
| corr | `loadVehicles` swallows all errors → blank parc indistinguishable from "API down / logged out" | `dashboard/src/App.tsx:49-58` |
| sec | Default Mercure JWT secret committed in `.env` (prod tooling injects real secret; dev-only fallback) | `.env:103` |
| corr | `engineFromPlate` swallows all `Throwable` → misconfigured SIV token looks like "no engine", no log | `State/MotulOilProcessor.php:119-144` |
| corr | Category→task matching by substring: `'air'` needle can mis-assign cabin filter to engine air-filter task | `Catalog/OpenTaskPartsBackfiller.php:98-107` |
| arch | Hydra parsing (`member ?? hydra:member`) + IRI normalization duplicated, unchecked `as PlannedTask` casts | `dashboard/src/garage.ts:20-29` |
| perf | Inter font from Google Fonts CDN — render-blocking `@import` in shipped bundle | `dashboard/src/design/tokens/fonts.css` |
| perf | Per-turn LLM context: client `summary`/`pendingContext` have no server-side length cap (facts/tasks bounded — "grows every turn" refuted) | `Agent/TurnContextBuilder.php` + `AgentTurnController:58` |
| perf | web_search query text never logged in prod (stream path) — counted but not auditable | `Agent/ServerToolUseLogger.php:43-54` |
| perf | PartsApi responses cached forever, no catalog-version token in key → stale across TecDoc releases | `PartsApi/PartsApiClient.php:46-49` |
| perf | Index-precision + over-fetch items (planned-task `type` residual filter, checklist spec-carrier fetch-and-discard, findShared sort) | `PlannedMaintenanceRepository · VehicleRepository` |

---

## Refuted (10) — do not act on these

Verifiers confirmed these reported problems do **not** exist as described:

- **web_search budget over-spend within a pass** — `max_uses:1` server-enforced + one-pass-late withdrawal; no over-search.
- **MaintenanceRecord sort on `id` vs `_id`** — ODM `prepareFieldName` maps `id`→`_id` for both sort and index. Tie-break works.
- **Unconditional `flush()` on every auth request** — ODM diffs values; unchanged doc = empty changeset = no Mongo write.
- **RenderIntentTools stale session topic** — `bindSession` is the unconditional first statement each turn; single-threaded worker, no overlap.
- **onFrame stale-closure / selection-fallback divergence** — ref pattern + reducer reconciliation already handle it.
- **"No auth credentials on fetch" as vuln** — nothing to authenticate to today; documented pre-tenancy design.
- **JWT zero clock-skew** — faithfully mirrors Symfony's own OidcTokenHandler defaults; NTP makes it moot.
- **PartsApi counter race (2nd report)** / **AgentTurnController docblock contradiction** — not reachable / docblock actually correct.

---

## Recommended order

1. **Now:** gate `/garage/*` writes + `/agent/turn` behind an `api` firewall (kills the critical + 2 highs in one change).
2. **Before multi-tenancy:** derive `owner` from token, scope reads, remove `owner` from DTO, make Mercure private — clears the entire "latent" security cluster.
3. **Correctness, high-value:** Motul fr-FR locale fix (+ fr-FR fixture); agent-turn retry idempotency; McpToolbox empty-tool-cache; `resolve()` upstream catch.
4. **Perf, cheap wins:** N+1 on vehicle collection; dedup-write indexes; negative SIV plate caching; `facts.tsx` href allowlist.
