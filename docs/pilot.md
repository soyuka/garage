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
| `show_fact(keys, title, nodes)` | focused spec panel for the SELECTED vehicle: `keys` filters its STORED facts, `nodes` projects lines you FETCHED (fill volumes, oil grade, carte grise) |
| `focus_view(panel, keys)` | raise "tasks", "specs", "carnet" (history + spend), "parts" (shopping list) or "docs" (the LIST of manuals, technical notes, quotes, bills) and light the matching body regions |
| `read_documentation_page(vehicle, document, page)` | the FULL text of one page (pdftotext layout, tables aligned) — call it after `search_documentation` when the answer is not entirely in the 300-char snippet. `page` is the physical page of the hit. Unknown id → `not_found`, page out of range or no extracted text → `invalid_input`. Answer from it, cite `sourceRef` + `pageLabel`, then `show_document` |
| `show_document(vehicle, document, page, highlight)` | open ONE vehicle document in the Atelier viewer, centred. Call it after `search_documentation` with the hit's `documentId` and `page` (physical page, not `pageLabel`) and your query as `highlight`; or with a `list_documents` id for page 1. Unknown id → `not_found`, page out of range → `invalid_input`, nothing opens. Still cite `sourceRef` + `pageLabel` in your answer |
| `propose_actions(headline, actions, finding)` | CTA card; buttons are NL follow-ups OR direct part-writes |
| `reveal_assembly(region, part_keys)` | look INSIDE: the shell ghosts to a wireframe, the region's schematic assembly rises under it, camera descends into the reveal pose. `part_keys` call parts out, they never filter the diagram |
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

## The motion of a piloted session

A pilot run is human-paced, and the dashboard is built for that shape rather than for a turn's
(`dashboard/CHOREOGRAPHY.md` § "The pilot driver" is the binding map; `dashboard/src/pilot.ts` is the
pure core). What you will see, and the datum each thing encodes:

- **No `turn:start` is needed.** The first `narrate_step` lifts the orb; the reducer treats a `status`
  arriving at rest as the start of a run.
- **`PILOTE`** sits beside the narration for as long as the run is yours — so a still screenshot says
  who placed the panels on it.
- **A burst is one gesture.** Intents fired inside 120 ms that each name a car region produce ONE
  camera dolly, to the last of them. Panels still open the instant their frame lands: only the camera
  waits for the burst to close.
- **An answer lands on the car it is about.** `select_vehicle` immediately followed by `show_fact`
  works: the fact is held until that car is on screen, then replayed. If the `@id` never resolves, the
  held intents are dropped after 6 s with a warn toast rather than shown over the wrong car.
- **Dead air is a state, not a fault.** Four seconds without a frame and the console enters *veille
  pilotée*: still lifted and held, but the reticle slows, the amber exertion gradient cools back to
  cyan, and a live `· en attente · 2 min` counts the silence. The 90 s watchdog does NOT apply to a
  pilot run — the dashboard cannot tell "reading code" from "quit", so it never claims either. The
  composer unlocks there too: typing takes the controls back.
- **`settle_dashboard` is the only clean end.** It plays the done filament and returns the orb to
  veille.

Offline, with no MCP host: `https://localhost/#/scene` → the **⇄** button in the composer replays a
pilot burst (two narrated steps, a three-region burst, a toast, then silence into veille pilotée);
press it again for the `settle_dashboard`.

## Demo script — one paste

Drives a full run against the live stack. Replace `VEHICLE` with an `@id` from `list_vehicles`; the
`show_fact` keys must exist on that vehicle or the panel comes up empty — a value you looked up
instead of read off the car goes in `nodes`, not `keys`.

