# bonneref API

The Symfony 8 / API Platform backend of the bonneref parts finder (`../`, the React front). Carved out of
`api/` (mon-parc-auto, "mpa") on 2026-10-30, see `../../docs/bonneref-extraction.md`.

It serves a read-only TecDoc catalogue, the plate / VIN lookups, and the finder agent. It owns no garage
data: vehicles, maintenance and documents stay in mpa.

## What it serves

| surface | routes |
| --- | --- |
| catalogue | `GET /makes`, `/makes/{id}/models`, `/makes/{m}/models/{mo}/cars`, `/cars/{id}/part-categories`, `.../articles`, `.../narrowed`, `/cars/{id}/identity` |
| vehicle lookup | `GET /vehicles/{plate}` (SIV), `GET /vins/{vin}` |
| finder agent | `POST /agent/turn`, `POST /agent/turn/cancel`, `GET /agent/turns`, `GET /agent/turns/{id}`, `POST /agent/turns/{id}/hide`, `GET /agent/selections/{id}` (public) |
| intake | `POST /photo-intake`, `POST /registration-documents/extract` |
| MCP | `/mcp`: `lookup_plate`, `decode_vin`, `list_makes`, `list_models`, `list_cars`, `list_part_categories`, `list_articles`, `narrow_parts`, `decode_build_code`, `compare_brands`, `get_service_parts` (read-only) |
| Mercure | the hub is hosted by the same FrankenPHP (`/.well-known/mercure`); a finder turn publishes its frames to `…/agent/sessions/<id>` |

A finder turn takes no prompt text besides the instruction (500 characters at most, ≤ 4 images of ≤ 4 MB):
the vehicle names, the result on screen, the pending question and the photo reading are rebuilt server-side
from `vehicle.carId`, `onScreen`, `previousTurnId` (the `turnId` the 202 returned) and `intakeId` (returned by
`/photo-intake`). `web_search` only runs for a reference the user gave, or after a catalogue miss with the
vehicle in the query, and `show_web_part` only draws a reference a cited source of that turn carries.

Everything except `/agent/selections/{id}` and the OAuth metadata needs a Casdoor bearer
(`CASDOOR_ISSUER`, issuer + signature only, no audience check). The tenant is the token's `email` claim.

## Running it

```bash
cd bonneref/api
docker compose up -d --build       # php (FrankenPHP + Mercure hub) + mongodb, no Postgres
docker compose exec php composer install   # first run only; the entrypoint installs when vendor/ is empty
```

Defaults: HTTPS on host port **8444**, HTTP on **8081** (mpa keeps 443/80), MongoDB published on **27018**,
database `bonneref`. Override with `HTTPS_PORT`, `HTTP_PORT`, `HTTP3_PORT`, `MONGODB_PORT`, `MONGODB_DB`.
The compose project is named `bonneref-api` so it never shares containers or volumes with mpa's `api`.

The dev stack bind-mounts the derived TecDoc SQLite read-only at `/srv/tecdoc/catalog.sqlite`; the host
path is `TECDOC_CATALOG_HOST_PATH` (default `~/projects/mon-parc-auto.fr/data/catalog-dev.sqlite`). See
`../../docs/tecdoc-sqlite.md`.

The worker only matters in `messenger` dispatch mode: `docker compose --profile messenger up -d` adds it and
Postgres, with `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=1` and
`MESSENGER_FAILED_TRANSPORT_DSN=doctrine://default?queue_name=failed`.

Front, from `../`: `VITE_API_BASE=https://localhost:8444 npm run dev`.

## Environment

