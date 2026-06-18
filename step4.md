# Step 4 — Wire the Symfony AI agent behind `/agent/turn`

> Companion to `ui.md` (steps 1–3 DONE). This is the detailed plan for step 4.

## Context

ui.md steps 1–3 are done: design tokens at one origin, Mercure on domain writes, and a
headless-UI vertical slice where `POST /agent/turn` returns a **fixed** `{actions: UIAction[]}`
list (no LLM) that the dashboard reducer applies. Step 4 replaces that stub with a real agent:
user instruction → LLM loop → MCP tools (read/write the garage) → emits UI-intents that drive the
dashboard, while domain writes fan out over Mercure for free. The parc sidebar also leaves mock
data via a real read.

The agent is a property of **our app**, not the domain. The domain only ever exposes MCP (already
shipped, also serving external hosts like Goose/Claude via mcp-ui). So if the entrypoint shifts to
IA-apps, the agent loop is disposable and the MCP server remains the durable asset — "both ways at
the same time."

## Settled decisions (from the design discussion)

1. **Agent ↔ tools = in-process MCP (no HTTP, no OAuth).** We do NOT re-author tool schemas
   (`#[AsTool]`) for the garage — that would fork the tool layer away from the MCP server. Instead a
   thin **`McpToolbox`** drives the **already-DI-registered `Mcp\Server`** in-process via a custom
   transport. Tool defs + the dedup/`ambiguous_match` instructions are reused verbatim. The Casdoor
   OAuth gate on `/mcp` stays for external hosts only (real trust boundary); our in-process agent is
   trusted and skips it.
2. **UI transport = stream over Mercure, ack the request.** The agent runs inside the synchronous
   `POST /agent/turn` request and publishes each UI-intent to a **per-session Mercure topic** as the
   loop produces it; the response is a 202 ack. Progressive UI, no Messenger. Domain writes already
   fan out on public topics (`DomainEventPublisher`).
3. **UI emission = render-intent tools as agent-local `#[AsTool]`, with a transport-agnostic vocabulary.**
   `select_vehicle` / `suggest` / `toast` / `patch_panel` are PHP tools the LLM calls; each validates
   against the typed `UIAction` vocabulary and publishes to the session topic. They stay `#[AsTool]`
   (NOT MCP) — and that is correct regardless of cross-host ambitions, because **MCP tool *calls*
   render nothing; only a tool *result* carrying a UI resource (mcp-ui) renders in a host.** So
   "make it an MCP tool" does not buy host-reach. Host-reach comes from the **delivery channel**, so
   the lever is to keep the `UIAction` vocabulary app-agnostic and let delivery branch later:
   - **our app** → Mercure session topic (today).
   - **host-as-widget** → ext-apps postMessage bridge (M2, future — `select_vehicle` etc. cross over
     here unchanged; same intent, different transport).
   - **host renders fresh** → mcp-ui resource returned as a tool result (M1, content intents like
     `suggest`/cards only; navigation intents are meaningless without a persistent shell). Already
     shipped via `UiResourceLoader`.
4. **Memory = rolling intent summary.** Fresh per-turn context: system prompt + compact UI-state
   snapshot (selected vehicle id + summary, not full data) + the instruction + a short rolling summary
   of recent intents. Tool results stay server-side and capped (`CappedResult`); only IDs/summaries
   enter the LLM.
5. **Model bridge = Anthropic** (`platform/src/Bridge/Anthropic`), per the project stack.

## Architecture / flow

```
 dashboard ──POST /agent/turn {instruction, sessionId}──▶ AgentTurnController
      ▲                                                        │ 202 ack
      │ EventSource(session topic + public vehicle topics)     ▼
      │                                              Symfony AI Agent::call(MessageBag)
      │                                                 │   ├─ McpToolbox  → Mcp\Server (in-process) → garage read/write
      │                                                 │   │                     └─ DB write → DomainEventPublisher
      │                                                 │   └─ render-intent #[AsTool] ─┐ (public topics)
      └──────────── Mercure hub ◀────────────────────────────────────────────────────┘ (session topic)
```

- Garage MCP tool call → DB write → `DomainEventPublisher` publishes to **public** vehicle topics →
  every viewer live-patches (already working).
- Render-intent tool call → validated `UIAction` → published to the **per-session** topic → only the
  requesting dashboard reacts. No chat transcript.

## Implementation — API (`api/`)

### A. In-process MCP bridge
- **`App\Mcp\Agent\CapturingTransport`** — server-side transport ≈ `Mcp\Server\Transport\InMemoryTransport`
  but `send()` **keeps** the response frame (the shipped one discards it, `InMemoryTransport.php:39`) and
  `listen()` returns the captured frame. Extends server `BaseTransport` to inherit `handleMessage` +
  the `onMessage`/`setPendingRequestsProvider`/`setResponseFinder` setters `Protocol::connect` needs.
  Seeded with one request frame + a fixed session id (`InMemorySessionStore`). ~40 lines.
- **`App\Mcp\Agent\McpToolbox implements Symfony\AI\Agent\Toolbox\ToolboxInterface`** — injects the DI
  `Mcp\Server` (same service `McpController` uses, `mcp-bundle/src/Controller/McpController.php:27`).
  - `getTools()` → run server with a `tools/list` frame, map each MCP tool → `Symfony\AI\Platform\Tool\Tool`
    (name/description/input_schema pass straight through; `ToolNormalizer` re-dialects for Claude).
  - `execute(ToolCall)` → build a `tools/call` frame → `$server->run(new CapturingTransport($frame, $sid))`
    → parse `CallToolResult` → return as `ToolResult`. No SDK Client, no Fibers (garage tools never use
    sampling/elicitation/progress). ~80 lines.
  - Tag `ai.toolbox` so the agent picks it up.
