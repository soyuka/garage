# Agent brief — the pilot-driven motion layer

Hand this file to a fresh agent working in `~/projects/mon-parc-auto.fr/mon-parc-auto.fr`.
It is self-contained: it assumes no memory of the sessions that built the pilot channel.

---

## Mission

The Atelier dashboard can now be driven from **outside the app** — an MCP host (Claude Code) calls
render-intent tools and the workshop reacts live. Read `docs/pilot.md` first; it is short.

Your job is the **motion layer for that mode**: make a Claude-piloted session read as a live cockpit
responding to an operator, not as a web page being repainted. The bar is "futuristic" in the sense of
*legible telemetry* — every movement must carry information a still screenshot would lose. Decoration
that carries nothing is a regression here, not a feature.

You own the choreography. What follows is the ground truth you must not violate, not a design spec.

## The two drivers (why this mode is different)

| | in-app agent turn | pilot (Claude Code over MCP) |
| --- | --- | --- |
| entry | user types in the composer → `POST /agent/turn` | external `tools/call` |
| topic | per-tab `…/agent/sessions/<uuid>` | per-owner `…/agent/pilots/<hmac>` |
| pacing | one bounded turn, 4–20 s, tools fire in a tight loop | a long human-paced session; frames arrive in bursts with dead air between |
| lifecycle | server brackets the run with `turn:start` / `turn:end` | **no `turn:start`** — a `status` frame arriving at rest implies working; `settle_dashboard` ends it |

That third row is the interesting one. The existing choreography was designed for a turn that starts,
works and settles inside ~10 s. A pilot session can narrate a step, go quiet for two minutes while
Claude reads code, then fire four intents in 300 ms. **Design for burstiness**: coalescing, queueing,
and an idle-but-attached state are yours to invent. The fourth row is the other one: decide what the
lifecycle should look like when nothing declares the start.

## Ground truth — read these before touching anything

- `dashboard/CHOREOGRAPHY.md` — **the binding frame → motion map**, and the authority. Revisable
  (unfrozen 2026-09-04): every new motion goes in the table, in the same verified vocabulary, no
  component animates against a mapping that is not written there, and a row that has gone stale is
  **corrected and dated** rather than contradicted by a newer one.
- `dashboard/CONTRACT.md` — the wire shapes. Never invent a frame name; the union is real and
  server-side in `api/src/Agent/Ui/RenderIntentTools.php`.
- `dashboard/src/noyau/state.ts` — the frame→state reducer. Pure, clock-injected (timestamps passed
  in), fully unit-tested. Any state machine you add follows that shape: pure core, glue at the edge.
- `dashboard/src/scene/` — the r3f scene. `Plateau.tsx` (canvas + post), `AtelierScene.tsx`
  (assembler), `SpecConstellation.tsx`, `PartsManifest.tsx`, `CarnetProjection.tsx`, `CtaCard.tsx`,
  `EntretienRail.tsx`, `ParcLot.tsx`.
- `dashboard/src/tokens/atelier.css` — the `--at-*` design tokens. Motion lives around line 69:
  `--at-ease-spring`, `--at-ease-sine`, and the note "data cascade ~800ms; everything yields to Le
  Noyau". There are **no duration tokens yet** — adding a proper motion scale is fair game and
  probably step one.

## Hard rules

1. **No theatre.** Timing must be caused by data, never padded to look busy. A staged
   `320ms → 950ms → 1900ms` beat sheet was deleted from `dashboard/src/Atelier.tsx` on 2026-08-14
   precisely because it made instant answers feel like the app was narrating at the user
   (`0b7fdb5`). Motion may *overlap* an arrival; it may never *gate* one. If a panel can open now,
   it opens now and animates while open.
2. **`prefers-reduced-motion` is a first path, not a fallback.** `dashboard/src/scene/useReducedMotion.ts`
   bridges the OS flag into React because a CSS `@media` backstop cannot reach a `useFrame` loop. Every
   motion you add answers to it.
3. **`EffectComposer multisampling` MUST stay `0`** (`dashboard/src/scene/Plateau.tsx:272`). The MSAA
   resolve path renders black on this stack (three r169 + postprocessing). This has cost a day before.
4. **The idle orb breathes like a sleeping Mac** — sine ease-in-out, ~4 s. Preserve it across any
   refactor of Le Noyau; it is a deliberate signature, not a placeholder.
