# Splitting bonneref into its own app

Decided 2026-10-30. bonneref becomes a second Symfony app inside this repo (`bonneref/api` next to its front),
serves its catalog over HTTP on bonneref.fr, and mon-parc-auto (mpa) consumes that API. partsapi.ru disappears
from both products. The repo stays a monorepo because it is the Symfony AI & MCP hackathon submission
(deadline 2026-11-22): one clone runs both. Showcase, not a product.

## Decisions

| question | decision |
| --- | --- |
| location | same repo, `bonneref/api` (Symfony) + existing `bonneref/` front. Reverses an earlier same-day choice of `~/projects/bonneref` + `git filter-repo`: the contest wants one repo |
| in-flight branches | `feat/front-vehicle-applicability`, `fix/per-vehicle-link-qualifiers`, `feat/tecdoc-catalog` are already merged into `main` (`git branch --merged main`, verified 2026-10-30), carve from `main` |
| mpa → bonneref auth | forward the caller's Casdoor bearer. Works as-is: `api/src/Security/CasdoorTokenHandler.php:35-39` drops the `aud` check, so a token minted for any client of the issuer validates on both sides |
| bonneref garage page / save | kept, bonneref front calls the mpa API cross-origin with the same token, mpa stays the garage backend (needs CORS for the bonneref.fr origin) |
| agent stack | copied into `bonneref/api`, each repo prunes the surface it does not use (`TurnSurface::garage` vs `finder`). A shared package only if they stay identical |
| partsapi.ru | removed from both. bonneref prod already runs `CATALOG_SOURCE=tecdoc` without `PARTSAPI_KEY_*` (`docs/tecdoc-sqlite.md` §7). `GetFillVolumes`, the one method without a TecDoc equivalent, has no caller in `api/src` or `api/config` (grep 2026-10-30) |

## The seam is the resource layer, not `PartsCatalog`

`App\Catalog\PartsCatalog` exposes internals (`cachedArticleCriteria`, `vehicleLinkCriteria`, `articleCrosses`,
`upstreamCalls*`) that only make sense next to the data. Implementing it over HTTP would mean publishing all of them.
Instead the whole catalog layer moves to bonneref and mpa calls the API Platform resources bonneref already serves:

| mpa consumer | bonneref endpoint |
| --- | --- |
| list_makes / list_models / list_cars | `GET /makes`, `/makes/{id}/models`, `/makes/{m}/models/{mo}/cars` |
| list_part_categories | `GET /cars/{id}/part-categories` |
| list_articles | `GET /cars/{id}/part-categories/{c}/articles` |
| narrow_parts | `GET /cars/{id}/part-categories/{c}/narrowed` (ArticleNarrower moves to bonneref) |
| get_service_parts | part-categories + articles; `OpenTaskPartsBackfiller` and `ServiceKind` stay in mpa (garage logic) |
| lookup_plate, dashboard `garage.ts:131`, get_motul_oil (`MotulOilProcessor:124`) | `GET /vehicles/{plate}` (SIV moves to bonneref, it pays the plate token) |
| decode_vin | `GET /vins/{vin}` (or keep `VinDecoder` local in mpa, it makes no upstream call) |
| compare_brands | `BrandTier` is static, copy stays in mpa |
| decode_build_code | `Knowledge/BuildCode` stays in both (no upstream) |

mpa gets one `BonnerefClient` (Symfony HttpClient, base URI from env, bearer forwarded from the current request).

## Moves to bonneref only

`Agent/Finder/*`, `Finder/*`, `Controller/Finder/*`, `PhotoIntake/*`, `RegistrationDocument/*`,
`AgentTurnHistoryController`, `FinderSelection`, `TurnImageBatch`, `HideJournalNoiseCommand`,
`config/prompts/finder.md`, the `finder` agent in `ai.yaml`, `Catalog/*` (minus `OpenTaskPartsBackfiller`,
`ServiceKind`), `PartsApi/*` (then deleted), `Siv/*`, `Vehicle/*`, the catalog ApiResources, `bonneref/`,
`docs/tecdoc-sqlite.md`, `api/docs/PARTSAPI.md`. The finder's in-process use of mpa's MCP server
(`app.finder.mcp_toolbox`, `$server: '@mcp.server.garage'`) becomes bonneref's own toolbox.

