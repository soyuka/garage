# Implementation kickoff — build `dashboard_2`, the **Atelier** dashboard

> Greenfield · prototype-first · ultracode-orchestrated.
> Paste this whole file as the opening prompt of a fresh **ultracode** session (or `@theming/IMPLEMENTATION-PROMPT.md`).

---

## Mission

Build **Atelier** — the new front-end for `mon-parc-auto.fr` — as a **brand-new, standalone project in a new directory `dashboard_2/`**. It talks to the *same* Symfony/API-Platform backend as the existing `dashboard/`, but it is a **clean-room rebuild of the UI**. The previous attempt failed by restyling `dashboard/` in place — it inherited the old SPA structure and only changed the paint. Atelier is a different mental model (a 3D garage *room*, not a restyled dashboard), so it needs a different codebase. Build it fresh, in small provable steps, and orchestrate the work with sub-agent workflows.

## HARD RULES (read twice)

1. **Greenfield. Do NOT reuse `dashboard/` UI code.** No importing, copying, or porting of components, hooks, CSS, styles, or token *values* from `dashboard/src/`. `dashboard_2/` never imports from `../dashboard`. Specifically forbidden: copying CSS custom-property values / token numbers (Atelier re-derives its scale from `DESIGN-VISION.md` §4); cloning the `App.tsx` component-tree structure or file layout; symlinking into `dashboard/` or its `node_modules`. **`dashboard/src/design/tokens/atelier/` on this branch is part of the FAILED attempt — OFF-LIMITS as a source.** Tokens are re-derived from the vision, never lifted.
2. **`dashboard/` is reference-only, and split into two tiers:**
   - **Tier-1 — pure transport/plumbing** (the PKCE+`authFetch` in `auth.ts`, `postTurn`/`cancelTurn` in `agent.ts`, the `EventSource` hook in `useMercure.ts`, and the bare fetch verbs in `garage.ts`): these encode the *contract itself*, not UI. They **MAY be re-authored near-verbatim** in `dashboard_2`'s own files (a faithful client of the same contract converges by necessity) — but must live in new files with **no import path back to `dashboard/`**, and the agent must **declare in a PROVENANCE note which functions it lifted**.
   - **Tier-2 — anything carrying display/derivation/state logic** (`state.ts` reducer, `vehicleFromSummary`, `computeStatus`, `@id`-normalization choices, `formatReading`, any component): **MUST be rebuilt fresh from the vision/contract — never lifted.**
3. **Do not touch `dashboard/`.** It stays the working old app until Atelier replaces it. Enforce structurally: every fan-out sub-agent's file-ownership grant explicitly excludes `dashboard/**`; the anti-reuse gate greps for leaks (below). All new work lives in `dashboard_2/`.
4. **Prototype before production.** Every stage below must be runnable and demoable on its own before the next begins. No big-bang.
5. **Honest tool, not eye-candy** (non-negotiable): if a maintenance value (due-date, km, health %) exists *only* inside a glow / hotspot card / 3D effect, that is a bug. The glass **truth-panel** and the flat **plan list** carry every value at full precision, always reachable. Acceptance test for any visual layer: **hide the entire WebGL canvas → no value is lost.**

## Read first (do not skip)

