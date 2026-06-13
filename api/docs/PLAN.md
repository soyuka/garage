# mon-parc-auto.fr API — Plan

Goal: French plate (plaque d'immatriculation) → vehicle infos → TecDoc parts/fluids,
exposed **MCP-first** for LLM connectors, with plain HTTP endpoints as a bonus.
Prototype reference: `../../prototype_1/` (see its `INIT.md` + `PARTSAPI.md` for verified
upstream behavior — golden source for formats, codes and caveats).

## Stack

- API Platform 4.3 (Symfony 8.1, PHP 8.4+), FrankenPHP via compose (root `../`, `docker compose up` running).
- MCP: `api-platform/mcp` + `symfony/mcp-bundle` (official `mcp/sdk`). HTTP transport only
  (**no stdio**), Streamable HTTP endpoint at `/mcp`. Docs reference: `~/forks/docs/core/mcp.md`.

## Data sources

| Source | Role | Auth | Status |
|---|---|---|---|
| api.apiplaqueimmatriculation.com | plate → SIV vehicle data + TecDoc kType | `API_PLAQUE_TOKEN` | ✅ token in `.env.local` |
| api.partsapi.ru | TecDoc parts tree/articles + fill volumes, makes/models | `PARTSAPI_KEY_<method>` (one key **per method**) | ✅ 4 method keys (see docs/PARTSAPI.md) |

Key joining fact (verified in prototype): SIV response carries `tecdoc_car_id` = **kType**.
Same id keys `getSearchTree`, `getArticles` **and** `GetFillVolumes`. Steps 1–3
(getMakes/getModels/getCars) are only needed for the brand/model browse entry, not for plate flow.

`carType` derivation: SIV `genreVCGNGC` starting `VP` → `PC`, else (`CTTE`, `CAM`, …) → `CV`.

## Architecture

```
src/
  Siv/SivClient.php            plate → SIV payload. Disk cache <PLATE>.json (never re-pay a plate).
  PartsApi/PartsApiClient.php  method+params → JSON. Per-method key, hard disk cache,
                               per-method call budget (default 50, env PARTSAPI_CALL_LIMIT).
  PartsApi/CarType.php         enum PC|CV
  Exception/                   MissingApiKeyException(501), BudgetExhaustedException(429),
                               UpstreamException(502) — mapped via api_platform.exception_to_status
  ApiResource/                 DTOs, each with HTTP operation(s) + `mcp:` tools
  State/                       thin providers (HTTP reads) + processors (MCP tools) over shared services
  Catalog/PartsCatalog.php     domain service: makes/models/cars/categories/articles/fillVolumes
  Vehicle/VehicleLookup.php    domain service: plate → Vehicle DTO
```

### Cache compatibility (important)

Disk cache layout/keys are **byte-compatible with prototype_1** so already-paid responses are
reused (`var/partsapi/<method>_<sha1(method?sortedquery-without-key)>.json`, `var/siv/<PLATE>.json`).
Secrets never land in the cache key. Successful responses cached forever; auth/network/API errors
are neither cached nor counted. Budget counters: `var/partsapi/_calls_<method>.json` (per-method —
fixes prototype limitation of a single global counter).

## Resources ⇄ MCP tools

| Resource | HTTP | MCP tool | Upstream | Token |
|---|---|---|---|---|
| Vehicle | GET `/vehicles/{plate}` | `lookup_plate` | apiplaqueimmatriculation | ✅ |
| Make | GET `/makes` | `list_makes` | getMakes | ✅ |
| Model | GET `/makes/{makeId}/models` | `list_models` | getModels | ❌ pending token → 501 |
| Car | GET `/makes/{makeId}/models/{modelId}/cars` | `list_cars` | getCars | ❌ pending token → 501 |
| PartCategory | GET `/cars/{carId}/part-categories?carType=` | `list_part_categories` | getSearchTree | ✅ |
| Article | GET `/cars/{carId}/part-categories/{categoryId}/articles?carType=` | `list_articles` | getArticles | ✅ |
| FillVolume | GET `/cars/{carId}/fill-volumes` | `get_fill_volumes` | GetFillVolumes | ✅ |

Naming: **Vehicle** = a registered vehicle (plate). **Car** = a TecDoc modification (kType id).
`carId` everywhere = kType. Model/Car endpoints are wired but answer **501** until their
partsapi tokens exist — adding `PARTSAPI_KEY_getModels` / `PARTSAPI_KEY_getCars` to `.env.local`
activates them with zero code change.

FillVolume mapping (verified prototype facts): RU-unit duplicate rows (`л.`, `гр.`) skipped;
`fillTitle` is Russian → FR label map ported from prototype `index.php` (`FILL_TITLES_FR`).

## Testing

- `ApiTestCase` functional tests, no Doctrine.
- `tests/Mock/FixtureHttpClient` decorates `http_client` in test env; routes by upstream host +
  query to fixture files under `tests/Fixtures/` (copied from prototype's real, paid responses).
- Test cache dirs isolated under `var/tests/`, purged per test.

## Done / verification log

(keep updated)

- [x] deps installed: api-platform/mcp 4.3, symfony/mcp-bundle 0.9, mcp/sdk 0.5, http-client, test-pack
- [x] clients + resources + MCP tools — 19 functional tests green (incl. MCP initialize/tools/list/tools/call)
- [x] prototype cache imported (`bin/import-prototype-cache.sh` + docker cp into the php container,
      whose `var/` is an anonymous volume — NOT bind-mounted)
- [x] live-verified against compose stack (https://localhost) 2026-06-12, all from cache, 0 paid calls:
      `/vehicles/XC302ZC`, `/cars/34247/part-categories?carType=CV` (34 leaves),
      `…/part-categories/200158/articles` (4 refs), `/cars/34247/fill-volumes` (17 rows mapped FR),
      `/makes/55/models` → 501, MCP full chain lookup_plate → list_part_categories → get_fill_volumes
- [x] `getMakes` verified live (1 paid call, now cached): response shape is camelCase
      `{makeId, makeName}` (NOT TecDoc MFA_*), 536 makes for PC, IVECO = 55

## Gotchas (verified)

- mcp/sdk 0.5 `Implementation::fromArray` uses `empty()` on clientInfo.version —
  a client sending version `"0"` gets rejected ("Invalid or missing version"). Send "0.1"+.
- Item IRIs like `/makes/55` appear in JSON-LD output but have no item operation yet —
  dereferencing them 404s (see TODO).