| variable | role |
| --- | --- |
| `CASDOOR_ISSUER` | OIDC issuer the bearer is checked against (`https://auth.bonneref.fr`) |
| `MCP_ALLOWED_HOSTS` | hosts `/mcp` answers on (the SDK's DNS-rebinding guard 403s any other with "Invalid Host header") |
| `CORS_ALLOW_ORIGIN` | regex of the front origins |
| `API_PLAQUE_TOKEN` | apiplaqueimmatriculation.com, the paid SIV plate lookup |
| `TECDOC_CATALOG_PATH`, `CATALOG_LANG` | the derived TecDoc SQLite and the language id of its names (6 = French) |
| `ANTHROPIC_API_KEY` | finder agent (Haiku 5.5), photo intake and carte grise reader (Sonnet 5.5), Anthropic web search |
| `WEB_SEARCH_BACKEND`, `WEB_SEARCH_MODEL`, `GEMINI_API_KEY`, `GEMINI_WEB_SEARCH_MODEL` | the out-of-band `web_search` side call |
| `WEB_SEARCH_ALLOWED_DOMAINS` | comma-separated `allowed_domains` for the Anthropic web search (empty: the whole web; Gemini ignores it) |
| `AGENT_TURN_RATE_LIMIT`, `PLATE_LOOKUP_RATE_LIMIT`, `INTAKE_RATE_LIMIT` | per-owner quotas per sliding hour (defaults 30, 60 and 30): finder turns, SIV lookups that miss the store (a stored plate is free), and vision intakes (`/photo-intake`, `/registration-documents/extract`). Over the limit: 429 + `Retry-After`, or a `rate_limited` result from the MCP `lookup_plate` tool |
| `AGENT_DAILY_BUDGET_USD`, `AGENT_PRICES` | per-owner finder spend cap over a sliding 24 hours (default 1.00), summed from the journal's per-turn cost estimate (`AGENT_PRICES`, USD per MTok per model). Over it: 429 + `Retry-After` |
| `LOCK_DSN` | lock store serialising the rate-limiter state (`flock`, one container) |
| `AGENT_TURN_DISPATCH` | `terminate` (default: the turn runs in `kernel.terminate`, capped by `AGENT_TURN_TIME_LIMIT`) or `messenger` (worker) |
| `MESSENGER_TRANSPORT_DSN`, `MESSENGER_FAILED_TRANSPORT_DSN` | `in-memory://` by default |
| `SKIP_DATABASE_WAIT` | `1` (compose default): the entrypoint does not wait for Postgres |
| `MONGODB_URL`, `MONGODB_DB` | domain store: `finder_selections`, `turn_images`, `intake_readings`, `agent_turns`, `siv_lookups`, `catalog_vehicles` |
| `MERCURE_*`, `CADDY_MERCURE_JWT_SECRET`, `MERCURE_JWT_ISSUER` | hub publish URL, public URL and JWT |
| `DEFAULT_URI` | public origin; the finder session topic is `DEFAULT_URI/agent/sessions/<id>` and must equal the front's `VITE_API_BASE` |

## Tests and static analysis

```bash
docker compose exec php composer cs:check   # php-cs-fixer dry run (composer cs to fix)
docker compose exec php composer phpstan    # level 6, no baseline
```

```bash
docker compose exec php php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Catalog/TecdocCatalogTest.php
```

Always a path, one run at a time (the suite shares one Mongo database). The suite never touches the dev
data: `tests/bootstrap.php` forces a `_test` database name and `AppTestCase` refuses to drop anything else.
The catalogue under test is built from `tests/Fixtures/tecdoc` into `var/test-tecdoc.sqlite` at bootstrap.

After changing code, clear the cache **inside** the container (`/app/var` is an anonymous volume):
`docker compose exec php rm -rf var/cache && docker compose restart php`.

## How mpa consumes it

mpa calls these routes through a `BonnerefClient` (Symfony HttpClient, base URI from env) and forwards the
caller's Casdoor bearer. That works because `CasdoorTokenHandler` drops the `aud` check, so a token minted for
any client of the issuer validates here too. mpa's MCP tools (`list_makes`, `list_part_categories`,
`list_articles`, `narrow_parts`, `lookup_plate`, `decode_vin`, `get_service_parts`) map one-to-one onto the
endpoints above; the table is in `../../docs/bonneref-extraction.md`.

The front reaches mpa's garage cross-origin with the same token (`VITE_GARAGE_API_BASE`), so mpa must allow the
front's origin on `/garage/*`.