## Data

One Mongo database today. bonneref takes `siv_lookups`, `catalog_vehicles`, `finder_selections`, `turn_images`,
`agent_turns` where `surface=finder`. `partsapi_responses` is dropped after the switch. `users` is copied.
mpa keeps the rest.

## Open

- In-app garage agent turns run in the Messenger worker, where there is no request to forward a bearer from.
  Decided: `RunAgentTurn` carries the user's access token (sitting in the queue is accepted).
- TecDoc licence: out of scope, it stays inside bonneref.
- Prod `~/bonneref.compose.yml` and `~/bonneref/.env` live only on the server, they become the new repo's compose.

## Order

1. mpa: `BonnerefClient` + consumers switched, test-first with `MockHttpClient`, pointed at the current API (same
   endpoints exist today, so this lands before any carving).
2. Copy the bonneref side of `api/` into `bonneref/api`, prune the garage surface, own compose / Mercure / Mongo DB.
3. Data copy of the bonneref collections.
4. mpa: delete what moved, drop `CATALOG_SOURCE`, `PARTSAPI_*`, `API_PLAQUE_TOKEN`, the `/bonneref` + `/finder`
   Caddy routes and the dev `bonneref` service; CORS for bonneref.fr on `/garage/*`.

Deployed 2026-10-30 at `018aa9f` (server checkout fast-forwarded from detached `f0d0b83` to `main`).
`~/bonneref.compose.yml`: `bonneref-api` now builds `bonneref/api` (same name, port 5382, catalog mount, `php`
alias, db `bonneref`); new `bonneref-garage` builds mpa's `api/` (port 5383, `~/bonneref/garage.env`, db
`garage`, `BONNEREF_API_URL=http://bonneref-api`, publishes to its own hub via `MERCURE_URL=http://localhost/...`
+ `MERCURE_HUB_UPSTREAM=127.0.0.1:80` because `php` resolves to bonneref-api). `vehicles`, `planned_maintenance`,
`users` copied (not moved) into `garage` (5/10/6). Caddy: `/garage/*` → 5383 before the bonneref-api matcher,
which gained `/makes` and `/makes/*`. SPA rebuilt from HEAD in `node:22`. Backups and rollback material are all
suffixed `pre-split-20261030` (mongodump in `~/bonneref/dumps/`, compose, `.env`, Caddy file, site dir, image
`bonneref-api:pre-split`). Not done: the garage keeps the old placeholder `S3_*` (bonneref had no bucket), so
maintenance bill uploads on bonneref.fr fail until a real bucket is set; garage's Mercure hub is internal only.

## Done (mpa side, `api/`, 2026-10-30)

Steps 1 and 4 landed together in the working tree, uncommitted; step 4 stops where noted. Verified against the
HEAD catalogue contract with JSON-LD fixtures captured from HEAD's own responses (`api/tests/Fixtures/bonneref/`).

- `App\Bonneref\BonnerefClient` (scoped client `bonneref.client`, `BONNEREF_API_URL`, default `https://bonneref.fr`)
  is the only door to the catalogue and the plate lookup. Every consumer switched, MCP tool names, input schemas and
  mpa routes unchanged (`GET /vehicles/{plate}` proxies, still JSON-LD for the dashboard).
- Bearer forwarding: `BonnerefToken` (set by `RunAgentTurnHandler` from `RunAgentTurn::$accessToken`, reset in
  `finally`) wins; else the main request's `Authorization: Bearer`. Terminate mode needs the message token too, the
  request stack is empty in `kernel.terminate`.
