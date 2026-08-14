# Issue — the agent turn feels slow (4–20 s, ~half of it ceremony)

Measured 2026-08-14 on the live stack, from the turn journal (`api/src/Agent/Journal/TurnJournal.php`,
Mongo `app.agent_turns`, per-tool `at` timestamps) — not estimated.

```
db.agent_turns.find({}).sort({startedAt:-1}).limit(6).toArray().forEach(t=>{
 print("=== "+(t.instruction||"").slice(0,60)+"  total="+t.durationMs+"ms");
 let prev=t.startedAt.getTime();
 (t.toolCalls||[]).forEach(c=>{ let at=c.at.getTime(); print("   +"+(at-prev)+"ms  "+c.name); prev=at; });
 if(t.finishedAt) print("   +"+(t.finishedAt.getTime()-prev)+"ms  [end]");
})
```

| instruction | breakdown |
| --- | --- |
| "taille pneus à l'avant ?" | show_fact **+2681 ms**, end_turn **+2447 ms** → 5.1 s |
| "Quelle est la prochaine échéance ?" | list_due_maintenance +3012, focus_view +2194, toast +1771, select_vehicle +0, end_turn +2389 → 9.4 s |
| "ouvre la renault" | list_vehicles > select_vehicle > toast > end_turn → 4.3 s |
| "deux pneus avant neufs…" | log_maintenance +3484, select_vehicle +0, toast +2165, end_turn +0 → 5.6 s |

Model `claude-haiku-4-5?max_tokens=8192`, 200–750 completion tokens per turn, 2–5 **sequential** model
round trips. `+0 ms` gaps prove the provider does batch several tool blocks into one message when it
chooses to — so the ceremony round trips are avoidable, not inherent.

## What costs the time

1. **Closing ceremony as separate round trips** — `toast` + `end_turn` (+ sometimes `select_vehicle`,
   `focus_view`) land after the answer already exists and cost ~2 s each. 40–65 % of a turn.
2. **Worker idle poll** — `compose.yaml:82` runs `messenger:consume` with no `--sleep`, so the Doctrine
   transport falls back to the 1 s default (`vendor/symfony/messenger/Worker.php:91`). Up to 1 s of dead
   air between the 202 ack and the `turn/start` frame.
3. **Discarded model text** — `RunAgentTurnHandler` drains the stream with `unset($delta)`. The first
   token exists ~400 ms in; nothing reaches the UI until the first tool call resolves (2.7–4.9 s).
4. **No deterministic short-circuit** — "ouvre la renault" is pure navigation and still costs a 4.3 s
   LLM turn with 4 tool calls.

## Candidate fixes (not done)

- Prompt: require the closing tools (`select_vehicle` + `toast` + `end_turn`) in ONE assistant message,
  emitted in parallel. Cheapest win, 2–4 s/turn.
- `--sleep=0.05` on the worker command (or move the transport to Redis/AMQP for push delivery).
- Publish model text deltas as `status` frames so the heartbeat has something honest at ~400 ms.
- A deterministic intent router in front of the LLM for navigation / stored-fact reads (the wayfinder
  chips already prove the pattern client-side).
- Re-check whether `end_turn` is still needed on the Anthropic path — it exists for tools-only models
  (Qwen) that never emit a natural stop.

Done separately (2026-08-14): the fake choreography padding on the deterministic wayfinder chips, and
the Claude Code pilot channel (`docs/pilot.md`) which bypasses the in-app turn entirely.