5. **Never colour-only.** Every state change stays legible in grayscale and is announced on the
   aria-live line. The done filament already pairs colour with a mono label — match that discipline.
6. **Cheap idle.** The scene must not burn frames when nothing happens: `Plateau` is memoized so
   typing does not re-render it, and callbacks passed into the scene are stabilised for that reason.
   No per-frame allocation in `useFrame`. Target 60 fps on integrated graphics.

## Deliverables

1. `dashboard/CHOREOGRAPHY.md` extended with the pilot-mode rows — the map is the contract.
2. A motion scale in `atelier.css` (durations + easings as tokens) and the existing ad-hoc timings
   migrated onto it.
3. The implementation, with **pure unit tests** for anything stateful (vitest; follow
   `noyau/state.test.ts` — inject the clock, assert transitions, never sleep in a test).
4. A **demo script**: the exact MCP call sequence that shows the work off, so a human can replay it
   in one paste. Put it at the end of `docs/pilot.md`.
5. A report: what you changed, what you measured (frame timings, not vibes), what you deliberately
   left out.

## How to see your work

The stack runs already: `docker compose ps` from `api/`. Dashboard at **https://localhost** (Caddy,
self-signed cert — `curl -k`; a browser needs the cert accepted or `--ignore-certificate-errors`).

Drive the UI over the pilot tools — `narrate_step`, `select_vehicle`, `focus_view`, `show_fact`,
`propose_actions`, `toast`, `settle_dashboard`. Either call them as MCP tools if your host has the
`mon-parc-auto` server registered, or by raw JSON-RPC:

```sh
TOKEN=$(python3 -c "import json;d=json.load(open('$HOME/.claude.json'));print(d['projects']['~/projects/mon-parc-auto.fr/mon-parc-auto.fr']['mcpServers']['mon-parc-auto']['headers']['Authorization'].split()[1])")
SID=$(curl -sk -D- -o /dev/null -X POST https://localhost/mcp -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"probe","version":"1"}}}' \
  | grep -i '^mcp-session-id' | tr -d '\r' | awk '{print $2}')
# then POST tools/call with -H "Mcp-Session-Id: $SID"
```

To watch the frames without a browser, subscribe to the topic from `GET /agent/link` (authenticated)
with `curl -sk -N "https://localhost/.well-known/mercure?match=<url-encoded topic>"`. Mercure 1.0 answers `?topic=` with a 400.

Gotchas that will eat an hour if nobody tells you:

- Many rapid edits can wedge Vite into a ~1 s full-reload loop. Restart the `dashboard` container.
- If `dashboard/node_modules` is an empty root-owned directory, it is a stale Docker anonymous volume:
  remove it and `npm install` on the host to run vitest locally.
- Checks are `npx vitest run` and `npx tsc --noEmit` from `dashboard/`. There is no React Testing
  Library — keep logic pure and testable rather than adding a renderer. Ask before adding any
  dependency; the scene stack is pinned and fragile.

## Non-goals

- Do not touch `api/` agent-turn latency work (`docs/issue-turn-latency.md`) — separate effort.
- Do not touch the service-defaults catalogue; another agent is building it right now.
- No visual rewrite of the app, no new UI framework, no external CDN assets (the page must stay
  self-contained), no new 3D model pipeline.
- Do not commit. Leave the tree dirty and report; the human reviews and commits.

## Taste

**Amended 2026-09-11 — the audio half of this rule is retired.** It forbade a sci-fi UI blip outright,
and the operator has decided the Atelier should sound its state changes in exactly that register
(`CHOREOGRAPHY.md` § "Audition — transition cues"). The rule below still governs **motion**, unchanged:
no glow, sweep or parallax that fires on a timer and encodes nothing. What is withdrawn is its reach
over the ear. Recorded here rather than quietly contradicted, because `FUTURE.md` § 9 cites it.

The reference is a well-instrumented machine, not a sci-fi movie UI: an aircraft PFD, a mixing desk,
a hospital monitor. Those earn their motion — a needle moves because a value moved. The failure mode
to avoid is the opposite one: glows, sweeps and parallax that fire on a timer and tell the operator
nothing. If you cannot say in one sentence which datum a movement encodes, cut it.

One open question worth answering early, because it shapes everything else: **should a piloted session
look different from an in-app turn at all?** There is a real argument either way — same grammar means
one thing to learn, distinct grammar tells the operator who is at the controls. Pick one, write the
reasoning into `CHOREOGRAPHY.md`, and build it.