1. **`theming/DESIGN-VISION.md`** — the canonical "Atelier" vision; the single source of truth for *what to build* (Le Noyau, Le Plateau, rim-gauge, hotspots, parts-as-objects, the wow moments, the color/material/light language). Read in full.
2. **`docs/ui.md`** — the agent↔UI architecture you MUST honor: **hidden chat** (no transcript ever rendered), the agent mutates one shared state via a typed `UIAction` vocabulary, Mercure SSE transport. ⚠️ This doc contains an **aspirational sketch** with action names that DO NOT exist in the live code (`renderSuggestions`, `patchPanel`) — trust the contract section below + the real source, not the sketch.
3. **`theming/` reference images** (Read tool): `agent-icon-circle-brain.webp`, `nice-agentic-centric.webp`, `a05ed21b46ba0ccaf12c9e59b5eab378.webp` (VORTEX glass-over-hero), `original-7a8c557da2d270d4059d4e9bcf6dbc21.webp` (ATMOS hotspots), `460f1a1af5a92f4b25965d385b4fe1f0.webp` (CARBO turntable), `e12b6a041584ab8a70265c26423f9444.webp` (VoltDrive ring). Asset: `theming/espace4-sketchup.skp`.
4. **`theming/proposals/*.md`** — the three source visions, for texture.
5. **Contract sources** (reference-only, per HARD RULE 2): `dashboard/src/{garage,agent,auth,useMercure,state,types}.ts`, plus `api/src` for `RenderIntentTools.php`, `StepNarrator.php`, `AgentTurnController.php`, `DomainPublisher.php` if a payload is unclear.
6. **`DESIGN.md`** is **deprecated** (flat "Control Panel") — read only to know the surface language Atelier rejects.

**Step-zero guardrail (anti-leak):** before building, distill the contract into a fresh **`dashboard_2/CONTRACT.md` in your own words** (routes, verbs, payload shapes, Mercure topics/frames, the UIAction vocabulary) + re-author the typed interfaces. Build primarily against *that* doc and your own types; re-open `dashboard/` source only to verify a specific payload shape, never as a copy buffer.

## The backend contract (re-implement, don't copy — verified against live code)

- **Auth:** Casdoor PKCE → Bearer; an `authFetch` wrapper attaches it. Public `GET` reads need **no** Bearer; `/agent/turn` and **all writes are gated** — a 401 wipes the token and triggers a full-page login redirect. Re-implement the flow + a `/callback` route.
- **REST (API Platform, `application/ld+json`; collections use `member` / `hydra:member`):** vehicles collection + item; per-vehicle subresources for planned tasks, maintenance records, forecast, service-schedule; vehicle facts; task **parts** subresource; maintenance **attachments** (bill upload/download); archive-done-tasks. `dashboard/src/garage.ts` is the route+payload spec. The vehicle key everywhere is the dereferenceable **`@id` IRI** (e.g. `/garage/vehicles/01J…`), never a bare ULID.
- **Agent turn:** `POST /agent/turn` is **async (202)**. Accepted body: `{instruction, sessionId, selectedId?, summary?, pendingContext?}` (`summary` = compact on-screen state; `pendingContext` = the card being replied to; both optional). The reply is NOT text — it arrives as state mutations over Mercure. A cancel endpoint exists.
- **Mercure (live) — subscribe to exactly two absolute-IRI topics:** the **collection** topic `${VITE_API_BASE}/garage/vehicles` (the publisher fans the same full-vehicle payload to item *and* collection topics, so the collection subscription alone covers every vehicle — do **not** add per-item subscriptions) **+** the **session** topic `${VITE_API_BASE}/agent/sessions/<sessionId>`.
- **Frame types:**
  - **Domain event** (collection topic): `{kind, event, vehicle, iri, resource}` — `resource` is the full vehicle; drives the live data cascade.
  - **Session frames** (agent topic): the transient cues `{type:'turn', phase:'start'|'end'}` (drives busy) and `{type:'status', message}` (per-narrated-step narration); the toast cue `{type:'toast', tone, message}`; and the real **UIAction** intents below.
- **The REAL `UIAction` union** (verified `types.ts`; the only members that exist): `setVehicles`, `selectVehicle` (`.id` is the `@id` IRI), **`showFact`** (`{type:'showFact', keys:string[], title?}` — spotlights named vehicle-fact keys), **`proposeActions`** (`{type:'proposeActions', headline, finding?, actions: CtaAction[]}` — the CTA card), `toast`. **`renderSuggestions` and `patchPanel` DO NOT EXIST — never build against them.**
  - **`CtaAction` is dual-shape:** either `{label, instruction}` (re-runs the agent with that NL instruction) **or** `{label, patchTask:{id, parts}}` (a deterministic PATCH, **no agent turn**). Wire the `patchTask` path — skipping it is a known dead-button bug class.