- Deleted: `Agent/Finder`, `Finder`, `Controller/Finder`, `PhotoIntake`, `RegistrationDocument` (+ controllers),
  `AgentTurnHistoryController`, `FinderSelection`, `TurnImageBatch`, `HideJournalNoiseCommand`,
  `ImportLegacyCacheCommand`, `BackfillJournalVehiclesCommand`, the `finder` agent + prompt, `PartsApi`, `Siv`,
  `Vehicle`, `Catalog/*` except `BrandTier`/`ServiceKind`/`OpenTaskPartsBackfiller`, `KnownCar` (`/cars/{id}/identity`
  has no mpa consumer), `MissingApiKeyException`, the `public_selection` firewall, `PARTSAPI_*` / `CATALOG_SOURCE` /
  `TECDOC_CATALOG_PATH` / `API_PLAQUE_TOKEN`, `docs/PARTSAPI.md`.
- CORS: `CORS_ALLOW_ORIGIN` (nelmio, regex) was already env-driven and allows `Authorization`; `CorsTest` pins it.
  Prod must set it to include the bonneref.fr origin.
- Left for later (not in the order above): the `/bonneref` + `/finder` Caddy routes and the dev `bonneref` compose
  service in `api/frankenphp/Caddyfile` / `api/compose.override.yaml`; finder-shaped fields still on the
  `AgentTurn` journal entity (`TurnSurface::Finder`, `search`, `vehicle`, `partsApiCalls*`) because legacy rows carry
  them; `docs/tecdoc-sqlite.md` and the `tecdoc` compose overlay.

## Done (bonneref side, `bonneref/api`, 2026-10-30, step 2)

Carved from `git archive HEAD api`, uncommitted. Verified: `lint:container` OK, 38 test files plus the untouched ones
green one file at a time (broader regression left to CI), `docker build --target frankenphp_prod` builds,
`GET /makes` and `/cars/19107/part-categories` answer 401 without a token, `/agent/selections/{id}` answers 404
(anonymous firewall). `TecdocCatalog` against the bind-mounted `catalog-dev.sqlite` returns 509 PC makes and 523
categories for kType 19107. Front: `npm test` 525 passed, `npm run build` passes.

Deviations from the plan above:

- `ServiceKind` is copied into bonneref (kept in mpa too): `get_service_parts` needs its service to category terms.
  `OpenTaskPartsBackfiller` is dropped, the tool writes nothing.
- `PartsCatalog` stays as an interface aliased to `TecdocCatalog`; its `upstreamCalls*` / `cached*` members are partsapi
  leftovers, not simplified. Names `fromPartsApi`, `partsApiCalls*` (journal entity and `/agent/turns` contract) kept
  for the unchanged HTTP contract and legacy rows. `PARTSAPI_LANG` became `CATALOG_LANG`.
- `TurnSurface` keeps one case (`Finder`), because the journal persists and filters on it. `RunAgentTurn`,
  `/agent/turn` and the MCP toolbox lost `selectedId`; `McpToolbox` now requires its allowlist. The MCP server id is
  `mcp.server.catalog`. `Mcp/State/DomainMiss*` were kept (without them an unknown plate becomes a JSON-RPC error).
- Postgres is optional: default `AGENT_TURN_DISPATCH=terminate` with in-memory DSNs and `SKIP_DATABASE_WAIT=1`; the
  worker and Postgres sit behind the compose profile `messenger`. DAMA and Flysystem/S3 and the generic (Qwen)
  platform were removed from composer.
- Compose project is named `bonneref-api` (the directory is also called `api`). Ports: HTTPS 8444, HTTP 8081,
  MongoDB 27018, Postgres 5433 (profile only). Dev catalog via `TECDOC_CATALOG_HOST_PATH`.
- Test bootstrap now builds the TecDoc fixture subset into `var/test-tecdoc.sqlite` and forces the path (a real
  `TECDOC_CATALOG_PATH` in the container would otherwise make the suite read the licensed catalogue).
- Front: new `VITE_GARAGE_API_BASE` (defaults to `VITE_API_BASE`, both default to same-origin); `src/api-base.ts`.
- Open: `get_service_parts`/compare-brands texts still say "garage" (product wording); `show_parts` agent summary lists
  4 clusters where `GET /narrowed` returns 2 for kType 19107 / category 100259, uninvestigated.
