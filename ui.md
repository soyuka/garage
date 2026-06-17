# UI Plan — Headless Agent Dashboard

## Goal

A full dashboard SPA (sidebar · vehicle detail · KPIs · HealthRing · cost chart ·
suggestion chips) with a bottom input to talk to an agent. **The chat is hidden** —
the agent does not emit a transcript; it **mutates the dashboard state**. The user
keeps interacting with the UI normally (click, type) and the agent and user share
one state. This is the CopilotKit / AG-UI "headless copilot" model: UI-as-output.

The agent reads/writes the domain through the existing API Platform **MCP** server
and can delegate to a **web-search sub-agent** for internet lookups (parts,
procedures, TecDoc).

## What we already have

- **api/** — API Platform 4.3 + Symfony 8.1 + PHP 8.4 + **MCP server** (mcp/sdk 0.6,
  symlinked forks `forks/core/src/Mcp`, `forks/mcp/ai`). Mercure wired in Caddy.
  `App\Mcp\UiResourceLoader` serves MCP Apps (mcp-ui) HTML resources (currently
  `maintenance-history.html`).
- **admin/** — Vite + React 18 + **react-admin**. Stays an **isolated side app**.
  Not part of this work. Not mixed in.
- **Design system** (`Fleet Dashboard Design Concept.zip`) — `--mpa-*` CSS tokens +
  6 React TSX primitives (Button, Badge, StatusPill, Card, MetricStat, HealthRing).
  Framework-agnostic tokens, standalone (does not theme react-admin/MUI).
- **DESIGN.md** — Control Panel design system (dark HMI/telemetry, signal colors).

## Architecture decisions

### 1. Shell = React (new clean Vite app)

The product is a stateful SPA with an agent streaming UI changes inline. Twig +
Symfony UX cannot do "agent injects generative components" without rebuilding AG-UI
semantics in Stimulus. Design primitives are already React TSX. → **New Vite+React
app**, separate from `admin/` (react-admin untouched).

### 2. Adopt AG-UI the protocol, not CopilotKit the library

CopilotKit's runtime is Node — adopting it wholesale adds a Node service proxying to
the Symfony agent. **AG-UI is an open SSE event spec** (state deltas, tool progress,
render/select intents). Implement the client in React (light), emit from Symfony.
Same own-the-tech ethos as the Mercure + mcp-ui forks. CopilotKit = UX reference, not
a dependency.

### 3. Agent loop = Symfony AI over the existing MCP

Run the agent in Symfony (already invested in `forks/mcp/ai` + `mcp/sdk`). Agent tools
= the API Platform MCP server (read vehicle, history, write maintenance). The
**web-search sub-agent is one more tool** (`web_search`) the main agent calls →
returns structured parts/procedures → folds into UI (e.g. "Pièces pour la vidange"
chips). One service, no extra runtime. Flag: Symfony AI is young — accept churn.

### 4. Transport = Mercure, two vocabularies

Mercure already wired. Reuse one transport for two event types:

- **Domain data** — API publishes on every write → all open dashboards live-patch.
  ("Logged 142 000 km" → KPI + HealthRing update for every viewer.)
- **Agent UI-intents** — publish to a **per-session private topic** → only the
  requesting user's UI reacts (select vehicle, show chips, toast). This realizes
  "chat hidden": events map to UI state, never a transcript.

### 5. mcp-ui role narrows (intentionally)

Inside our React app we render natively with the design primitives — **not** iframes.
mcp-ui HTML fragments stay for **external MCP hosts** (Claude / Goose / ChatGPT-apps
reaching our server). The same `--mpa-*` tokens drive both targets → visual parity,
two render targets. Do not embed iframes into our own SPA.

## The "chat hidden" contract

The agent's reply is not text — it is a **typed UI-action list**. Start small; it
converges to AG-UI:

```
[ {type:'selectVehicle', id},
  {type:'renderSuggestions', chips:[{label:'Pièces pour la vidange', action}]},
  {type:'patchPanel', panel:'maintenance', data},
  {type:'toast', tone:'ok', msg:'Vidange enregistrée à 142 000 km'} ]
```

Domain writes go through MCP (→ Mercure → reactive). UI-intents go through this list.
Agent and user share one state.

## Data flow

```
        ┌─────────────────────── React dashboard (the host) ──────────────────────┐
        │  sidebar · vehicle detail · KPIs · HealthRing · suggestion chips · input │
        │     ▲ renders via design TSX primitives + --mpa-* tokens                 │
        └─────┼───────────────────────────────▲───────────────────────────────────┘
   user types │ instruction (POST)             │ events apply to UI STATE
   + clicks   ▼                                │ (no chat transcript)
        ┌── Agent turn endpoint (Symfony AI) ──┴──────────────────┐
        │  LLM loop with tools:                                   │
        │   • MCP tools → API Platform (read/write vehicles) ─────┼─► API mutates
        │   • web_search tool → sub-agent (internet)              │      │
        │  emits: domain writes + UI-intent events                │      ▼
        └─────────────────────────────────────────────────────┬──┘   Mercure publish
                                                               │      (domain events)
        ┌──────────────────────── Mercure hub ◄────────────────┴───────────┘
        │  • domain topic:  "vehicle/{id} updated" → all viewers re-render
        │  • session topic: agent UI-intent events → requesting user's UI
        └───────────────────────────────────────────────────────────────────
```

## Bootstrap order (lowest-regret)

1. **[DONE] Tokens to one origin.** `design/tokens/` + `styles.css` copied to
   `api/public/design/` (FrankenPHP serves `/design/*` same-origin). Added a fonts-free
   `tokens.css` bundle for embedding. `maintenance-history.html` retrofitted: its
   hardcoded palette + light-mode block removed, now aliases `--bg/--card/...` to
   `--mpa-*`. `UiResourceLoader::renderApp()` inlines the shared tokens into every
   fragment's `<head>` at serve time (fragments run in the host's iframe, not our
   origin, so a `<link>` can't reach us — inlining keeps one source on disk yet
   portable + fetch-free).
2. **[DONE] Mercure on domain writes.** `symfony/mercure-bundle` installed (recipe:
   `config/packages/mercure.yaml`, bundle registered; container already feeds real
   `MERCURE_URL`/`JWT_SECRET` via compose, secret matches Caddy hub). Added
   `MERCURE_TOPIC_BASE` env. `App\Mercure\DomainEventPublisher` is a Doctrine listener
   (postPersist/postUpdate buffer → postFlush publish) covering Vehicle /
   MaintenanceRecord / VehicleFact. Two topics per change: item
   `{base}/garage/vehicles/{ulid}` + collection `{base}/garage/vehicles`. Payload
   `{kind,event,vehicle,iri}`. **Verified**: ORM persist → hub 200 → SSE subscriber
   received the `vehicle/created` event. _TODO later: move publish to Messenger so the
   request doesn't wait on the hub._
3. **Vertical slice, no LLM.** React shell (the wireframed screen) using design
   primitives; bottom input POSTs a *hardcoded* UI-action list; render it. Proves the
   headless-UI loop end to end. Subscribe the shell to the Mercure topics from step 2.
4. **Wire Symfony AI agent.** input → agent → MCP tools → emit domain writes +
   UI-intents over the per-session Mercure topic.
5. **Add `web_search` sub-agent** as a tool. Then generative suggestion chips.

**Next: step 3** (React shell + headless UI-action loop, subscribing to step-2 topics).

## Open questions / to decide later

- AG-UI event schema: adopt the published spec verbatim vs a minimal custom subset
  that grows toward it.
- Streaming granularity: stream tool progress to UI, or only final UI-action list?
- Auth on the per-session Mercure topic (JWT subscriber claim scoping — Casdoor).
- Icon set: Lucide (thin stroke) recommended by design README; not yet chosen.
- Real logo asset (currently a CSS gradient "M" tile).
