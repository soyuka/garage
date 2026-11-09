# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## « démarre projet »

Means: bring the full stack up, in stage profile, and confirm it answers. Run, in order:

```bash
cd api && docker compose --env-file .env.stage \
  -f compose.yaml -f compose.override.yaml -f compose.stage.yaml up -d
```

Then the tunnel (only if `curl -s -o /dev/null -w '%{http_code}' https://garage.soyuka.me/mcp`
returns `000` or `530` — `401` means it is already up and healthy):

```bash
cloudflared tunnel --config api/docker/cloudflared/config.yml run >> stage/var/cloudflared.log 2>&1 &
```

Then report: `https://localhost` (Atelier, self-signed cert), `https://garage.soyuka.me/mcp` (public
MCP). Auth is the hosted Casdoor at `https://auth.bonneref.fr` (no local container): Claude Code does the
OAuth itself on first MCP connect (protected-resource metadata → DCR → browser login).

`stage/bin/atelier` does all three **and then launches a fresh `claude` inside `stage/`** with the
Atelier output style — that is the presenter's entry point, run from a terminal, not from inside an
existing session.

Dev-only (no tunnel, no stage overlay): `cd api && docker compose up -d`.

The three compose files layer, never replace: dropping `compose.override.yaml` from a stage launch
yields a stack that boots but serves localhost-only `MCP_ALLOWED_HOSTS` / `CASDOOR_ISSUER`, which
reads exactly like an OAuth failure.

## Surfaces

Five, and a task rarely touches only one:

| surface | what it is | read first |
| --- | --- | --- |
| `api/` | Symfony 7 / API Platform. Garage domain and the Motul upstream responses in MongoDB (Doctrine ODM); parts catalog + plate lookup come from bonneref over HTTP (`BonnerefClient`, bearer forwarded); Postgres backs the Messenger queue only; bills and documents live on Flysystem (local volume, S3 in prod); Mercure pushes render frames. Also hosts the MCP server. | `docs/mcp.md`, `api/docs/PLAN.md`, `FUTURE.md` |
| `dashboard/` | The Atelier — React + react-three-fiber 3D workshop. Served same-origin behind Caddy (Vite runs *in* the compose network as the `dashboard` service). | `dashboard/CONTRACT.md`, `PRODUCT.md` |
| `stage/` | The pilot harness — **current workstream**. Its own `CLAUDE.md` is the operator protocol (not repo guidance), `.mcp.json` points the session at the public MCP server, `bin/atelier` is the launcher. | `stage/CLAUDE.md`, `docs/pilot.md`, `CONFERENCE.md` |
| `bonneref/` | The parts finder: React front + its own Symfony app in `bonneref/api` (TecDoc catalog, SIV plate lookup, finder agent, catalog MCP server). Deployed on bonneref.fr; mpa consumes it over HTTP only. | `bonneref/api/README.md`, `docs/bonneref-extraction.md` |
| `admin/` | Vite SPA, largely dormant. | — |

Further entry docs by topic: `api/` maintenance domain → `FUTURE.md` § GMAO core (the anti-rebuild
section: it lists what already ships);
conference → `CONFERENCE.md` § "the one rule".

### Splitting a multi-surface ask

Split **by surface, not by finding.** One agent per surface, sequential within a surface, parallel
across surfaces — `api/` and `dashboard/` genuinely parallelise (different languages, different test
runners). Most dashboard findings collide: three separate bugs all landed in
`dashboard/src/scene/SceneView.tsx` + `scene.css` on the same day, so fanning them out produces three
conflicting edits to one file, and worktree isolation only moves the collision to merge time.

A finding that crosses surfaces (a new `UIAction` = `api/` publisher + `dashboard/` reducer) is **one**
item, owned by whoever holds the contract (`dashboard/src/types.ts`) — never two.

Findings that are not the task at hand go into `FUTURE.md`, not into chat where they evaporate.
Every dispatched worker inherits the conventions at the bottom of this file.

## Commands