```sh
TOKEN=$(python3 -c "import json;d=json.load(open('$HOME/.claude.json'));print(d['projects']['~/projects/mon-parc-auto.fr/mon-parc-auto.fr']['mcpServers']['mon-parc-auto']['headers']['Authorization'].split()[1])")
SID=$(curl -sk -D- -o /dev/null -X POST https://localhost/mcp -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"demo","version":"1"}}}' \
  | grep -i '^mcp-session-id' | tr -d '\r' | awk '{print $2}')

call() { curl -sk -o /dev/null -X POST https://localhost/mcp \
  -H "Authorization: Bearer $TOKEN" -H "Mcp-Session-Id: $SID" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }

VEHICLE=/garage/vehicles/01KVFEQA72XQMWSS3HPBD93ASR

# 1. the run opens with no turn:start — the orb lifts on the first narrated step
call narrate_step '{"message":"Je relis le carnet…"}'

# 2. DEAD AIR. Watch the console enter veille pilotée at 4s and start counting.
sleep 12

# 3. THE BURST: drive the car in and answer about it in the same breath. The fact panel is held
#    until that car is on screen — without the gate it would land on the car being replaced.
#    ⚠ ONE curl per call is NOT a burst: a fresh process re-does the TLS handshake and each call
#    costs ~2 s. Chain them with --next so they share the connection (~50 ms each, measured) —
#    otherwise the calls arrive further apart than the bundle takes to load and you see nothing.
H=(-H "Authorization: Bearer $TOKEN" -H "Mcp-Session-Id: $SID" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream')
body() { printf '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2"; }
curl -sk -o /dev/null -X POST https://localhost/mcp "${H[@]}" -d "$(body select_vehicle "{\"vehicle\":\"$VEHICLE\"}")" \
  --next -sk -o /dev/null -X POST https://localhost/mcp "${H[@]}" -d "$(body show_fact '{"keys":["oil_capacity","oil_type","oil_viscosity"],"title":"Huile moteur"}')" \
  --next -sk -o /dev/null -X POST https://localhost/mcp "${H[@]}" -d "$(body focus_view '{"panel":"tasks","keys":["oil_change"]}')"
#    …two of those name a region: ONE dolly fires, to the last one.

# 3b. THE REVEAL (C1–C4): open the car up. The shell ghosts to a wireframe, the bay's schematic
#     assembly rises under it and the camera descends over it. The dissolve is paced by the diagram's
#     own arrival, so there is nothing to wait for here — and no dismiss call either: releasing the
#     camera ("vue libre", Escape, a drag) puts the shell back.
call reveal_assembly '{"region":"bay","partKeys":["fuel_filter"]}'

# 4. a decision to take, then the close — the filament takes its colour from this tone
call propose_actions '{"headline":"Vidange à prévoir","finding":"Filtre MANN W 712/75 au catalogue","actions":[{"label":"Planifier la vidange","instruction":"planifie la vidange"}]}'
call settle_dashboard '{"message":"Vidange préparée","tone":"ok"}'
```

To watch the frames without a browser, subscribe to the topic from `GET /agent/link`:
`curl -sk -N "https://localhost/.well-known/mercure?topic=<url-encoded topic>"`.

**If the dashboard ignores a frame the hub clearly delivered**, check the browser's EventSource before
suspecting the topic digest: Caddy's cert is self-signed, and when Chrome drops the exception the
streams die with `net::ERR_CERT_AUTHORITY_INVALID` and retry silently forever (DevTools → Network →
filter `eventsource`). Reload and re-accept the certificate. Note the dashboard now holds **two**
streams (one per driver — see `dashboardSubscriptions`), so a broken cert produces twice the reconnect
noise it used to.

## Verified (motion layer)

2026-08-15, live stack, one MCP session over `/mcp`:

| what | observed |
|---|---|
| `narrate_step` at rest | orb → working, `driver=pilot`, `PILOTE` on the console — no `turn:start` sent |
| veille pilotée | `quiet` at **+3 999 ms**, caption counting `4 s` → `1 min` → `2 min`, **no watchdog warn at any point** |
| the staging gate | `select_vehicle` + `show_fact` **50 ms apart**, bundle **141 ms** later → the spec panel appeared *with* the car, correct content (`4,3 L` / `ACEA A3/B3\|A5/B5` / `5W-30`) |
| burst coalescing | 3 region targets in 180 ms → **one** camera hold established (one `.at-vuelibre` mount) |
| `settle_dashboard` | green filament + `posé · tout est à jour`, back to veille **1 600 ms** later; the projected answer stays on screen |
| frame budget | **59.9 fps, 0 frames > 20 ms, 0 long tasks** through the burst + dolly; 4 s of veille pilotée = **4 DOM mutations**, all on `.noyau-wait-clock` |
