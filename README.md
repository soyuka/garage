# mon-parc-auto

[![CI](https://github.com/soyuka/mon-parc-auto.fr/actions/workflows/ci.yml/badge.svg)](https://github.com/soyuka/mon-parc-auto.fr/actions/workflows/ci.yml)

A garage and maintenance tracker you run by talking to an AI. Your vehicles, their service history, planned work
and a usage-driven forecast of what is due are exposed as an MCP server, so Claude (or any MCP client) reads and
writes your garage with owner-scoped tools. When a client drives it, the **Atelier**, a 3D workshop in the
browser, reacts live: the car drives in, the part is revealed with its real catalogue reference, and logging a
visit closes the planned task. Parts come from **bonneref**, a second Symfony app in this repo that serves the parts
catalogue and plate lookup as its own MCP server and runs a parts-finder agent.

Built with Symfony 7, API Platform 5 (`api-platform/mcp`), `symfony/mcp-bundle`, Symfony AI, Mercure and
React / react-three-fiber. Entry for the Symfony AI & MCP hackathon.

## Try it in 2 minutes

MCP endpoint: **`https://garage.bonneref.fr/mcp`** (Streamable HTTP, OAuth). Atelier: **https://garage.bonneref.fr**.

Demo login on `auth.bonneref.fr`: `DEMO_EMAIL` / `DEMO_PASSWORD` <!-- TODO: fill in -->. The demo garage is shared
between judges and reset nightly.

Add the server:

- **Claude Code**: `claude mcp add --transport http garage https://garage.bonneref.fr/mcp`, then `/mcp` to log in.
- **claude.ai / Claude Desktop**: Settings → Connectors → Add custom connector → URL
  `https://garage.bonneref.fr/mcp`. No client id is needed: the client registers itself (dynamic client
  registration) and opens the login page.

Open https://garage.bonneref.fr in a browser logged in with the same account to watch the Atelier react, then
paste:

1. `What's due on my cars? Forecast the next services and tell me which one is the most urgent.`
   (`list_vehicles`, `list_due_maintenance`, `list_planned_maintenance`)
2. `Which oil filter should I order for the Mazda? Narrow it down to the physically different parts and compare brands.`
   (`get_service_parts`, `narrow_parts`, `compare_brands`, catalogue served by bonneref)
3. `Look up plate AB-123-CD and tell me which engine it has.` <!-- TODO: replace with a plate that resolves -->
   (`lookup_plate`, `decode_vin`)
4. `I just did the oil change on the Mazda today with that filter. Log it.`
   (`log_maintenance`: the visit closes the matching planned task and the forecast shifts)
5. `Open the Mazda in the Atelier and show me where the oil filter sits.`
   (`open_vehicle`, `reveal_assembly`, `focus_view`, `narrate_step`: render intents pushed to the browser)

The full tool list is advertised by `tools/list`; garage tools are declared as `McpTool` operations on API
Platform resources in [`api/src/ApiResource/`](api/src/ApiResource/) (for example
[`Maintenance.php`](api/src/ApiResource/Maintenance.php), [`Garage.php`](api/src/ApiResource/Garage.php),
[`Dashboard.php`](api/src/ApiResource/Dashboard.php)).

## Run it locally

Requirements: Docker with Compose. Ports 443/80 free, or set `HTTPS_PORT`/`HTTP_PORT`.

```bash
git clone https://github.com/soyuka/mon-parc-auto.fr.git
cd mon-parc-auto.fr/api
docker compose up -d        # php (FrankenPHP + Mercure), worker, MongoDB, Postgres, dashboard (Vite)
```

- Atelier: https://localhost (self-signed certificate). MCP: https://localhost/mcp.
- Auth uses the hosted Casdoor at `https://auth.bonneref.fr` (`CASDOOR_ISSUER` in `api/.env`); there is no local
  identity server.
- The catalogue and plate lookup are fetched from bonneref over HTTP: `BONNEREF_API_URL` defaults to
  `https://bonneref.fr`, so parts work without the licensed TecDoc data. Running `bonneref/api` yourself is
  optional and needs that data (see [`bonneref/api/README.md`](bonneref/api/README.md)).
- The MCP path spends nothing on our side: your own client does the reasoning. The in-app agent turn needs an
  `ANTHROPIC_API_KEY` in `api/.env.local`.
- Tests: `docker compose exec php php -d memory_limit=1G vendor/bin/phpunit <file>`;
  dashboard: `cd dashboard && npm install && npm test`.

<!-- TODO: confirm that a localhost redirect URI is registered for the dashboard app on auth.bonneref.fr,
     otherwise the local Atelier login fails. -->

## What's inside

| feature | code |
| --- | --- |
| MCP over HTTP, tools declared on API Platform resources | [`api/config/packages/mcp.yaml`](api/config/packages/mcp.yaml), [`api/src/ApiResource/`](api/src/ApiResource/) |
| OAuth for MCP: protected-resource metadata (RFC 9728), `WWW-Authenticate` challenge, Casdoor bearer validation; Casdoor chosen because it accepts anonymous dynamic client registration (RFC 7591), so stock clients self-register | [`ProtectedResourceMetadataController.php`](api/src/Controller/Mcp/ProtectedResourceMetadataController.php), [`McpProtectedResourceChallengeListener.php`](api/src/EventListener/McpProtectedResourceChallengeListener.php), [`CasdoorTokenHandler.php`](api/src/Security/CasdoorTokenHandler.php), [`api/docs/AUTH.md`](api/docs/AUTH.md) |
| Owner-scoped garage: every read and write is filtered by the caller's identity (Casdoor `email` claim) | [`OwnerContext.php`](api/src/Security/OwnerContext.php) |
| Maintenance domain: a record is a visit with `items[]`; recurring intervals + usage give a forecast and a health figure; logging a service closes its planned task | [`api/src/Maintenance/`](api/src/Maintenance/), [`api/src/Garage/`](api/src/Garage/) (`VehicleWriteResolver` dedups vehicles across labels) |
| Render intents over Mercure, one pipeline with two drivers: an MCP client (pilot) or the in-app agent call the same `Dashboard` tools; `DashboardChannel` picks the agent-turn topic or the owner's HMAC-derived pilot topic | [`Dashboard.php`](api/src/ApiResource/Dashboard.php), [`RenderIntentTools.php`](api/src/Agent/Ui/RenderIntentTools.php), [`DashboardChannel.php`](api/src/Agent/Ui/DashboardChannel.php), [`dashboard/src/agent.ts`](dashboard/src/agent.ts) |
| Symfony AI agent loop, async on Messenger (worker under CLI, no request time limit) or in `kernel.terminate` | [`RunAgentTurn.php`](api/src/Message/RunAgentTurn.php), [`RunAgentTurnHandler.php`](api/src/MessageHandler/RunAgentTurnHandler.php), [`api/src/Agent/Dispatch/`](api/src/Agent/Dispatch/), [`api/config/packages/ai.yaml`](api/config/packages/ai.yaml) |
| Turn journal, token and cost tracking | [`api/src/Agent/Journal/`](api/src/Agent/Journal/) (`TurnJournal`, `ToolCallJournalListener`, `TurnCostEstimator`) |
| Runaway guardrails: an explicit `end_turn` tool, terminal-tool listener, repeated-failure guard, `max_tool_calls: 25` | [`EndTurnTool.php`](api/src/Agent/Ui/EndTurnTool.php), [`TerminalToolListener.php`](api/src/Agent/Ui/TerminalToolListener.php), [`RepeatedToolFailureGuard.php`](api/src/Agent/Ui/RepeatedToolFailureGuard.php), [`ai.yaml`](api/config/packages/ai.yaml) |
| The in-app agent reuses the MCP tools through a toolbox (pilot-only tools excluded) | [`McpToolbox.php`](api/src/Mcp/Agent/McpToolbox.php), [`CompositeToolbox.php`](api/src/Agent/Toolbox/CompositeToolbox.php) |
| bonneref: second MCP server (read-only catalogue: plate, VIN, makes/models, categories, articles, narrowing, brand comparison) | [`bonneref/api/src/ApiResource/`](bonneref/api/src/ApiResource/), [`bonneref/api/config/packages/mcp.yaml`](bonneref/api/config/packages/mcp.yaml) |
| bonneref finder: a second agent that asks questions and renders parts | [`bonneref/api/src/Agent/Finder/`](bonneref/api/src/Agent/Finder/) |
| mpa talks to bonneref over HTTP, forwarding the caller's bearer | [`BonnerefClient.php`](api/src/Bonneref/BonnerefClient.php) |
| The Atelier (React + react-three-fiber) | [`dashboard/`](dashboard/), [`dashboard/CONTRACT.md`](dashboard/CONTRACT.md), [`dashboard/CHOREOGRAPHY.md`](dashboard/CHOREOGRAPHY.md) |

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
