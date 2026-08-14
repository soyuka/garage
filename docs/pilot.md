# The pilot channel — driving the dashboard from Claude Code

Prompt in your MCP host (Claude Code, Goose…), watch the workshop move. The host answers **in the
interface** — the car turns, the panel rises, the card appears — instead of in a transcript the user
is not reading. No `/agent/turn`, no in-app LLM, so no 4–20 s turn (see `docs/issue-turn-latency.md`).

## How it works

The in-app agent renders through `#[AsTool]` render intents bound to the asking tab's Mercure session
topic (`…/agent/sessions/<uuid>`). An MCP caller has no tab and no session, so the same intents are
also exposed as MCP tools (`src/ApiResource/Dashboard.php`) and published to the owner's **pilot
topic** instead:

```
…/agent/pilots/<hmac_sha256(owner_sub, APP_SECRET)>
```

`src/Agent/Ui/DashboardChannel.php` picks the target: bound turn session first, owner pilot topic
otherwise. Everything downstream is unchanged — the frames, `RenderIntentTools`, the reducer, the
choreography. The digest (not the raw `sub`) is what scopes the channel: the hub accepts anonymous
subscribers, so the topic string is the access control.

The dashboard asks for its topic once on mount (`GET /agent/link`, authenticated) and subscribes to
it next to its own session topic — `dashboardTopics()` in `dashboard/src/agent.ts`.

The pilot tools are on `McpToolbox::EXCLUDED`, so the in-app agent never sees them: it already has
each one as a session-bound `#[AsTool]`.

## The tools

| tool | what it does |
| --- | --- |
| `narrate_step(message)` | one running step sentence on the heartbeat; the first one lifts the orb |
| `select_vehicle(vehicle)` | drives that car into the workshop — **call it first** if your answer is about another vehicle |
| `show_fact(keys, title)` | focused spec panel for the SELECTED vehicle |
| `focus_view(panel, keys)` | raise "tasks" or "specs" and light the matching body regions |
| `propose_actions(headline, actions, finding)` | CTA card; buttons are NL follow-ups OR direct part-writes |
| `toast(message, tone)` | one-line acknowledgement, French |
| `settle_dashboard(message, tone)` | closing toast + turn end — the orb settles |

Shape of a run: `narrate_step` → do the work (garage tools) → render (`select_vehicle`, `show_fact`,
`focus_view`) → `settle_dashboard`. Forget the settle and the orb stays lit until the dashboard's 90 s
watchdog trips it with a warn.

Garage writes need nothing special: they already fan out on the public vehicle topic, so
`log_maintenance` from an MCP host live-updates every open dashboard.

Known edge: every dashboard of that owner reacts to pilot frames (intended — one operator, several
screens), and a `settle_dashboard` fired while an in-app turn is mid-flight settles that orb early.
Both drivers share one orb; only one should be at the controls at a time.

## Verified

2026-08-14, live stack: `tools/list` exposes the seven tools; `tools/call toast` →
`{"type":"toast","tone":"ok","message":"Pilote branché"}` observed on the pilot topic's SSE stream.
Functional coverage in `tests/Functional/DashboardPilotTest.php`, topic derivation in
`tests/Unit/Agent/Ui/DashboardChannelTest.php`.

Note: after changing MCP tool metadata, `rm -rf var/cache` is not enough — **restart the `php`
container** (FrankenPHP worker mode holds the compiled container in memory) and the `worker` container
(it holds the tool schemas too).
