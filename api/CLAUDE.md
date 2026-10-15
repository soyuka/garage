# CLAUDE.md — `api/`

Guidance for Claude Code inside the API surface. Repo-wide rules live in `../CLAUDE.md`.

## Stack

API Platform 5.0 on Symfony / PHP 8.4+, FrankenPHP 1.12 (custom build). **MCP-first**: every capability is an
`ApiResource` DTO carrying both HTTP operations and `mcp:` tools, backed by a thin
`State/` provider (reads) or processor (tool calls) over a domain service. Adding a capability means
adding a DTO + processor, not a controller — plain `#[Route]` controllers exist only where API
Platform cannot express the thing (bill up/download).

Persistence is split on purpose:

- **MongoDB (Doctrine ODM)** — the domain: vehicles, facts, maintenance records, planned tasks, users.
- **Postgres** — DBAL HTTP caches (`cache.siv`, `cache.partsapi`) and Casdoor's own store. Never the
  domain model.
- **MinIO / S3** — maintenance bill binaries; metadata stays on the Mongo record.
- **Mercure** — render frames to the dashboard.

The FrankenPHP binary is **rebuilt** (`Dockerfile` builder stage): stock modules with the Mercure
hub pinned at 1.1.2. The builder sets `GOTOOLCHAIN=auto` because Mercure 1.x needs a newer Go than
the builder image ships; rebuild with `docker compose build php` after changing a module version.

## Commands

```bash
docker compose exec php vendor/bin/phpunit
docker compose exec php vendor/bin/phpunit tests/Functional/McpOpenVehicleTest.php
docker compose exec php vendor/bin/phpunit --filter testOpensByQuery
docker compose exec php php -d memory_limit=1G vendor/bin/phpunit    # ALWAYS — see below
```

**No php-cs-fixer and no phpstan in this repo** — the global pre-push gate does not apply here.
`vendor/bin/phpunit` is the whole toolchain.

**Always raise the memory limit**, not just for the full suite (corrected 2026-09-11): the container's
`memory_limit` is 128M and building the MCP registry alone costs ~120 MB, so even a single functional
file dies with `Fatal error: Premature end of PHP process` on a stack ending in `apcu_fetch` — which
reads as a cache bug and is really OOM. `tests/Functional/SpeechTest.php` alone reproduces it, and is
green at 1G. Clearing `var/cache` inside the containers does not help; only the limit does.

After touching code, the worker still holds the compiled container and the MCP tool schemas in
memory. Clear the cache **inside** both containers — `compose.override.yaml:14,49` mount `/app/var`
as an anonymous volume, so a host-side `rm -rf var/cache` touches a different directory and silently
does nothing (verified 2026-08-15):

```bash
docker compose exec php rm -rf var/cache && docker compose exec worker rm -rf var/cache
docker compose restart php worker
```

**Run ONE phpunit at a time.** The suite shares a single test database, so two concurrent runs (or a
`rm -rf var/cache` landing mid-run) produce phantom errors that differ on every pass — 68, then 5, then
2, from unchanged code. A surprising failure count is far more likely to be a second run than a
regression: re-run alone before believing it.

`cache:clear` is a no-op for API Platform resource metadata — new `ApiResource` properties get
silently dropped from JSON-LD until `var/cache` is physically removed. The same staleness hits
generated **input** schemas through `CachedPropertyNameCollectionFactory`, which keys on options: a
property can be live for `create($cls)` and missing from `create($cls, ['schema_type' => 'input', …])`,
so a new tool parameter never reaches the advertised MCP schema even though the code defines it.

## Layout

| dir | role |
| --- | --- |
| `src/ApiResource/` | DTOs = the public surface. `Dashboard.php` holds the pilot render intents. |
| `src/State/` | one provider/processor per operation, thin; logic belongs in the domain services |
| `src/Garage/` | vehicle resolution + write outcomes (`VehicleWriteResolver` is the dedup brain) |
| `src/Maintenance/` | forecast, health, recorder, schedule planner, facts projector |
| `src/Agent/` | in-app agent: toolbox, turn context, journal, `Ui/DashboardChannel.php` |
| `src/Mcp/` | MCP toolbox filtering, prompt loaders, MCP-Apps UI resources |
| `src/Siv/`, `src/PartsApi/`, `src/Motul/`, `src/Catalog/` | upstreams and the catalogue domain over them |

## Things that have bitten before

- **Tests once dropped the live database.** Compose's `MONGODB_DB=app` outranked `.env.test`.
  `tests/bootstrap.php` + `AppTestCase` guard it now — do not weaken them.
- **MCP host allowlist.** The SDK's DNS-rebinding middleware is localhost-only and the bundle exposes
  no hook, so on a public hostname authenticated `/mcp` calls 403 "Invalid Host header." while
  unauthenticated ones still 401 — reads exactly like an OAuth failure. mcp-bundle 0.14 made this a
  config option, so the hand-written controller is gone: set `http.allowed_hosts` in
  `config/packages/mcp.yaml` (it reads `MCP_ALLOWED_HOSTS`).
- **New nullable ODM fields 500 on legacy documents** when constructor-promoted: hydration uses
  `newInstanceWithoutConstructor`, which skips promoted defaults. Declare a class-level default.
- **Clearing a value** goes through `$set: null`, never `$unset` — `$unset` breaks hydration.
- **A maintenance record is one visit** carrying `items[]{type, parts}`, not one row per service.
  Logging a service auto-closes its planned task; do not also dismiss it or usage double-counts.
- **Agent turns run async** on the `worker` container (CLI SAPI, unbounded); the in-request version
  died at the 180 s limit.
- Tool/LLM JSON schemas are generated via API Platform's `SchemaFactory`, never Symfony AI's lossy
  `TypeInfoDescriber`.
- **One unnamed Mercure hub per *configuration*.** Mercure 1.0 refuses a second one anywhere, and
  each `mercure` directive builds its own. Addresses that differ in scheme land in different Caddy
  servers, so a hub on the `{$SERVER_NAME}` site is built once per address: the stage profile
  (`https://localhost, http://garage.soyuka.me`) would not boot. The hub is declared once on the
  internal `http://php:80` site, which serves only `/.well-known/mercure*`; the public site proxies
  the hub path to it. FrankenPHP hot reload must stay off, as it wants a hub in its own server
  (`FRANKENPHP_SITE_CONFIG` is empty in `compose.override.yaml`).
- **The owner is the email claim** (55bf873), so a seeded `setOwner()` takes an address. The test
  fallback in `config/services.yaml` and `AppTestCase::TEST_OWNER` must stay equal.

## Docs

`docs/PLAN.md` (architecture + upstream joining facts), `docs/AUTH.md` (Casdoor first-boot setup),
`docs/PARTSAPI.md` (per-method keys, call budget), `docs/TEST.md`, `docs/AGENT_PROVIDER.md`.
Resolved review notes live in `docs/resolved/`.
