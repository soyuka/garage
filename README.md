# mon-parc-auto

[![CI](https://github.com/soyuka/mon-parc-auto.fr/actions/workflows/ci.yml/badge.svg)](https://github.com/soyuka/mon-parc-auto.fr/actions/workflows/ci.yml)

A garage and maintenance tracker you run by talking to an AI. Your vehicles, their service history, planned work
and a usage-driven forecast of what is due are exposed as an MCP server, so Claude (or any MCP client) reads and
writes your garage with owner-scoped tools. When a client drives it, the **Atelier**, a 3D workshop in the
browser, reacts live: the car drives in, the part is revealed with its real catalogue reference, and logging a
visit closes the planned task. Parts come from **bonneref**, a second Symfony app in this repo that serves the parts
catalogue and plate lookup as its own MCP server and runs a parts-finder agent.

Built with Symfony 7, API Platform 5 (`api-platform/mcp`), `symfony/mcp-bundle`, Symfony AI, Mercure and
React / react-three-fiber. This is a pet project for testing MCP with API Platform. Entry for the Symfony AI & MCP hackathon.

## Try it in 2 minutes

MCP endpoint: **`https://garage.bonneref.fr/mcp`** (Streamable HTTP, OAuth). Atelier: **https://garage.bonneref.fr**.

Demo login on `auth.bonneref.fr`: `demo` / `demo-MpiFkZmAPfu2`. The demo garage is shared
between judges and reset nightly.

Add the server:

- **Claude Code**: `claude mcp add --transport http garage https://garage.bonneref.fr/mcp`, then `/mcp` to log in.
- **claude.ai / Claude Desktop**: Settings → Connectors → Add custom connector → URL
  `https://garage.bonneref.fr/mcp`. No client id is needed: the client registers itself (dynamic client
  registration) and opens the login page.

Open https://garage.bonneref.fr in a browser logged in with the same account to watch the Atelier react, then
paste these in order. The tools in brackets are what the client is expected to call.

1. `What's due on the Mazda?`
   (`open_vehicle` drives the car into the Atelier and returns its forecast and open tasks; `focus_view` raises
   the task list)
2. `Which oil filter should I order for the Mazda? Narrow it down to the physically different parts and compare brands.`
   (`get_service_parts`, `narrow_parts`, `compare_brands`; the catalogue is served by bonneref)
3. `When did I last change the oil on the Mazda? Show me the logbook.`
   (`get_maintenance_history`, then `focus_view` on the `carnet` panel filtered to `oil_change`, and a `toast`
   with the date)
4. `Show me where the fuel filter sits on the Mazda.`
   (`reveal_assembly` on the `bay` region: the body turns to wireframe and the fuel circuit is drawn under it)
5. `I've just replaced the fuel filter on the Mazda, at 252 524 km. Log it.`
   (`log_maintenance`: the visit closes the open fuel filter task and the forecast moves)

Prompt 5 writes to the shared garage. If someone already logged it today, the task is gone until the nightly
reset.

The full tool list is advertised by `tools/list`. Garage tools are declared as `McpTool` operations on API
Platform resources in [`api/src/ApiResource/`](api/src/ApiResource/), for example
[`Garage.php`](api/src/ApiResource/Garage.php), [`Maintenance.php`](api/src/ApiResource/Maintenance.php) and
[`Dashboard.php`](api/src/ApiResource/Dashboard.php) for the render intents.

## What Symfony AI and the MCP SDK unlocked

**One tool declaration, two callers.** Garage tools are `McpTool` operations on API Platform resources
(`api-platform/mcp` on `symfony/mcp-bundle` and the PHP MCP SDK). The in-app Symfony AI agent does not redeclare
them: [`McpToolbox`](api/src/Mcp/Agent/McpToolbox.php) implements Symfony AI's `ToolboxInterface` by calling the
same `Mcp\Server` instance in process, so schemas, validation and dedup results are identical for both. Its
denylist hides the pilot-only tools from the agent.

**The UI is an MCP client's output device.** An MCP tool call renders nothing in the host, so the tools in
[`Dashboard.php`](api/src/ApiResource/Dashboard.php) (`open_vehicle`, `focus_view`, `reveal_assembly`,
`show_document`, `propose_actions`…) publish a Mercure frame instead, through processors in
[`api/src/State/Dashboard/`](api/src/State/Dashboard/). The in-app agent has the same intents as `#[AsTool]`
methods in [`RenderIntentTools.php`](api/src/Agent/Ui/RenderIntentTools.php), on the same publisher.
[`DashboardChannel.php`](api/src/Agent/Ui/DashboardChannel.php) picks the topic: the turn's session for the
in-app agent, or the owner's pilot topic (an HMAC of the owner identity) for an external client. The Atelier
subscribes to both ([`agent.ts`](dashboard/src/agent.ts),
[`Workshop.tsx`](dashboard/src/workshop/Workshop.tsx)). Claude Code or Claude Desktop can therefore drive the
browser with no chat UI of ours in between.

**A remote MCP server with no client id to hand out.**
[`ProtectedResourceMetadataController`](api/src/Controller/Mcp/ProtectedResourceMetadataController.php) serves
RFC 9728 metadata, [`McpProtectedResourceChallengeListener`](api/src/EventListener/McpProtectedResourceChallengeListener.php)
adds the `WWW-Authenticate` challenge, and [`CasdoorTokenHandler`](api/src/Security/CasdoorTokenHandler.php) is a
Symfony `AccessTokenHandlerInterface` validating the bearer. The issuer accepts anonymous RFC 7591 dynamic client
registration, so stock clients register themselves ([`api/docs/AUTH.md`](api/docs/AUTH.md)). Every tool is then
scoped to the caller by [`OwnerContext`](api/src/Security/OwnerContext.php).

**Agent turns off the request.** `POST /agent/turn` dispatches a
[`RunAgentTurn`](api/src/Message/RunAgentTurn.php) Messenger message; the
[handler](api/src/MessageHandler/RunAgentTurnHandler.php) runs the Symfony AI agent in the worker under the CLI
SAPI, with no request time limit. Without a worker,
[`DeferredTurnDispatcher`](api/src/Agent/Dispatch/DeferredTurnDispatcher.php) runs it in `kernel.terminate`
after the 202 is sent.

**A tool loop that stops.** [`CompositeToolbox`](api/src/Agent/Toolbox/CompositeToolbox.php) merges the render
intents and the MCP tools inside the bundle's `FaultTolerantToolbox`, so a malformed call becomes an error tool
result instead of a 500 ([`services.yaml`](api/config/services.yaml)). Listeners on Symfony AI's
`ToolCallsExecuted` event end the turn on an explicit [`end_turn`](api/src/Agent/Ui/EndTurnTool.php) tool
([`TerminalToolListener`](api/src/Agent/Ui/TerminalToolListener.php)) or on the same failing call repeated
([`RepeatedToolFailureGuard`](api/src/Agent/Ui/RepeatedToolFailureGuard.php)); `max_tool_calls: 25` in
[`ai.yaml`](api/config/packages/ai.yaml) is the backstop. The same event feeds the turn journal
([`ToolCallJournalListener`](api/src/Agent/Journal/ToolCallJournalListener.php)), and
[`TurnCostEstimator`](api/src/Agent/Journal/TurnCostEstimator.php) prices each turn's token counts.

**A second server, a second agent.** bonneref is its own Symfony app exposing the parts catalogue as a read-only
MCP server ([`bonneref/api/src/ApiResource/`](bonneref/api/src/ApiResource/)) and running a parts-finder agent
([`bonneref/api/src/Agent/Finder/`](bonneref/api/src/Agent/Finder/)). mpa calls it over HTTP with the caller's
bearer ([`BonnerefClient.php`](api/src/Bonneref/BonnerefClient.php)).

What broke on the way is listed in [What we found broken upstream](#what-we-found-broken-upstream).

## Run it offline

Requirements: Docker with Compose, ports 8480-8482 free on loopback (or set `DEMO_PORT`, `BONNEREF_PORT`,
`AUTH_PORT` before the first run).

```bash
git clone https://github.com/soyuka/mon-parc-auto.fr.git
cd mon-parc-auto.fr
bin/demo
```

One stack ([`compose.demo.yaml`](compose.demo.yaml)), nothing hosted: the Atelier, mpa's MCP server, bonneref's
catalogue API and MCP server on a small TecDoc slice, and a Casdoor with a `demo` user whose garage is already
filled (a Mazda6, a Renault Espace IV with its workshop manuals, a TORO ride-on mower, a BMW F 800 GS Adventure).
`bin/demo` generates the secrets and the demo password into `.demo/env` on the first run, then prints:

- the Atelier, `http://localhost:8480`, and the login;
- the two MCP servers, `http://localhost:8480/mcp` (garage) and `http://localhost:8481/mcp` (catalogue), with
  the `claude mcp add --transport http …` lines. Run `/mcp` in Claude Code to log in: it registers itself.

`bin/demo` pulls the three app images from GHCR (`ghcr.io/soyuka/mon-parc-auto-{api,atelier,bonneref}:main`, built by
CI on every green `main`) and falls back to building them from the checkout when the pull fails, printing which path
it took per image. Those packages are public only once the repository is: until then the pull is refused and the
first run builds locally (a few minutes). `bin/demo --build` forces the local build. A non-default `AUTH_PORT`
also builds the Atelier locally, the issuer URL is compiled into its bundle.

`bin/demo down` stops it (data kept), `bin/demo reset` reloads the demo garage, `bin/demo info` prints the URLs
again. No key is needed: your MCP client does the reasoning. `ANTHROPIC_API_KEY` in the environment turns the
in-app agent on.

The demo garage is [`api/fixtures/demo/`](api/fixtures/demo/), loaded by `app:demo:load`. It is exported from the
owner's live garage by a command, never edited by hand:

```bash
# in api/, against the dev stack; the mapping file holds the real plates/VINs and stays out of the repo
docker compose exec -T php php -d memory_limit=1G bin/console app:demo:export \
  --owner=<owner email> --mapping=php://stdin \
  --vehicle=01KVKMEFM6V36AWETKE7A9QCJ8 --vehicle=01KW7CFJP6Y0ARF5287B7Y9CAY --vehicle=01KVFEQA72XQMWSS3HPBD93ASR \
  --skip-document=01M6NJ7C6D8MJKMJE1F216FGFJ --skip-document=01M3Z4RDF4XETN3TXNH9YZ3P3D \
  < ../../rewrite-mapping.txt
```

It rewrites plates and VINs through the mapping, drops bills, quotes and the owner's email, merges the vehicles
of [`api/fixtures/demo/extras.json`](api/fixtures/demo/extras.json), and refuses to write if a real plate, VIN or
mapped value is left in the JSON or in a document's text, or if the documents exceed 50 MB.

The catalogue is [`data/catalog-demo.sqlite`](data/catalog-demo.sqlite) (46 MB), a slice of the licensed TecDoc
2020 extract cut to the demo garage. Parts, part categories and narrowing answer for its three catalogued
vehicles only: the Mazda6 (kType 19107), the Espace IV (18679) and the F 800 GS Adventure (motorbike 101222); the
TORO has no TecDoc type. `list_makes` and `list_models` show the whole 2020 catalogue, but `list_cars` lists types
only for MAZDA, RENAULT and BMW MOTORCYCLES: any other make stops at the model.
`DEMO_CATALOG_PATH=<path to a full catalog.sqlite> bin/demo` mounts another catalogue instead. The slice is
regenerated on the server holding the full catalogue (source opened read-only, output never overwritten):

```bash
scp bonneref/api/bin/build-demo-catalog <server>:src/bdf2sql/catalog/demo-tool/
ssh <server> 'cd src/bdf2sql/catalog && python3 -I demo-tool/build-demo-catalog "$PWD/catalog.sqlite" \
  "$PWD/catalog-demo.sqlite" --types PC:19107 PC:18679 MC:101222 --languages 1,4,6'
scp <server>:src/bdf2sql/catalog/catalog-demo.sqlite data/catalog-demo.sqlite
```

What it keeps and drops: [`docs/tecdoc-sqlite.md`](docs/tecdoc-sqlite.md) § "Demo slice".

## Run it locally

Requirements: Docker with Compose. Ports 443/80 free, or set `HTTPS_PORT`/`HTTP_PORT`.

```bash
git clone https://github.com/soyuka/mon-parc-auto.fr.git
cd mon-parc-auto.fr/api
docker compose up -d
```

This starts php (FrankenPHP with Caddy and Mercure), the Messenger worker, MongoDB, Postgres and the Vite dev
servers for the Atelier and bonneref.

A fresh clone runs with an empty `api/.env.local`: every value in `api/.env` has a working default for the
compose stack. Paid keys are optional, and a missing key turns its feature off instead of breaking it:

- `ANTHROPIC_API_KEY`: the in-app agent (or the key of whichever platform `api/config/packages/ai.yaml` gives
  it). MCP clients bring their own model and need no key.
- `GEMINI_API_KEY`: the agent's `web_search` tool. Gemini is the web-search backend on purpose.
- `ELEVENLABS_API_KEY`: voice and dictation. ElevenLabs is the only voice backend.

Tools whose key is missing are not listed by `tools/list` (see [`api/src/Capability/`](api/src/Capability/)).

- Atelier: https://localhost (self-signed certificate). MCP: https://localhost/mcp. A local garage starts
  empty: add a car from the Atelier or by asking your MCP client.
- Auth uses the hosted Casdoor at `https://auth.bonneref.fr` (`CASDOOR_ISSUER` in `api/.env`); there is no local
  identity server.
- The catalogue and plate lookup are fetched from bonneref over HTTP: `BONNEREF_API_URL` defaults to
  `https://bonneref.fr`, so parts work without the licensed TecDoc data. Running `bonneref/api` yourself is
  optional and needs that data (see [`bonneref/api/README.md`](bonneref/api/README.md)).
- The MCP path spends nothing on our side: your own client does the reasoning. The in-app agent turn needs an
  `ANTHROPIC_API_KEY` in `api/.env.local`.
- Tests: `docker compose exec php php -d memory_limit=1G vendor/bin/phpunit <file>`;
  dashboard: `cd dashboard && npm install && npm test`.

### Switching the model

The in-app agent's provider is set in [`api/config/packages/ai.yaml`](api/config/packages/ai.yaml): one
platform/model pair is uncommented. Anthropic is the default; Qwen, through the generic OpenAI-compatible
bridge, is the alternative. Walkthrough: [`api/docs/AGENT_PROVIDER.md`](api/docs/AGENT_PROVIDER.md).

<!-- TODO: confirm that a localhost redirect URI is registered for the dashboard app on auth.bonneref.fr,
     otherwise the local Atelier login fails. -->

## What's inside

| feature | code |
| --- | --- |
| MCP over HTTP, tools declared on API Platform resources | [`api/config/packages/mcp.yaml`](api/config/packages/mcp.yaml), [`api/src/ApiResource/`](api/src/ApiResource/) |
| OAuth for MCP: protected-resource metadata (RFC 9728), `WWW-Authenticate` challenge, Casdoor bearer validation; Casdoor chosen because it accepts anonymous dynamic client registration (RFC 7591), so stock clients self-register | [`ProtectedResourceMetadataController.php`](api/src/Controller/Mcp/ProtectedResourceMetadataController.php), [`McpProtectedResourceChallengeListener.php`](api/src/EventListener/McpProtectedResourceChallengeListener.php), [`CasdoorTokenHandler.php`](api/src/Security/CasdoorTokenHandler.php), [`api/docs/AUTH.md`](api/docs/AUTH.md) |
| Owner-scoped garage: every read and write is filtered by the caller's identity (Casdoor `email` claim) | [`OwnerContext.php`](api/src/Security/OwnerContext.php) |
| Maintenance domain: a record is a visit with `items[]`; recurring intervals + usage give a forecast and a health figure; logging a service closes its planned task | [`api/src/Maintenance/`](api/src/Maintenance/), [`api/src/Garage/`](api/src/Garage/) (`VehicleWriteResolver` dedups vehicles across labels) |
| Archiving: a sold or scrapped car leaves `list_vehicles` and `list_due_maintenance` but keeps its history (`update_vehicle` with `archived: true`) | [`Garage.php`](api/src/ApiResource/Garage.php), [`GarageVehicleArchiveTest.php`](api/tests/Functional/GarageVehicleArchiveTest.php) |
| Render intents over Mercure, one pipeline with two drivers: an MCP client (pilot) or the in-app agent call the same `Dashboard` tools; `DashboardChannel` picks the agent-turn topic or the owner's HMAC-derived pilot topic | [`Dashboard.php`](api/src/ApiResource/Dashboard.php), [`RenderIntentTools.php`](api/src/Agent/Ui/RenderIntentTools.php), [`DashboardChannel.php`](api/src/Agent/Ui/DashboardChannel.php), [`dashboard/src/agent.ts`](dashboard/src/agent.ts) |
| Symfony AI agent loop, async on Messenger (worker under CLI, no request time limit) or in `kernel.terminate` | [`RunAgentTurn.php`](api/src/Message/RunAgentTurn.php), [`RunAgentTurnHandler.php`](api/src/MessageHandler/RunAgentTurnHandler.php), [`api/src/Agent/Dispatch/`](api/src/Agent/Dispatch/), [`api/config/packages/ai.yaml`](api/config/packages/ai.yaml) |
| Turn journal, token and cost tracking | [`api/src/Agent/Journal/`](api/src/Agent/Journal/) (`TurnJournal`, `ToolCallJournalListener`, `TurnCostEstimator`) |
| Runaway guardrails: an explicit `end_turn` tool, terminal-tool listener, repeated-failure guard, `max_tool_calls: 25` | [`EndTurnTool.php`](api/src/Agent/Ui/EndTurnTool.php), [`TerminalToolListener.php`](api/src/Agent/Ui/TerminalToolListener.php), [`RepeatedToolFailureGuard.php`](api/src/Agent/Ui/RepeatedToolFailureGuard.php), [`ai.yaml`](api/config/packages/ai.yaml) |
| The in-app agent reuses the MCP tools through a toolbox (pilot-only tools excluded) | [`McpToolbox.php`](api/src/Mcp/Agent/McpToolbox.php), [`CompositeToolbox.php`](api/src/Agent/Toolbox/CompositeToolbox.php) |
| bonneref: second MCP server (read-only catalogue: plate, VIN, makes/models, categories, articles, narrowing, brand comparison) | [`bonneref/api/src/ApiResource/`](bonneref/api/src/ApiResource/), [`bonneref/api/config/packages/mcp.yaml`](bonneref/api/config/packages/mcp.yaml) |
| bonneref finder: a second agent that asks questions and renders parts | [`bonneref/api/src/Agent/Finder/`](bonneref/api/src/Agent/Finder/) |
| mpa talks to bonneref over HTTP, forwarding the caller's bearer | [`BonnerefClient.php`](api/src/Bonneref/BonnerefClient.php) |
| The Atelier (React + react-three-fiber) | [`dashboard/`](dashboard/), [`dashboard/CONTRACT.md`](dashboard/CONTRACT.md) |

## What we found broken upstream

Only items with a trace in this repo are listed.

- **API Platform: JSON-LD dropped read-only properties.** Issue filed:
  [api-platform/core#8332](https://github.com/api-platform/core/issues/8332)
  (`api/docs/resolved/jsonld-readonly-props-dropped.md:119`).
- **MCP SDK DNS-rebinding guard was localhost-only with no config hook.** On a public hostname every
  authenticated `/mcp` call answered `403 Invalid Host header` while unauthenticated ones still got 401, which
  reads as an OAuth failure (`CONFERENCE.md:162-170`, `api/tests/Functional/McpAllowedHostsTest.php`).
  `symfony/mcp-bundle` 0.14 exposes `http.allowed_hosts`. <!-- TODO: link the upstream PR/issue if ours. -->
- **mcp/sdk `InitializeHandler` echoes the server's protocol version** instead of negotiating the client's
  (`CONFERENCE.md:116-118`). <!-- TODO: upstream issue/PR link, or drop. -->
- **mcp/sdk 0.5 `Implementation::fromArray` rejected client version `"0"`** (`empty()` check)
  (`api/docs/PLAN.md:82-83`). <!-- TODO: upstream link, or drop. -->
- **MCP Apps (`io.modelcontextprotocol/ui`)** adopted from php-sdk PR #281 via local forks of mcp-bundle and
  api-platform/mcp before release (`api/docs/TODO_UI.md:12-20`). <!-- TODO: say whether we authored it. -->
- **Symfony AI Anthropic bridge cannot declare provider server tools** (`web_search_20250305`): `Tool`,
  `AgentProcessor`, `ToolNormalizer` and `ModelClient` only carry function tools (`docs/archive/next_plan.md:39-56`).
  We shipped a provider-agnostic `web_search` tool instead
  ([`WebSearchTool.php`](api/src/Agent/WebSearch/WebSearchTool.php)). <!-- TODO: upstream issue/PR link. -->
- **Casdoor DCR registers apps with an empty signing cert**, so JWT tokens cannot be minted for self-registered
  clients (`CONFERENCE.md:155-161`). <!-- TODO: upstream issue link. -->
- <!-- TODO: Qwen streaming tool-call with empty `id` crashing the generic bridge ("Undefined array key name");
  the fix lived in a local Symfony AI fork and has no trace in this repo. Add the upstream PR link or drop. -->

## Repository layout

| path | what |
| --- | --- |
| `api/` | mpa: garage domain, MCP server, in-app agent (Symfony / API Platform, MongoDB ODM) |
| `dashboard/` | the Atelier (React, react-three-fiber) |
| `bonneref/` | parts finder front; `bonneref/api` is its Symfony app (catalogue MCP server, finder agent) |
| `stage/` | the live-demo harness used for the talk ([`CONFERENCE.md`](CONFERENCE.md)) |
| `docs/` | design notes and investigations |

## License

[MIT](LICENSE), code only. Third-party assets (3D meshes, catalogue excerpts) keep their own terms; see
[`docs/public-audit.md`](docs/public-audit.md). <!-- TODO: revisit once licensed assets are removed. -->