- Optionally restrict the exposed tool set per turn (garage read/write + `lookup_plate`); keep the bulky
  catalog browse tools out of the agent context to control tokens.

### B. Render-intent tools (agent-local)
- **`App\Agent\Ui\RenderIntentTools`** — one class, methods `#[AsTool]`: `selectVehicle`, `suggest`,
  `toast`, `patchPanel`. Each builds + validates a `UIAction` and publishes it to the session topic via
  the Mercure `HubInterface`/`Publisher` (reuse the pattern in `Mercure/DomainEventPublisher.php`).
  Returns a short ack string to the LLM (keeps tool results tiny → context control). The session topic
  string is injected per turn (see C).
- Expand the **typed vocabulary on the PHP side** to match the dashboard (see D): `setVehicles`,
  additional `patchPanel` panels.

### C. The turn endpoint + agent
- Replace `AgentTurnController::__invoke` body: read `{instruction, sessionId}`, build a `MessageBag`
  (system prompt = the MCP instructions text + UI-intent contract + rolling summary + compact UI snapshot),
  call the configured agent, return **202** (intents already streamed over Mercure during the loop).
- Configure the agent in **`config/packages/ai.yaml`** (DSL confirmed against the fork demo):
  `ai.platform.anthropic.api_key: '%env(ANTHROPIC_API_KEY)%'`; `ai.agent.garage` with `platform`,
  `model`, `prompt`, and `tools:` = the render-intent tools + the `McpToolbox`.
- Session topic = `{MERCURE_TOPIC_BASE}/agent/sessions/{sessionId}`. Dev hub is anonymous-subscribe;
  real per-user scoping (Casdoor JWT subscriber claims) stays deferred (already an ui.md open question).
- Composer: require `symfony/ai-agent`, `symfony/ai-platform`, `symfony/ai-bundle` (the fork monorepo
  already on disk under `~/forks/mcp/ai/src`; add path repos like the existing mcp forks).

### D. Real parc read (leave mock data)
- Add a **GET collection** for garage vehicles (none exists today — `GarageVehicle` has GET-item only).
  Back it with `VehicleRepository::findShared()` + `VehicleSummaryView::toArray()` (the exact shape the MCP
  `list_vehicles` tool already returns: `@id, make, model, year, vehicleType, source, kType, plate, vin,
  factCount, addedAt`). Either a new collection operation on `GarageVehicle` with a state provider, or a
  small read controller.

## Implementation — Dashboard (`dashboard/`)

- **`types.ts`** — extend `UIAction` union: add `setVehicles` and broaden `patchPanel` panels beyond
  `nextService`. Mirror the PHP vocabulary exactly.
- **`state.ts`** — add reducer cases for the new actions.
- **On load** — fetch the new GET collection → `setVehicles` (sidebar/detail leave mock data in `mock.ts`).
- **Session** — generate a `sessionId` (per tab), send it with `postTurn` (`agent.ts`), and subscribe
  `useMercure` to the session topic in addition to the public vehicle topics.
- `postTurn` — treat 202 as success (actions now arrive over Mercure, not in the response body).

## Files

- New: `api/src/Mcp/Agent/CapturingTransport.php`, `api/src/Mcp/Agent/McpToolbox.php`,
  `api/src/Agent/Ui/RenderIntentTools.php`, `api/config/packages/ai.yaml`.
- Edit: `api/src/Controller/AgentTurnController.php`, `api/composer.json`, the `GarageVehicle` resource
  (+ provider) for the GET collection, `api/.env`/compose for `ANTHROPIC_API_KEY`.
- Edit: `dashboard/src/{types,state,agent}.ts`, `dashboard/src/App.tsx`, `dashboard/src/useMercure.ts`.
- Reuse: `Mcp\Server` (DI), `Mercure/DomainEventPublisher.php` (publish pattern), `VehicleRepository::findShared()`,
  `VehicleSummaryView`, `CappedResult`, `platform/Bridge/Anthropic`.

## Verification

1. **TDD first** — failing functional test: `POST /agent/turn` with a deterministic instruction
   (e.g. "log a vidange at 142000 km on <vehicle>") asserts (a) 202, (b) the garage write happened
   (DB / `get_maintenance_history`), (c) a `UIAction` was published to the session topic. Use a mocked
   platform (`MockAgent`/`MockResponse` exist in `agent/src`) for tool-call determinism; keep a separate
   opt-in test that hits the real Anthropic platform.
2. **McpToolbox unit test** — `getTools()` returns the garage tools; `execute()` of `list_vehicles`
   returns the same payload as the HTTP `/mcp` path (proves verbatim reuse, no HTTP).
3. **End-to-end (ui.md dev run)** — `cd api && docker compose up`, `cd dashboard && npm run dev`. Type
   an instruction; confirm: toast appears immediately, then chips/panel patch progressively (session
   topic), and the KPI/HealthRing update from the domain write (public topic) — in a second tab too.
4. Sidebar shows real vehicles from the new GET collection, not `mock.ts`.

## Deferred (unchanged from ui.md)

- `web_search` sub-agent + generative chips (step 5).
- Move Mercure publish to Messenger (request shouldn't wait on hub).
- Per-session topic auth via Casdoor JWT subscriber claims.
- AG-UI event schema: mirror published names vs minimal subset.
- Cross-host UI delivery (M2 ext-apps postMessage bridge for `UIAction`s; M1 mcp-ui resources for
  content intents) — vocabulary is kept transport-agnostic now so this needs no tool changes later.
- In-process *client*-side transport (the heavier B variant) — unnecessary; driving the server side covers us.
