# mon-parc-auto

[![CI](https://github.com/soyuka/mon-parc-auto.fr/actions/workflows/ci.yml/badge.svg)](https://github.com/soyuka/mon-parc-auto.fr/actions/workflows/ci.yml)

A garage and maintenance tracker exposed as an MCP server, with a 3D workshop in the browser (the **Atelier**)
that any MCP client can drive. You ask Claude what is due on your car; Claude calls the garage tools, and the
Atelier reacts live: the car drives in, the task list opens, the part is shown inside the engine bay with its
catalogue reference, and logging the visit closes the planned task. The Atelier has no privileged channel: it
renders the same frames whether Claude Code, Claude Desktop or the app's own Symfony AI agent made the call.
Parts come from **bonneref**, a second Symfony app in this repository that serves a TecDoc parts catalogue as its
own MCP server.

Symfony 7, API Platform 5 (`api-platform/mcp`), `symfony/mcp-bundle` and the PHP MCP SDK, Symfony AI, Mercure,
React / react-three-fiber. Entry for the Symfony AI & MCP hackathon.

## Run it

Requirements: Docker with Compose, ports 8480–8482 free on loopback (or set `DEMO_PORT`, `BONNEREF_PORT`,
`AUTH_PORT` before the first run).

```bash
git clone https://github.com/soyuka/mon-parc-auto.fr.git
cd mon-parc-auto.fr
bin/demo
```

[`compose.demo.yaml`](compose.demo.yaml) brings up everything with nothing hosted: the Atelier, mpa's MCP server
and worker, bonneref's catalogue API and MCP server, MongoDB, Postgres, and a Casdoor with a `demo` user. On the
first run `bin/demo` generates the secrets and the demo password into `.demo/env`, seeds the garage, then prints:

```
  Atelier              http://localhost:8480
  mpa MCP server       http://localhost:8480/mcp
  bonneref MCP server  http://localhost:8481/mcp
  Casdoor (issuer)     http://localhost:8482
  Login                demo / <generated>
  claude mcp add --transport http garage http://localhost:8480/mcp
  claude mcp add --transport http bonneref http://localhost:8481/mcp
```

Run `/mcp` in Claude Code to log in: the client registers itself (dynamic client registration) and opens the login
page, no client id to copy. Open the Atelier in a browser logged in as the same `demo` user, then paste these in
order (tools in brackets are what the client is expected to call):

1. `What's due on the Mazda?`
   (`open_vehicle` drives the car in and returns its forecast and open tasks; `focus_view` raises the task list)
2. `Which oil filter should I order for the Mazda? Narrow it down to the physically different parts and compare brands.`
   (`get_service_parts`, `narrow_parts`, `compare_brands`, served by bonneref)
3. `When did I last change the oil on the Mazda? Show me the logbook.`
   (`get_maintenance_history`, `focus_view` on the `carnet` panel filtered to `oil_change`, a `toast`)
4. `Show me where the fuel filter sits on the Mazda.`
   (`reveal_assembly` on the `bay` region: the body turns to wireframe, the fuel circuit is drawn under it)
5. `I've just replaced the fuel filter on the Mazda, at 252 524 km. Log it.`
   (`log_maintenance`: the visit closes the open fuel filter task and the forecast moves)

The garage also holds a BMW F 800 GS Adventure whose parts are in the catalogue slice
(`Which oil filter fits the BMW?`). `bin/demo reset` puts everything back to replay the run.
Catalogue names are English by default. MCP answers follow the request's `Accept-Language` (for French:
`claude mcp add … --header "Accept-Language: fr"`); the Atelier has its own EN/FR switch.

- **Images.** `bin/demo` pulls `ghcr.io/soyuka/mon-parc-auto-{api,atelier,bonneref}:main` (published by CI after a
  green `main`) and builds from the checkout when a pull fails, printing which path it took per image. Measured:
  about 24–45 s to a running stack with the images, 2 min 50 s on a cold local build. `bin/demo --build` forces the
  build; a non-default `AUTH_PORT` rebuilds the Atelier, which compiles the issuer URL in.
- **Keys.** None needed: your MCP client does the reasoning. `ANTHROPIC_API_KEY` in the environment turns on the
  in-app agent and the bonneref finder.
- **Data.** The garage is [`api/fixtures/demo/`](api/fixtures/demo/) (a Mazda6, a Renault Espace IV with its
  workshop manuals, a TORO ride-on mower, the BMW), exported from the owner's garage with plates and VINs replaced
  ([how](docs/deploy-garage.md#offline-fixture)). The catalogue is [`data/catalog-demo.sqlite`](data/catalog-demo.sqlite),
  a 46 MB slice of a TecDoc 2020 extract: parts and narrowing answer for the Mazda6, the Espace IV and the
  F 800 GS only ([what it keeps](docs/tecdoc-sqlite.md#demo-slice-datacatalog-demosqlite-2026-11-13)).
- `bin/demo down` stops it (data kept), `bin/demo reset` reloads the garage, `bin/demo info` prints the URLs again.

The development stack (`cd api && docker compose up -d`, hosted auth, Vite dev servers) is described in
[`CLAUDE.md`](CLAUDE.md) and [`api/docs/AUTH.md`](api/docs/AUTH.md).

## Where to read the code

| file | what it carries |
| --- | --- |
| [`api/src/ApiResource/Dashboard.php`](api/src/ApiResource/Dashboard.php) | the render intents (`open_vehicle`, `focus_view`, `reveal_assembly`, `propose_actions`…) declared as `McpTool` operations |
| [`api/src/Agent/Ui/DashboardChannel.php`](api/src/Agent/Ui/DashboardChannel.php) | picks the Mercure topic: the agent turn's session, or the owner's pilot topic (an HMAC of the owner identity) |
| [`dashboard/src/agent.ts`](dashboard/src/agent.ts) | the Atelier's subscriptions, one EventSource per driver, resolved through `GET /agent/link` |
| [`api/src/Security/CasdoorTokenHandler.php`](api/src/Security/CasdoorTokenHandler.php) | a Symfony `AccessTokenHandlerInterface` validating the OAuth bearer on `/mcp` |
| [`Maintenance.php`](api/src/ApiResource/Maintenance.php) → [`LogMaintenanceInput`](api/src/Dto/LogMaintenanceInput.php) → [`LogMaintenanceProcessor`](api/src/State/LogMaintenanceProcessor.php) | the pattern every tool follows: an `McpTool` operation, a validated input DTO, a state processor |
| [`api/src/Mcp/Agent/McpToolbox.php`](api/src/Mcp/Agent/McpToolbox.php) | Symfony AI `ToolboxInterface` over the in-process `Mcp\Server`: the agent calls the MCP tools without redeclaring them |
| [`bonneref/api/src/Catalog/Narrowing/ArticleNarrower.php`](bonneref/api/src/Catalog/Narrowing/ArticleNarrower.php) | `narrow_parts`: turns hundreds of fitting articles into the few physically distinct parts |

Skip: `stage/` (the live-talk harness), `theming/` (design references), `docs/archive/`. The four surfaces are
`api/` (mpa, Symfony), `dashboard/` (the Atelier, React), `bonneref/api/` (catalogue, Symfony) and `bonneref/`
(its React front).

## What it unlocked

**One render pipeline, two drivers.** An MCP tool call renders nothing in the host, so the tools in `Dashboard.php`
publish a Mercure frame through processors in [`api/src/State/Dashboard/`](api/src/State/Dashboard/). The in-app
agent has the same intents as `#[AsTool]` methods ([`RenderIntentTools.php`](api/src/Agent/Ui/RenderIntentTools.php))
on the same publisher. Either way the Atelier receives the same frame; for an external client the topic is the
owner's HMAC digest, which is the access control on an anonymous-subscribe hub.

**A remote MCP server with no client id to hand out.**
[`ProtectedResourceMetadataController`](api/src/Controller/Mcp/ProtectedResourceMetadataController.php) serves RFC 9728
metadata, [`McpProtectedResourceChallengeListener`](api/src/EventListener/McpProtectedResourceChallengeListener.php)
adds the `WWW-Authenticate` challenge, and Casdoor accepts anonymous RFC 7591 registration, so stock clients
register and log in by themselves ([`api/docs/AUTH.md`](api/docs/AUTH.md)). Every tool is scoped to the caller by
[`OwnerContext`](api/src/Security/OwnerContext.php).

**Two MCP servers, and an agent that is a client of one of them.** bonneref exposes the catalogue as a read-only MCP
server ([`bonneref/api/src/ApiResource/`](bonneref/api/src/ApiResource/)); its finder agent drives that same server
in process through an allowlisted toolbox (`app.finder.mcp_toolbox` on `mcp.server.catalog`,
[`services.yaml`](bonneref/api/config/services.yaml)). mpa reaches bonneref over HTTP with the caller's bearer
([`BonnerefClient.php`](api/src/Bonneref/BonnerefClient.php)).

**Schemas written once.** Tool input schemas come from the input DTOs through API Platform's JSON Schema factory
(`api-platform/mcp`); for those tools the in-app agent reuses the schema the server advertises instead of a second
description.

**Agent turns off the request, and a loop that stops.** `POST /agent/turn` dispatches
[`RunAgentTurn`](api/src/Message/RunAgentTurn.php) on Messenger; the worker runs it under the CLI SAPI with no request
time limit ([`DeferredTurnDispatcher`](api/src/Agent/Dispatch/DeferredTurnDispatcher.php) runs it in
`kernel.terminate` when there is no worker). Listeners on Symfony AI's `ToolCallsExecuted` end the turn on an
explicit [`end_turn`](api/src/Agent/Ui/EndTurnTool.php) or on a repeated failing call
([`RepeatedToolFailureGuard`](api/src/Agent/Ui/RepeatedToolFailureGuard.php)), `max_tool_calls: 25` in
[`ai.yaml`](api/config/packages/ai.yaml) is the backstop, and every call lands in a turn journal with its token cost
([`api/src/Agent/Journal/`](api/src/Agent/Journal/)).

## Fixed upstream on the way

- [api-platform/core#8845](https://github.com/api-platform/core/pull/8845) (merged, in 5.0.7): nullable object
  collections lost `null` in the generated JSON Schema, so MCP clients had tool arguments rejected.
- [symfony/ai#2220](https://github.com/symfony/ai/pull/2220) (merged): the generic platform bridge crashed when a
  provider (Qwen) streamed an empty-string tool-call id on continuation deltas.
- [symfony/ai#2196](https://github.com/symfony/ai/pull/2196) (merged): Anthropic server-side tools through a
  `server_tools` option, used by the agent's web search
  ([`AnthropicWebSearchClient.php`](api/src/Agent/WebSearch/AnthropicWebSearchClient.php)).
- [api-platform/core#8332](https://github.com/api-platform/core/issues/8332) (issue, closed): a property added to an
  existing resource stayed missing from the output until `var/cache` was deleted.

## Quality

CI ([`ci.yml`](.github/workflows/ci.yml)) runs, for both Symfony apps, php-cs-fixer (`@Symfony`, plus `@Symfony:risky`
on mpa), Mago analyzer and linter with no baseline, and PHPUnit; for both fronts, vitest and a production build.

## License

[MIT](LICENSE), for the code. The TecDoc catalogue slice, the vehicle documents in the demo garage and some
third-party 3D assets are included for the demo only and keep their own terms
([`docs/public-audit.md`](docs/public-audit.md)).