```bash
# api — phpunit, php-cs-fixer (@Symfony + @Symfony:risky), phpstan level 6 (no baseline)
cd api && docker compose exec php vendor/bin/phpunit
docker compose exec php vendor/bin/phpunit tests/Functional/McpOpenVehicleTest.php
docker compose exec php vendor/bin/phpunit --filter testOpensByQuery
docker compose exec php php -d memory_limit=1G vendor/bin/phpunit   # full suite needs this
docker compose exec php composer cs          # fix; `composer cs:check` is the dry-run gate
docker compose exec php composer phpstan     # warms the test debug container, then analyses

# dashboard
cd dashboard && npm test            # vitest, run once
npm test -- src/scene/worklist.test.ts
npm run build                       # tsc + vite build
```

Test DB safety: `MONGODB_DB=app` from compose once overrode `.env.test` and the suite dropped the
live database. `tests/bootstrap.php` + `AppTestCase` now guard it — do not weaken those.

After changing code the worker keeps serving from memory, so MCP clients get the old tool schema:

```bash
docker compose exec php rm -rf var/cache && docker compose exec worker rm -rf var/cache
docker compose restart php worker
```

**Clear it INSIDE the containers.** `compose.override.yaml:14,49` mount `/app/var` as an anonymous
volume on both `php` and `worker`, so the host `api/var/` is a different directory — `rm -rf
api/var/cache` from the host is a no-op that reads as one (verified 2026-08-15). `cache:clear` alone
is not enough for API Platform metadata either: a stale `CachedPropertyNameCollectionFactory` entry
is keyed by options, so a new property can be visible to `create($cls)` and absent from the generated
input schema — the symptom is a new tool parameter that the code clearly defines never reaching the
advertised MCP schema.

## Architecture, the parts that need several files to see

**One render pipeline, two drivers.** Render intents are declared once as `#[AsTool]` methods
(`api/src/ApiResource/Dashboard.php`) and reach the browser as Mercure frames. `src/Agent/Ui/DashboardChannel.php`
chooses the topic: the *turn session* topic when an in-app agent turn asked, otherwise the owner's
**pilot topic** `…/agent/pilots/<hmac_sha256(owner_sub, APP_SECRET)>`. The digest is the access
control — the hub accepts anonymous subscribers. The dashboard learns its pilot topic from
`GET /agent/link` and subscribes to both (`dashboard/src/agent.ts`). Pilot tools sit on
`McpToolbox::EXCLUDED` so the in-app agent never double-sees them.

**Agent turns are async.** `/agent/turn` enqueues; the `worker` container consumes under CLI SAPI so
the tool loop is unbounded (the in-request version died at 180 s). The pilot path skips this entirely
— that is its whole point. `AGENT_TURN_DISPATCH=terminate` (default `messenger`) instead runs the turn
in `kernel.terminate` of the same request after the 202 is sent, capped by `AGENT_TURN_TIME_LIMIT`
(default 120 s) — no worker, for bonneref deployed alone; `MESSENGER_FAILED_TRANSPORT_DSN` moves the
dead letters off Postgres.

**Maintenance domain.** A `MaintenanceRecord` is a *visit* carrying `items[]{type, parts}` and a visit
cost — not one row per service. Forecasting walks `items[].type` against `ServiceInterval` recurring
rules plus usage, composing a health figure; a per-vehicle schedule overrides the generic interval.
Logging a service auto-closes the matching planned task — never also call `dismiss_maintenance`.

**Auth.** Casdoor, hosted at `https://auth.bonneref.fr` (not in this stack), is the OIDC server, chosen
because it supports anonymous RFC 7591 DCR (stock Goose and Claude Desktop self-register). Owner identity
is the Casdoor `email` claim (not `sub`, which Casdoor re-mints), carried through MCP and the
agent by `OwnerContext`; every garage read/write is owner-scoped.

**Contracts are single-owner.** A change that crosses `api/` and `dashboard/` (a new `UIAction`, a new
frame) is *one* task owned by whoever holds `dashboard/src/types.ts` — not two parallel edits.

## Conventions specific to this repo

- Test-first, always: failing test shown before production code.
- Dashboard tests on the host need a real `dashboard/node_modules` — the host directory is a
  root-owned empty docker anonymous volume until you `rmdir` it and `npm install`.
- Bloom multisampling in the scene MUST stay `0`.
- Many rapid edits can wedge Vite into a ~1 s full-reload loop; restart the `dashboard` container.