- **Narration routing (match the live client):** the heartbeat/narration line is fed by **`status` frames AND non-alarm `toast` frames**; only **`alarm`-tone toasts** surface as real toast popups. Le Noyau's narration queue consumes both.
- **Pulse granularity (important):** `status` frames are emitted only for a **whitelisted subset of tools** (a step narrator; unmapped tools produce no line) — **not** one per tool-call. So Le Noyau **pulses per narrated step**, sparsely — never claim "one pulse per tool-call." Mock streams must fire `status` frames sparsely to match.

## Locked tech choices (decided — do not re-litigate)

- **Scaffold:** fresh **Vite + React 18 + TypeScript** in `dashboard_2/` (same toolchain as `dashboard/` so it slots into the dev env + Mercure proxy), its **own** `package.json`, **own** `tsconfig` (no path alias resolving outside its root, no workspace link), own tokens, own `.env`.
- **3D engine:** Three.js via **React Three Fiber (R3F) + `@react-three/drei`** — drei's `<Html>` anchors hotspot pins + frosted cards in 3D for free; `useGLTF`/`Stage`/`Environment`/`ContactShadows`/`Bounds` cover staging; light for a single-hero scene. Babylon rejected.
- **3D assets:** `dashboard_2/public/models/*.glb` (Draco/meshopt compressed), served static, lazy-loaded via `useGLTF` + Suspense. Never bundled.
- **Le Noyau v1 is CSS/SVG (no WebGL)** so it ships standalone before any Three scene exists.
- **State:** a fresh typed reducer mirroring the real `UIAction` union. **Testing:** Vitest. **Formatting:** new French helpers, TDD'd from scratch.

## Atelier non-negotiables (from `DESIGN-VISION.md`)

- Signal semantics locked: `vert`=ok / `ambre`=bientôt / `rouge`=en retard, rendered as **emissive light**, **never the only signal** — every glow pairs with a mono label, **and the hotspot glyph is shape-differentiated by state** (legible in grayscale, not color alone).
- **Cyan is rationed to the agent (Le Noyau) only** — the one cool thing in a warm graphite room. Never flood it.
- **French UI throughout:** `15 janv. 2026`, `142 000 km` (thin-space), "dans environ 2 mois" (no false precision; round health to nearest 5); **overdue is precise** — `DÉPASSÉ de 3 200 km`.
- **The truth-panel/plan-list is authoritative; the 3D conveys urgency only.** The car attracts attention; the panel provides truth. Every value in a hotspot card, rim arc, or body-glow MUST also appear, full-precision, in the always-visible truth-panel or flat plan list.
- **Glass never fully occludes the car** — the body stays readable behind/around the glass at every viewport size.
- **The composer is always accessible and typeable** — CTAs and glass rise from *behind* it, never over it; no moment or modal disables the input.
- **`prefers-reduced-motion`:** breathing/pulses/car-descent collapse to instant state changes; the overdue wow becomes a static red state + narration line.
- **No new backend runtime.** Atelier is a fresh *client* over the existing reducer-shaped state + Mercure transport. Hidden-chat contract preserved.

---

## Build path — Stage 0 → A → B → C → D, each runnable & demoable

> **Order is the point:** stand up the rails, prove the soul, build the honest data floor, then the look, then wire real data, then the magic. Do not skip ahead.

### Stage 0 — Scaffold & dev-env (the rails)
Create `dashboard_2/` (Vite+React+TS), `package.json`, `tsconfig` (isolated), `.env` (`VITE_API_BASE`, `VITE_MERCURE_URL`), the **Vite proxy** for `/.well-known/mercure` and the Casdoor paths (the same-origin Caddy-hub proxy trick the whole app rides on — non-obvious, get it right), a placeholder dev-route router, and Vitest config.
- **Acceptance:** `cd dashboard_2 && npm run dev` boots a blank routed page; `npm test` runs a trivial green test; a manual `EventSource` against the proxied hub receives a keep-alive (**proxy proven**) — all with zero app logic. Also author & freeze the re-implemented **typed contract interfaces** (`VehicleSummary`, `MaintenanceHealth`, `NextDue`, `VehicleForecast`, `PlannedTask`, `ServiceRule`, `MaintenanceRecord`, and the frame/`UIAction` types) derived field-by-field from `dashboard/src/types.ts` into `dashboard_2/src/types.ts`.

