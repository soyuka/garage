# Next plan — grounded maintenance recommendations

## Motivating scenario

User asks: *"ma Mazda a passé les 250 000, quel entretien est préconisé ?"*

### What happens today

- `/agent/turn` → `AgentTurnController` → Symfony AI agent, model `claude-haiku-4-5`, over in-process MCP (7 tools).
- Agent path: `list_vehicles`/`lookup_plate` → find Mazda; `list_due_maintenance` → `DueMaintenanceCalculator` projects `next_due = last_performed + MaintenanceInterval::forType()`.
- **Gap:** recommendation only works if the user already recorded prior services. A Mazda at 250k with no history has nothing to project from. `MaintenanceInterval` = hardcoded general-knowledge defaults (oil 12mo/10k, brakes 24mo, plugs 48mo/60k…), **not** Mazda-specific, **not** keyed to a 250k milestone.
- Net: agent answers from its own training weights, ungrounded, no source, can hallucinate.

### UI for exchange — exists

- `Composer.tsx`: single-line chat input, streams render-intents over Mercure live.
- Generative overlays: `select_vehicle`, `toast`, `show_fact`, `propose_actions` (CTA cards w/ buttons).
- So two-way exchange + "confirm before I plan" is already possible via `propose_actions`.

### Web-sourced fact — none today

- Zero web search wired. Agent fully offline: local garage + TecDoc catalog (parts, not schedules).
- No OEM maintenance-schedule API exists. This is the core gap.

## Agreed design

1. **Persist the web fact** (AGREED). After any web lookup, agent calls `add_vehicle_fact` with a `source` field (URL/provenance). Turns an ephemeral web result into a durable, cited garage fact. Next time it's local + cited.
2. **Surface provenance in UI.** `show_fact` displays the source so the user sees the fact is web-derived/cited, not invented. Requires a `source` field on the fact model + `show_fact` payload.
3. **Expose `plan_maintenance` to the agent.** Currently excluded from `McpToolbox::ALLOWED` (token-control decision). Close this gap so the agent can create open `PlannedMaintenance` tasks.
4. **Milestone-aware forecast.** "Passed 250k" = milestone trigger, not last-service projection. Given current mileage + make/model, list services whose interval lands at/before 250k and aren't recorded done.
5. **Confirm loop.** Milestone forecast → `propose_actions` card ("Plan these N services? [Confirm]") → on confirm, `plan_maintenance` creates the open tasks.

### End-to-end target flow

250k question → agent checks local facts → misses → **web search** (cited) → `add_vehicle_fact(source=…)` persists it → milestone forecast → `propose_actions` confirm card → user confirms → planned tasks created. Grounded, cited, durable, two-way.

## Open decision — how to do web search

The model (Haiku 4.5) runs on Anthropic, which HAS a server-side `web_search` tool with native citations. BUT we don't call Anthropic directly — we go through **Symfony AI over in-process MCP**, and that bridge **cannot declare Anthropic server-side tools** as currently wired (verified in the fork).

### Findings (Symfony AI fork at `~/forks/mcp/ai`)

- `Tool` value object (`platform/src/Tool/Tool.php:21-56`) is a pure **function tool** (PHP callable + name/desc/params). No `type` field → no way to represent a provider-native server tool.
- `AgentProcessor::processInput` (`agent/src/Toolbox/AgentProcessor.php:63-75`) **overwrites** `options['tools']` with the toolbox's `Tool[]`. No passthrough for raw server-tool specs.
- `ToolNormalizer` (`Bridge/Anthropic/Contract/ToolNormalizer.php:36-43`) emits only `{name, description, input_schema}`. The `{"type":"web_search_20250305",…}` shape is unreachable.
- `ModelClient::request` (`Bridge/Anthropic/ModelClient.php:69-72`) never appends a server tool.
- **Response-side plumbing already exists**: `ResultConverter.php:103-115`, `AssistantMessageNormalizer.php:96-119`, `TokenUsageExtractor.php:52` parse `server_tool_use`/web_search results. Only the *request-side declaration* is missing.
- Symfony AI ships **`Brave`** (`agent/src/Bridge/Brave/Brave.php`) and **`Ollama` web_search/web_fetch** (`agent/src/Bridge/Ollama/Ollama.php:23,45,64`) — both as client-side `#[AsTool]` function tools calling external APIs. **Prior art in our own dependency.**
- **Haiku 4.5 web_search support — VERIFIED (2026-06-18).** Models API capability tree does NOT enumerate web tools for any model (Haiku/Sonnet/Opus all omit `web_search`/`web_fetch`; only `code_execution` is listed, `false` on Haiku) — inconclusive there. Empirical `/v1/messages` test with `web_search_20250305` declared: request validated (no 400), `stop_reason: end_turn`, one `server_tool_use` block, `usage.server_tool_use.web_search_requests: 1`. **Haiku 4.5 supports native server-side web_search — no model bump needed.** Dynamic filtering (`web_search_20260209`) still excludes Haiku; use `web_search_20250305`.

### Option A — native Anthropic server web_search (patch the bridge)

- Add a server-tool value object (with `type`) bypassing `ToolNormalizer`; let `ModelClient::request` append raw server-tool specs from an option; stop `AgentProcessor` from clobbering them.
- Pros: single-request server loop, **native citation blocks**, no own search API/key/cost, response-side already handles results.
- Cons: touches `Tool` + `AgentProcessor` + `ToolNormalizer` + `ModelClient` in the fork; non-trivial. Haiku server-search support unverified (may force model bump or `web_search_20250305`).

### Option B — custom `#[AsTool]`/MCP web_search via external API (Brave/Tavily)

- Mirror the existing `Brave` bridge. Zero vendor changes; fits `CompositeToolbox`.
- Pros: drop-in, matches how this codebase already adds tools; full control; we build provenance ourselves (already planned via `add_vehicle_fact(source)`).
- Cons: client-side (extra round-trips), own API key/cost, no Anthropic-native citation blocks (we synthesize source from the search result URL).

### Prior art for "search → cite → persist"

- Anthropic server web_search: citation IS the provenance (Option A).
- Tool-augmented generation / RAG: external search tool feeds results back into context (Option B).
- Memory-tool pattern: agent writes learned facts to durable storage (= our `add_vehicle_fact` persistence step, independent of which search path).

### Recommendation

Lean **Option B** for the first iteration: it fits the MCP-composed architecture, has prior art *inside our own dependency* (`Brave` AsTool), needs no fork surgery, and we were going to build provenance ourselves anyway. Revisit Option A if native server-side citations become a hard requirement. (Pending user pick.)
