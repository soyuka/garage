# Garage agent chat provider

The in-app garage agent (`ai.agent.garage`) can run on **Anthropic Claude** (default) or
**Alibaba Cloud Qwen** (OpenAI-compatible MaaS endpoint). Both platforms are always defined in
`config/packages/ai.yaml`; only one is wired into the agent at a time.

## Why it's a comment toggle, not an env var

The agent's `platform:` value is a **service id** (`ai.platform.anthropic` /
`ai.platform.generic.qwen`). The bundle resolves it into a `Reference` at container-**compile**
time (`AiBundle::processAgentConfig`, `->setArgument(0, new Reference($config['platform']))`), so
it cannot be a `%env(...)%` placeholder. Switching providers is a one-line comment swap + cache
clear. The `model:` string and `AGENT_WEB_SEARCH` flag *are* env-adjacent and move with it.

## Switch to Qwen

1. `config/packages/ai.yaml`, under `agent.garage`, comment the Anthropic pair and uncomment Qwen:
   ```yaml
   # platform: 'ai.platform.anthropic'
   # model: 'claude-haiku-4-5?max_tokens=4096'
   platform: 'ai.platform.generic.qwen'
   model: 'qwen3.5-flash?max_tokens=4096'
   ```
2. `.env.local`: leave `AGENT_WEB_SEARCH=off` (web search is the provider-agnostic custom tool now —
   see web search note below), and confirm `ALIBABACLOUD_API_KEY` is set (it already is).
3. Clear the compiled container — config and metadata caches are sticky here:
   ```sh
   rm -rf var/cache/dev && php bin/console cache:clear
   ```
4. Restart the worker that runs the turn (Messenger consumer) so it picks up the new container:
   the turn runs in a `messenger:consume` process (see `RunAgentTurnHandler`), not the web request.

## Switch back to Anthropic (default)

Reverse step 1 (uncomment the Anthropic pair, comment Qwen), leave `AGENT_WEB_SEARCH=off`
in `.env.local`, then `rm -rf var/cache/dev && php bin/console cache:clear` and restart the worker.

## Web search

Web search is **decoupled from the orchestrator**. The garage agent calls a `web_search` custom tool
(`App\Agent\WebSearch\WebSearchTool`); that tool delegates to a `WebSearchClient` which runs a
separate, focused model call to do the searching and grounding. So whichever model orchestrates the
turn — including Qwen, whose native search is weak — borrows real grounding, and the **main loop
carries no provider-native web search**.

```
garage agent (any platform)
  └─ tool call: web_search(query)
       └─ WebSearchClient.search()  → out-of-band side call (Anthropic|Gemini) → answer + citations
  └─ agent persists the finding (set_vehicle_facts, source:"web", url:…)
```

### Switching the web-search backend (`WEB_SEARCH_BACKEND`)

Two interchangeable `WebSearchClient` implementations sit behind `SelectableWebSearchClient`, a runtime
router that picks one from the **`WEB_SEARCH_BACKEND`** env (cast to `App\Agent\WebSearch\WebSearchBackend`):

| `WEB_SEARCH_BACKEND` | impl                       | platform / model                              | key             |
|----------------------|----------------------------|-----------------------------------------------|-----------------|
| `anthropic`          | `AnthropicWebSearchClient` | undecorated Anthropic, `WEB_SEARCH_MODEL`     | `ANTHROPIC_API_KEY` |
| `gemini`             | `GeminiWebSearchClient`    | `ai.platform.gemini`, `GEMINI_WEB_SEARCH_MODEL` | `GEMINI_API_KEY` |

Switching is a **one-line `.env.local` edit + worker restart** — no recompile and no service
re-aliasing (unlike the orchestrator toggle above, which is compile-time). The clients live behind a
service locator, so only the selected backend's (lazy) platform is instantiated; the unused backend
needs no API key. The Anthropic backend calls the *undecorated* platform
(`App\Agent\ServerToolUseLogger.inner`) so the side call doesn't pollute the turn's budget/journal;
the Gemini backend uses Google Search grounding (`server_tools: {google_search: true}`) and reads its
sources from the response `groundingMetadata`.

- Per-turn cap: `WebSearchTool` consults `WebSearchBudget` (reset per turn by `RunAgentTurnHandler`),
  records one use per call, and refuses once exhausted — a hard ceiling on searches per turn. The
  search is narrated on the dashboard naturally as a `web_search` toolbox call (`StepNarrator`).

Keep **`AGENT_WEB_SEARCH=off`** so the legacy per-platform mechanism below stays inert. It and the
custom tool both declare a `web_search`, and Anthropic rejects duplicate tool names.

### Legacy native path (`AGENT_WEB_SEARCH`)

`App\Agent\WebSearchInputProcessor` declares a provider-native web search, switching on the
`AGENT_WEB_SEARCH` env (cast to `App\Agent\WebSearchProvider`). Superseded by the custom tool; only
use it if you remove the tool.

| value       | mechanism                                                   | use with   |
|-------------|-------------------------------------------------------------|------------|
| `anthropic` | server-side tool sent as a `server_tools` block, max_uses:1 | Anthropic  |
| `qwen`      | DashScope `enable_search: true` boolean in the request body | Qwen       |
| `off`       | no native web search (the custom tool is the path)          | either     |

Sending the wrong one fails: Anthropic `server_tools` is an unknown field on Qwen's
OpenAI-compatible endpoint (`400`), and vice versa. On this path web search is a last resort —
withheld until the agent has run ≥1 local tool (the processor checks for a `ToolCallMessage` in the
bag). Qwen's `enable_search` has no per-turn cap and folds results into the model context server-side
(no `web_search` `ToolCallMessage`, so `ServerToolUseLogger` reports nothing on Qwen).

## Endpoint details (Qwen)

- `QWEN_BASE_URL` = `https://ws-cchfndp3ldtdfudj.ap-southeast-1.maas.aliyuncs.com/compatible-mode`
  — the generic bridge appends `completions_path` (`/v1/chat/completions`), so the full URL is
  `…/compatible-mode/v1/chat/completions`.
- Auth: `ALIBABACLOUD_API_KEY` sent as `Authorization: Bearer …`.
- Model id `qwen3.5-flash` is what the agent sends in the request body. If the MaaS deployment
  reports model-not-found, adjust the model name in the `model:` line to the id the deployment
  exposes (the `?max_tokens=4096` suffix is parsed off by `AbstractModelCatalog::parseModelName`
  and forwarded as a body param — it is not part of the model id).
