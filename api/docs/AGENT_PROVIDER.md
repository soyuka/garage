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
2. `.env.local`: set `AGENT_WEB_SEARCH=qwen` and `AGENT_STREAM=0` (see web search note below),
   and confirm `ALIBABACLOUD_API_KEY` is set (it already is).
3. Clear the compiled container — config and metadata caches are sticky here:
   ```sh
   rm -rf var/cache/dev && php bin/console cache:clear
   ```
4. Restart the worker that runs the turn (Messenger consumer) so it picks up the new container:
   the turn runs in a `messenger:consume` process (see `RunAgentTurnHandler`), not the web request.

## Switch back to Anthropic (default)

Reverse step 1 (uncomment the Anthropic pair, comment Qwen), set `AGENT_WEB_SEARCH=anthropic` and
`AGENT_STREAM=1` in `.env.local`, then `rm -rf var/cache/dev && php bin/console cache:clear` and
restart the worker.

## Web search (`AGENT_WEB_SEARCH`)

Both providers support web search, but through **incompatible request shapes**, so the mechanism
must match the active platform. `App\Agent\WebSearchInputProcessor` switches on the
`AGENT_WEB_SEARCH` env (cast to `App\Agent\WebSearchProvider`):

| value       | mechanism                                                   | use with   |
|-------------|-------------------------------------------------------------|------------|
| `anthropic` | server-side tool sent as a `server_tools` block, max_uses:1 | Anthropic  |
| `qwen`      | DashScope `enable_search: true` boolean in the request body | Qwen       |
| `off`       | no web search                                               | either     |

Sending the wrong one fails: Anthropic `server_tools` is an unknown field on Qwen's
OpenAI-compatible endpoint (`400`), and vice versa.

For **both**, web search is a last resort — withheld until the agent has run ≥1 local tool (the
processor checks for a `ToolCallMessage` in the bag), so it can never be the first move.

Differences on Qwen:
- No per-turn search cap. Anthropic uses `max_uses:1` + `WebSearchBudget` (fed from
  `server_tool_use.web_search_requests` token deltas) to cap a turn to one search. Qwen's
  `enable_search` is a flat boolean — the model decides whether/how often to search, and
  `WebSearchBudget` never throttles it (its `exhausted()` only ever trips on Anthropic deltas).
- No citation tool-call loop. Qwen folds results into the model context server-side; there is no
  `web_search` `ToolCallMessage` to observe (so `App\Agent\ServerToolUseLogger`, which decorates
  `ai.platform.anthropic`, reports nothing on Qwen — it only logs Anthropic server-tool use).

## Logging Qwen searches (`AGENT_STREAM`)

Qwen reports its server-side search in a top-level `search_info` block on the response. The generic
ResultConverter keeps only content + token-usage deltas, so that block survives **only on a
non-stream response** — hence `AGENT_STREAM=0` on the Qwen profile. `App\Agent\QwenSearchLogger`
(decorates `ai.platform.generic.qwen`, mirrors `ServerToolUseLogger`) then reads the memoised body
and logs `Qwen server web search: N result(s) <urls>` at NOTICE — visible under
`messenger:consume -v`.

Streaming is safe to drop here: `RunAgentTurnHandler` discards the streamed deltas (the UI renders
from the render-intent tools' Mercure publishes, not from the text stream), so a non-stream turn runs
the identical tool-call loop and side effects — it just also exposes the body. Anthropic keeps
`AGENT_STREAM=1`; its searches are logged via the stream budget listener instead.

⚠️ The exact `search_info` shape is provider-defined and only confirmable against the live
deployment. `QwenSearchLogger` reads it defensively (`search_info.search_results[].url`, falling back
to `results[]`). If your deployment nests it differently, the log line will show
`0 result(s) (no urls in search_info)` even though a search ran — adjust the field paths in
`QwenSearchLogger::logSearch()` once you see a real body. Bump it to a `debug` dump of `$data` first
if you need to discover the shape.

## Endpoint details (Qwen)

- `QWEN_BASE_URL` = `https://ws-cchfndp3ldtdfudj.ap-southeast-1.maas.aliyuncs.com/compatible-mode`
  — the generic bridge appends `completions_path` (`/v1/chat/completions`), so the full URL is
  `…/compatible-mode/v1/chat/completions`.
- Auth: `ALIBABACLOUD_API_KEY` sent as `Authorization: Bearer …`.
- Model id `qwen3.5-flash` is what the agent sends in the request body. If the MaaS deployment
  reports model-not-found, adjust the model name in the `model:` line to the id the deployment
  exposes (the `?max_tokens=4096` suffix is parsed off by `AbstractModelCatalog::parseModelName`
  and forwarded as a body param — it is not part of the model id).