### Stage A — Prototype **Le Noyau** standalone (the soul, in isolation)
Le Noyau as an **isolated dev-route playground** driven by a **scripted mock event stream** (no backend).
- CSS/SVG faceted sphere on a machined cradle; the cyan→amber exertion gradient (the only sanctioned gradient).
- Four states driven by frames **using the real shapes** (`{type:'turn',phase}`, `{type:'status',message}`, `{type:'toast',tone,message}`): **idle (veille)** breathing, **listening**, **working** (lift + ignite + **one pulse per `status` frame**, fired sparsely), **done (posé)** settle → green/amber/red. Narration line etches from the cradle base, one line at a time; consumes `status` + non-alarm `toast`. Click → journal drawer (mock history + pulse glyphs + elapsed stamps). Notification ember.
- `prefers-reduced-motion` variant.
- **TDD the frame→state machine** against those exact frame shapes (so Stage C is a transport swap, not a rewrite).
- **Acceptance:** a button panel fires mock turns; the orb visibly lives, pulses are countable per narrated step, narration ticks, reduced-motion collapses. **End with a (non-optional) critique panel — 3 lenses:** motion quality; reduced-motion/a11y; **frame-shape correctness vs the real `status`/`turn` shapes.**

### Stage B — The honest data floor, then the look (on fixtures)
Static **typed fixtures only** (`dashboard_2/src/fixtures/`, satisfying the Stage-0 interfaces; components consume the typed interface, never loose JSON) — **no backend, no Mercure**. Scenarios switchable: healthy / a service ambre / **overdue rouge** / empty parc / **no-3D-asset**.

**B.0 — freeze the shared contracts (serial barrier, before any fan-out):** the orchestrator authors & freezes (a) the typed fixture interfaces + sample data, (b) the `--at-*` token file (re-derived from `DESIGN-VISION.md` §4), and (c) the **choreography map written in the real vocabulary** (`selectVehicle`→drive-forward, `showFact`→focus-panel/etch-in, `proposeActions`→CTA-from-cradle, `toast`→ember, `status`→Noyau pulse, `turn`→busy). No fan-out agent may add/rename a token or fixture field.

**B.1 — the honest floor FIRST (truth before spectacle — `DESIGN-VISION` §8 / BUILD-NOTE 5):** build and merge, as standalone reviewable artifacts reading the fixtures, **before any Plateau/rim/hotspot work**:
- the **flat plan fallback list** (next-due per service, days/km-until, overdue flags) — first-class, permanently reachable, **not** a Plateau sub-feature;
- the **glass truth-panel** (identity, mileage, per-system health %, next-due, overdue — full precision). The **overdue scenario must surface the precise value** (`DÉPASSÉ de 3 200 km`) here and in the flat list *now*, three stages before any red glow exists.

