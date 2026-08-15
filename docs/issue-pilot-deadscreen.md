# Issue — the pilot run leaves the screen dead while it reads

Sibling of `issue-turn-latency.md`, which measures the **in-app** turn. This one is about the
**pilot** path (`pilot.md`) — the one the conference actually runs on. Not committed work.

Observed 2026-08-15 on the stage stack, driving beat 1 (« qu'est-ce que j'ai à faire sur la Mazda ? »)
from Claude Code.

## Symptom

Between the first `narrate_step` and the first render intent, the console shows one step chip and
then nothing — for the whole duration of `list_vehicles` → `select_vehicle` → `list_due_maintenance`
→ `list_planned_maintenance`. Four model round trips of silence in front of a room.

## Why

Only `App\Agent\Ui\RenderIntentTools` publishes to Mercure (`RenderIntentTools::publish()`, one
`Update` per intent). Every **read** tool — `list_vehicles`, `list_due_maintenance`,
`list_planned_maintenance`, `get_service_parts`, `get_vehicle_facts`, `list_articles` — returns its
payload to the MCP host and publishes **nothing**. The dashboard cannot know they ran.

The client already tries to compensate, and the workaround is the tell: `dashboard/src/noyau/state.ts:143-166`
keyword-matches the *French narration sentence* against a rule table to guess which tool is running
(`['catalogue','pièces','références','tecdoc'] → get_service_parts`). The server knows the real tool
name and never sends it, so the client infers it from prose. That table is a liveness hack standing in
for a missing frame.

## Why not just narrate more

Each extra `narrate_step` is a full model round trip (~2 s on the in-app path, measured in
`issue-turn-latency.md`; the pilot path is unmeasured, see below). Narrating to cover dead air
*creates* the dead air it covers. Same objection kills "add a `tool_running` MCP tool": the model
would spend a turn announcing that it is about to spend a turn.

Liveness must come from **out of band** — something that fires without the model deciding to fire it.

## Candidate mechanisms

### A. Host hook → API → Mercure  *(recommended for the conference)*

A Claude Code `PreToolUse` / `PostToolUse` hook matched on `mcp__mon-parc-auto__*` POSTs
`{tool, phase}` to a new thin endpoint (`POST /agent/pilot/tool`), which publishes a `tool` frame on
the owner's pilot topic (`DashboardChannel::pilotTopic()`).

- **Earliest possible signal.** The hook fires when the model *emits* the call, before the request
  reaches PHP — so it covers the model's think time, which is the actual dead air.
- Costs the model nothing: no turn, no tokens, no protocol change.
- Config lives in `stage/.claude/settings.json` (which already carries `outputStyle` + permissions)
  and needs a token — `stage/bin/mint-token` already mints one for the session, so the hook can read
  the same one `bin/atelier` exports.
- **Limit:** Claude Code only. Goose or Claude Desktop driving the same MCP server stay dark.

### B. Server-side MCP tool-call listener  *(the durable floor)*

Publish the same frame from the server, around tool dispatch in `McpEndpointController` /
`McpToolbox`, for every tool including reads.

- Host-agnostic — works for any MCP client, zero client config, no secret to distribute.
- **Limit:** it can only fire once the request has landed, so on its own it lights the screen for the
  ~30 ms the server is busy and not the 2–4 s the model was thinking. Correct, honest, and nearly
  invisible alone.

A and B are not alternatives — B is the floor that always holds, A is what actually covers the gap.
Ship B first (it is small and hostless), then A for the stage.

### C. MCP progress notifications

Spec-native (`notifications/progress` over streamable HTTP), but it addresses host↔server liveness,
not dashboard liveness — the browser is not the MCP client. Would still need B to bridge to Mercure.
No advantage over B; noted so it is not re-investigated.

## Frame shape (proposal)

Add to the `UIAction` union (`dashboard/src/types.ts`), alongside `status`:

```ts
// The pilot is executing a tool. Out-of-band liveness — NOT model-authored, carries no reducer
// state, never gated by the staging gate (same class as `status`/`turn`).
| { type: 'tool'; name: string; phase: 'start' | 'end' }
```

`name` is the real MCP tool name. `classifyStep()` stays as the fallback for hosts that only send
narration, but stops being the primary path.

Note the staging gate: `pilot.ts:placesSurface()` must keep returning `false` for `tool`, same as
`status`/`turn` — a liveness frame that waits for a car to drive in is a liveness frame that arrives
after the thing it was announcing.

## Before optimizing: the pilot path is unmeasured

`TurnJournal` is wired only into `RunAgentTurnHandler` (the in-app turn). A pilot run writes no
journal entry, so every number in this note about the pilot path is an observation, not a
measurement. The table in `issue-turn-latency.md` exists because the in-app path *is* instrumented.

Cheapest instrumentation: mechanism B already touches the dispatch seam — have it stamp the same
`{tool, at}` rows into a pilot-scoped journal document while it publishes. Then the conference path
gets the same evidence table the in-app path has.
