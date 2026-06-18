# Model Usage — Findings & Optimization Plan

Context: generative-UI garage dashboard, agent-driven loop. Goal — pick model tier + instrument cost. Target: 100–150k tokens per user input per loop (to refine with real data).

## Open question (refine target first)

"100–150k per loop" is ambiguous. Resolve before sizing budget:

- **Input only** → cheap; output dominates cost.
- **Total context at end of loop** → mostly cache reads; very cheap.
- **Total billed (input + output) across all loop iterations** → output is the real spend.

In agentic loops context grows to ~120k but 90%+ is cached reads after first call. **Output tokens are the real cost driver.**

## Findings

### Cost per loop

Model: ~120k input (12k fresh + 108k cache-read) + 15k output, caching on.

| Model | $/loop (cached) | $/loop (no cache) |
|---|---|---|
| Opus 4.8 | ~$0.49 | ~$0.98 |
| Sonnet 4.6 | ~$0.29 | ~$0.59 |
| Haiku 4.5 | ~$0.10 | ~$0.20 |

Pricing ($/MTok): Opus 4.8 = 5 in / 0.50 cache-read / 25 out. Sonnet 4.6 = 3 / 0.30 / 15. Haiku 4.5 = 1 / 0.10 / 5.

**Key insight:** with caching on, output tokens dominate (Opus output alone = $0.375 of that $0.49). Model choice matters less than effort + max_tokens discipline.

### Smartness needed

Loop is tool-heavy: list_vehicles, dedup resolver (VehicleWriteResolver), MCP bridge, generative-UI component emit. Hard part = tool selection + multi-step orchestration, not prose. Generative-UI JSON output is structured.

- **Sonnet 4.6** = sweet spot for main loop. 1M context, adaptive thinking, strong tool-use, 60% cheaper than Opus.
- **Opus 4.8** = quality ceiling. Switch only if Sonnet drops tool calls or mis-orchestrates. Don't pay preemptively.
- **Haiku 4.5** = dedup/ambiguous_match classification subagent (200k ctx fine). Spawn as subagent — never switch model mid-loop (breaks prompt cache).

### Other providers (DeepSeek / Mistral / Kimi) — skip for orchestration

- Harness built on MCP + Anthropic tool-use + structured outputs + prompt caching. Port = re-engineering, lose cache economics.
- Weaker tool-calling reliability. Real product (paying garage users) → mis-fired tool calls break dashboard.
- Cost edge real but caching already collapses input cost. Not worth reliability hit + rewrite.
- Revisit only on Anthropic rate limits or on-prem requirement.

## Optimization levers (bigger than model choice)

1. **Prompt caching discipline** — frozen system prompt, deterministic tool order. Cached prefix → 10x cheaper input. Verify `cache_read_input_tokens > 0`.
2. **`effort: "medium"`** on main loop — cuts output/thinking tokens hard, often no quality loss for structured work.
3. **Cap `max_tokens`** to real output size — dashboard component JSON isn't 64k.
4. **Tier with subagents** — Haiku for dedup/classify, keep main loop one model.

## Plan

### Phase 1 — Instrument (measure, don't guess)

- [ ] Add per-loop usage logger to agent: capture `input_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens`, `output_tokens` per model call.
- [ ] Aggregate per user input (sum across loop iterations) → derive $/loop.
- [ ] Verify caching is live: `cache_read_input_tokens > 0` across repeated calls. If zero, hunt silent invalidator (timestamps/UUIDs/unsorted JSON in prefix, varying tool set).
- [ ] Log model + effort + stop_reason per call for later analysis.

### Phase 2 — Baseline (one week of real data)

- [ ] Run Sonnet 4.6 main loop, `effort: medium`, caching on.
- [ ] Haiku 4.5 dedup/classify subagent.
- [ ] Collect: median/p95 $/loop, output-token distribution, tool-call success rate.
- [ ] Refine the 100–150k target against measured numbers.

### Phase 3 — Tune

- [ ] If tool orchestration flaky → bump main loop to Opus 4.8; re-measure.
- [ ] Sweep `effort` (low/medium/high) on eval set; pick by quality ↔ cost.
- [ ] Cap `max_tokens` to observed p95 output + margin.
- [ ] Audit cache prefix order (tools → system → messages); move volatile content after last breakpoint.

## Decision (starting point)

Sonnet 4.6 main loop, `effort: medium`, caching on, Haiku 4.5 dedup subagent. Measure, then escalate to Opus 4.8 only if reliability demands it.