**B.2 — the look (fan-out after B.1 passes):** the visual components, each an independently-owned leaf exporting a typed component + its fixture-prop contract — **the orchestrator owns the scene assembler / layout shell; leaf agents NEVER edit it**:
- **Le Plateau** with a **manual/fixture rung selector** (scenario prop forcing rung a/b/c/d — the seam Stage D's auto-detector later plugs into): rung (d) flat list → (c) SVG car silhouette + hotspot-grid overlay → (b) R3F **body-class proxy** (hatchback/berline/SUV/utilitaire/moto) in paint color. Hotspot anchors are **per body-class, not per mesh**. (Exact `.glb` is Stage D.)
- **Hotspots** (hex pins → frosted card: system, health %, interval, days/km-until, last-logged, action chip) — **shape-differentiated glyphs**, and **every value in the card also lives in the truth-panel** (urgency vs truth division). **RimGauge** (arc segments per system). Cardinal disc readouts. Plate. Body-glow on sick parts.
- **ParcLot** (baked-render cards, base-ring health color). **PartsShelf** (fixture parts as pedestal objects). Material/light: warm graphite void, glass recipe (**never fully occluding the car**), brushed metal, glow=signal, depth/shadow.
- Prove the **overdue-crossing** on fixtures here: a scenario toggle amber→rouge drives hood-glow + rim-arc + ember on demand (Stage D only swaps the trigger to a live frame).
- **Acceptance:** the mock *is* `DESIGN-VISION.md` made real, navigable, on fixtures; **Le Noyau (lifted from Stage A) docks over the composer at correct z-order (highest) and still cycles all four states** via the scenario switcher; hide the WebGL canvas → no value lost; grayscale → state still legible. End with a **design-fidelity critique panel** (parallel reviewers score each component vs the vision; a completeness critic for missing visual states; occlusion + color/label/grayscale checks).

### Stage C — Wire to the real backend, one slice at a time
Swap fixtures → real client against the verified contract. **Serial spine first, then parallel tail** (the spine is a real dependency chain):
1. **Auth (slice 0):** re-implement PKCE/Casdoor + `authFetch` + `/callback` + token storage. *Acceptance: a real login yields a Bearer and one gated write returns 2xx.* (Public reads work without it — don't let that give false confidence.)
2. **Vehicles collection/item + parc-list & selection** — the spine every vehicle-scoped slice reads.
3. **Truth-panel + forecast + flat plan fallback** on live data — the honest floor.
4. **Le Noyau live on real turns + the `UIAction` choreography dispatch** (`selectVehicle`→drive-forward, `showFact`→focus-panel, `proposeActions`→CTA-from-cradle incl. the **`patchTask` deterministic path**, `toast`→ember, `status`→pulse). Wire the composer → `POST /agent/turn` (async 202) + cancel. **Hidden chat — never render a transcript.**

Then **pipeline the genuinely independent tail** (each its own `[wire → TDD → verify]` lane): **schedule**; **tasks/checklist + parts**; **records/history + bill-upload**; **hotspots/rim bound to live health**. Wire Mercure: collection-topic domain frames → cascade; session frames → Noyau + intents.
- **Per-slice gate:** end-to-end against the real backend, tests pass, **chrome-devtools run confirms it (screenshot + clean console)**, anti-reuse + contract-fidelity + honesty/label gates pass. App never regresses between slices.

### Stage D — Moments, real 3D asset, choreography, polish
- **Serial first:** build & freeze the shared **choreography/perf layer** as one owned module (Noyau-priority animation queue, data-cascade timing ~800ms, drive-forward, DPR cap, lazy-init WebGL on vehicle view + teardown on parc view). **Then parallel:** each moment consumes that frozen queue API and owns only its assets. The **`.glb` export** (`espace4-sketchup.skp` → compressed `.glb` → rung (a) for matching vehicles; all others stay on proxy/SVG/list rungs) is independent — run it in parallel from the start.
- **Moments:** make the **live overdue crossing** (*service passant en rouge*, §9 — hood breathes red, rim arc ignites, ember docks, **in every open tab**) the **first** Stage-D moment with a multi-tab acceptance gate (its on-fixtures animation already exists from Stage B; here swap the trigger to a live Mercure domain frame). Then *lecture de plaque* (ghost wireframe resolves), *recherche de pièce* (pedestal/part renders, CTA from cradle), *saisie d'une facture* (service-tray, three-column bill modal, skeuomorphic curled-paper bill), *La Mise en Fosse* (first-run car-descent).
- **Polish/perf/a11y:** WebGL+frame-budget detection → **auto-select degradation rung** behind the Stage-B manual seam; final holistic color+label / grayscale / WCAG-on-glass pass; backgrounded-tab pause.

---

## Operating mode — ultracode orchestration

You are running in **ultracode**. Author and run **Workflow** scripts for substantive work; **stay in the loop between stages** (read each stage's result before starting the next).

- **Stage 0:** solo (it's the rails). Gate it before anything else.
- **Stage A (soul):** solo or a tiny workflow — one tightly-coupled artifact. **End with the mandatory 3-lens critique panel.**
- **Stage B:** **B.0 barrier is mandatory and serial** (freeze fixtures + tokens + choreography map) → **B.1 honest floor** (truth-panel + flat list, reviewed) → **B.2 fan-out**. In the fan-out, **the orchestrator OWNS the scene assembler and shared shell; each leaf agent owns ONLY its component file(s)** and exports a typed component — no agent edits the assembler or the frozen token/fixture files. After the barrier, the orchestrator wires components into the scene serially. (Worktrees are a last resort, not the plan — and note a worktree `dashboard_2/` needs its **own `npm install`**; node_modules isn't shared. Prefer the orchestrator-owns-assembler rule so no shared-file edit ever happens.)
- **Stage C:** **serial spine (slices 1–4 above) then parallel pipeline** for the independent tail. Each slice ends at the per-slice gate.
- **Stage D:** **serial choreography/perf layer, then parallel moments**; `.glb` export parallel from the start.
- **Standing adversarial review gates** (run at **every** Stage-B component review and **every** Stage-C slice — not just at the end): **anti-reuse**, **contract-fidelity**, **honesty/legibility (color+label+grayscale+WCAG-on-glass)**, **French-correctness**, **reduced-motion**.
  - **Gate mechanic:** ≥3 independent reviewers, each defaulting to **REFUTE** and required to cite a concrete contract line / vision §, `file:line`, or value the slice violates (a reviewer with no citation does not count — no vibe-kills). A claim is **KILLED if a majority refute**; a kill **blocks the slice from "done" and reopens it** with the cited defects as the fix list. Ties escalate to the repo owner with both sides' evidence.
  - **Anti-reuse gate input + mechanics:** (a) the slice's new/changed `dashboard_2` files, (b) the corresponding `dashboard/` source for side-by-side, (c) the agent's PROVENANCE note. Run mechanical checks FIRST: `grep -rn '\.\./dashboard\|dashboard/src' dashboard_2/` must be **zero**; `dashboard_2` has its own `tsconfig`/`package.json` with no external path alias; a Tier-2 file with **>~40% line overlap** vs `dashboard/` is an **automatic fail**. Fails closed: unreviewable similarity = rejected.
- **No silent caps:** if a workflow bounds coverage (top-N, sampling, no-retry), `log()` what was dropped.

## Working rules

- **Test-first** for all logic (formatting helpers, the Noyau frame→state machine, degradation-rung selection, data mappers): write the failing Vitest first, confirm the failure, then implement.
- **Visual/3D verification is mechanical, not vibes:** drive the running app with the **chrome-devtools tools** (`navigate`, `take_screenshot`, `list_console_messages` for WebGL/runtime errors); attach the screenshot + a **clean-console assertion** to the slice's acceptance. A slice with console errors or no screenshot is **not done**. (`cd dashboard_2 && npm run dev`; API via `cd api && docker compose up`.)
- **No shortcuts.** Correct over quick. On a real tradeoff, present options + evidence; the repo owner picks.
- **Do not commit** without explicit instruction. Stay on branch `feat/atelier`. Never `git add`/`commit`/`push` outside an explicit request. Keep each stage/slice reviewable.
- Never import from or modify `dashboard/`. `dashboard_2/` is self-contained.

## Definition of done

`dashboard_2/` is a standalone Atelier client: Le Noyau embodies the agent over an always-typeable composer; the selected vehicle stands on Le Plateau (at whatever degradation rung its asset allows) with health reading off its body and rim; the truth-panel and flat plan list keep every value legible in French at every rung and under reduced motion (hide the canvas → nothing lost); the four moments play (including the multi-tab live overdue wow); it speaks the real backend contract over REST + Mercure with the hidden-chat agent loop intact. **`dashboard_2` imports nothing from `dashboard/`, has its own `package.json`/`tsconfig`/tokens, and contains no Tier-2 code lifted from `dashboard/`** (contract-level type shapes and Tier-1 transport helpers may match the backend by necessity but are independently authored and declared in the PROVENANCE note). `DESIGN.md` is retired; `DESIGN-VISION.md` is the system.
