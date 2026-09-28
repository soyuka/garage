# PROVENANCE — what was lifted, what diverged

> **Rename note:** this app was built greenfield as `dashboard_2/` alongside the old `dashboard/`.
> Once parity was reached it was promoted to **`dashboard/`** (the old app removed — preserved in git
> history). References to `dashboard_2/` below are the historical build-time name; the old `dashboard/`
> they contrast against no longer exists on disk.

> Per IMPLEMENTATION-PROMPT HARD RULE 2: Tier-1 transport/plumbing MAY be re-authored near-verbatim
> (a faithful client of the same contract converges by necessity), but must live in new files with no
> import path back to `dashboard/`, and every lifted function must be declared here. Tier-2 (display /
> derivation / state logic) is rebuilt fresh, never lifted.

## Anti-reuse status (mechanical)

- `dashboard_2/` imports **nothing** from `../dashboard` or `dashboard/src` — verify:
  `grep -rn '\.\./dashboard\|dashboard/src' dashboard_2/src` → **zero**.
- Own `package.json`, own isolated `tsconfig` (`include: ["src","vite.config.ts"]`, no external path
  alias), own `.env`, own tokens (Stage B.0).

## Stage 0 — what exists so far

Only rails. **No Tier-1 transport has been authored yet** (no auth/agent/mercure/garage client) — those
land in Stage C and will be declared below as they are written. Stage-0 files:

- `src/types.ts` — **contract-level type shapes**. The field set matches the backend (and therefore
  `dashboard/src/types.ts`) by necessity, but the file is independently authored: Atelier's own
  grouping, comments, shared aliases (`Iri`, `Signal`, `UsageUnit`, `ServiceAction`), and it
  deliberately **excludes** the old Tier-2 `Vehicle`/`DashboardState` display models (rebuilt fresh
  from fixtures in Stage B). This is the allowed contract-shape convergence, not a lift.
- `src/router.ts` / `src/App.tsx` / `src/main.tsx` — Atelier-original; no analog in `dashboard/`.
- `vite.config.ts` — dev-env plumbing (Tier-1-adjacent). Structure converges with `dashboard/vite.config.ts`
  (host/HMR/`/vite-hmr`) because both ride the same Caddy `@frontend` mechanism; independently authored,
  no import path back.

## Tier-1 functions to re-author near-verbatim in later stages (declare on write)

| Stage | New file (planned) | Lifted-from (reference only) | Why it converges |
|---|---|---|---|
| C-0 | `src/auth.ts` | `dashboard/src/auth.ts` | PKCE math + `authFetch` 401-bounce encode the OAuth contract itself |
| C-0 | `src/agent.ts` | `dashboard/src/agent.ts` | `postTurn`/`cancelTurn` = the exact `/agent/turn` request shape |
| C-tail | `src/useMercure.ts` | `dashboard/src/useMercure.ts` | the `EventSource` topic-subscribe hook = the transport |
| C-1+ | `src/garage.ts` | `dashboard/src/garage.ts` | bare fetch verbs = the REST routes/payloads |

Everything Tier-2 those files touch (`vehicleFromSummary`, `computeStatus`, `state.ts` reducer,
`@id`-normalization *choices*, `formatReading`, `recency`, `forecast`/`health`/`schedule` derivation)
is **rebuilt fresh** from the vision/contract.

## Divergences from IMPLEMENTATION-PROMPT (decided, with reason)

1. **Dev-env = Caddy same-origin, not a Vite proxy.** The prompt's Stage 0 says "Vite proxy for
   `/.well-known/mercure` and the Casdoor paths." The repo owner authorized removing the old
   `dashboard` from docker and replacing it (2026-06-29), which unblocks the team's *current* proven
   mechanism: Atelier is Caddy's `@frontend` upstream, served same-origin at `https://localhost`. This
   is strictly better — no proxy, no CORS, no env split (the old conflation of fetch-base vs
   topic-base IRI disappears because both are same-origin), and the `https://localhost/callback`
   redirect URI is already seeded in Casdoor (Stage C needs no re-seed).
2. **`api/compose.override.yaml`** swapped the `dashboard` service → `dashboard_2` and
   `FRONTEND_UPSTREAM: dashboard_2:5173`. The old `dashboard/` **source stays on disk** (reference-only,
   never imported/modified); only the docker service that *served* it was replaced. The Caddyfile is
   untouched (its `@frontend` matcher is frontend-agnostic; only the env var changed).

## Stage B.2 (step 2 leaves + step 3 crossing) — all Tier-2, built fresh

The leaf components (`Hotspots`, `BodyGlow`, `RimGauge`, `CardinalReadouts`, `ParcLot`, `PartsShelf`),
the shared geometry seam (`anchors.ts`, `signalColors.ts`), the `crossing.ts` transform and the
`useReducedMotion` hook are **Tier-2 display/derivation logic, rebuilt fresh** from the vision/contract
— nothing lifted from `dashboard/`. Anti-reuse stays mechanically clean
(`grep -rn '\.\./dashboard\|dashboard/src' dashboard_2/src` → 0). Critique + fixes: `src/scene/REVIEW.md`.

## Stage C.1 — Auth (landed)

Tier-1 transport re-authored fresh per HARD RULE 2 (faithful OAuth-contract client; reference only =
`dashboard/src/auth.ts`, no import path back):

- **`src/auth.ts`** — Casdoor OAuth2 Authorization Code + PKCE (public client). Diverges from the
  reference by **splitting the security-bearing logic into pure, unit-tested functions**
  (`base64urlEncode`, `pkceChallenge` S256, `isTokenFresh` skew, `validateCallback` CSRF/state guard,
  `decodeJwtClaims`) under thin window/storage/fetch glue — the reference inlined these. The OAuth
  request shapes (authorize params, token-exchange body via the same-origin `/casdoor` proxy,
  `authFetch` Bearer + 401-bounce) converge by necessity. Storage keys (`mpa.auth.*`) match the
  contract namespace. Verified live 2026-06-29: real WRITE `POST /garage/vehicles` → **201** with the
  `Authorization: Bearer` header on the wire (owner = Casdoor `sub`).
- **`src/AuthGate.tsx`** (Tier-2, fresh) — `AuthBoot` (callback completion splash) + `RequireAuth`
  (signed-out bounce). No analog lifted; the reference `AuthGate.tsx` is a single combined gate.
- **`src/AuthPlayground.tsx`** (Tier-2, dev surface) — `#/auth` slice-1 gate-verify harness, Atelier-original.

## Stage C.2 — Garage reads (landed)

- **`src/garage.ts`** (Tier-1, fresh) — `getVehicles` / `getVehicle` / `getForecast` over `authFetch`,
  plus two unit-tested pure helpers: `hydraMembers` (`member ?? hydra:member`) and `normalizeIri`
  (`id := @id`, dropping `@id`). `getVehicle` normalizes only its inlined `plannedTasks` (the vehicle
  handle itself stays `@id`).

### Verified live collection shape (2026-06-29, admin owner, 6 vehicles)

`GET /garage/vehicles` member fields observed live (browser, Bearer):
`@id, @type, id, make, model, year, engineVariant, vehicleType, source, kType, plate, vin, factCount, facts, addedAt`
plus **`nextDue` ONLY on tracked vehicles** (MAZDA6, TORO had it; untracked ones omitted it). Divergences
from CONTRACT.md to note:
- `nextDue` is **omitted when null** (API Platform `skip_null_values`), not sent as `null` — so
  `ParcLot.parcSignal`'s `if (!n) return 'unknown'` is correct for untracked members.
- The collection **DOES carry `facts`** (factCount up to 26) — CONTRACT.md said "omits facts"; stale.
- `openTasks` was **absent on every member** (likely 0-omitted or not serialized) — do not rely on it
  for the parc signal (Atelier derives from `nextDue`, which is faithful).
- `health` correctly absent from the collection (item GET only) → the amber-by-usage gap below stands.
- Item `GET {iri}` adds `health`; `plannedTasks` omitted when empty. `GET {iri}/forecast` → 200 with
  `{vehicleId, vehicle, due[], gaps[]}` (an extra `vehicleId` alongside `vehicle`).

## Stage C.3 — Live data into surfaces (landed)

- **`src/scene/SceneView.tsx`** (Tier-2, fresh) — the PURE presentational scene (main region + dock),
  extracted from `AtelierScene`. Props-driven (bundle, rung, reduced, noyau, composer handlers,
  `composerExtras` slot). Both drivers render it: `AtelierScene` (now a thin fixture playground with the
  scenario/rung/crossing/mock-turn sims as `composerExtras`) and the live `Atelier`. The fixture
  playground's 6-scenario visual harness is preserved unchanged (verified pixel-faithful post-extract).
- **`src/Atelier.tsx`** (Tier-2, fresh) — the LIVE orchestrator: parc-first (Le Parc grid at `#/`,
  drive into the hero at `#/v/<seg>`, reload restores). Owns `getVehicles` + `getVehicleBundle` reads
  and Le Noyau's state; renders `ParcLot` or `SceneView`. RequireAuth-gated via `App`.
- **`src/router.ts`** — added `segForIri` / `hashForVehicle` / `vehicleSegFromHash` (the `#/v/<seg>`
  selection route; auth owns the pathname, selection owns the hash).
- **`src/App.tsx`** — root `#/` + `#/v/<seg>` → `<RequireAuth><Atelier/></RequireAuth>`; the dev
  playgrounds stay PUBLIC under their hashes; the dev index moved to `#/dev`.
- **`src/garage.ts`** — added `getMaintenanceRecords` (read) + `getVehicleBundle` (parallel item +
  forecast + records).

### Honesty/French fixes surfaced by live data (TDD'd)

- **Overdue axis (honesty §5).** Live `nextDue`/`ForecastDue` can be overdue on the CALENDAR while the
  usage axis still has distance left (positive `remaining`). `derive.dueDescriptor` and
  `ParcLot.horizonFor` were formatting that positive remaining as an overshoot ("DÉPASSÉ de 6 548 km"
  when 6 548 km REMAIN). Fixed both: prefer a negative usage remaining (km/h overshoot), else a
  negative day count, else bare "DÉPASSÉ". The live MAZDA now reads "DÉPASSÉ de 113 jours" for its
  time-overdue services and "DÉPASSÉ de 3 190 km" for its genuinely km-overdue one. Pinned in
  `derive.test.ts` + `ParcLot.test.ts` (horizonFor now exported).
- **`nextDue.label` is unreliable** — live values are the raw type (`"oil_change"`) or an English
  component (`"foam element"`), never a French display label (the fixture used "Vidange"). `ParcLot`
  now localizes via `serviceLabel(nextDue.type)`, matching the floor leaves (`ForecastDue` carries no
  label at all, so they already localized). The playground fixture still resolves to "Vidange" → no regression.
- **`labels.ts`** — live forecast keys differ from the fixture's: added `inspection` → "Contrôle
  technique" and `spark_plug` → "Bougies" (singular; the catalog uses singular keys).

### Slice C.3 adversarial review — fixes landed

4-lens review (contract/honesty/refactor-safety/anti-reuse): contract FAITHFUL, honesty correct, 0
blockers. Fixed: the one confirmed **major** — `Atelier` rendered a stale bundle under a new `#/v/<seg>`
on a car→car nav (back/forward, or a future Mercure/CTA `selectVehicle`); now gated on a
`heroBundle.vehicle['@id'] === selectedIri` match (verified live: MAZDA→TORO shows "Chargement", never
stale MAZDA). Plus: `dueDescriptor` usage-overshoot now also guards `unit` (parity with `horizonFor`);
error screen has a "← Le Parc" recovery path + clears on re-selection; `Atelier` imports `auth.css`
explicitly; `getForecast` falls back `vehicle ?? iri`.

## Stage C.4 — Mercure live cascade (landed)

- **`src/useMercure.ts`** (Tier-1, fresh) — the EventSource subscription. Pure core (unit-tested):
  `buildHubUrl` (repeated `?topic=`), `parseFrame` (null on `:` keep-alive), `isDomainEvent`
  (`kind` vs `type` discriminator), `upsertVehicle`/`removeVehicle` (collection reconciliation). The
  hook holds the handler in a ref so a changing callback identity does NOT re-open the stream — only a
  topic-set change re-subscribes (cleaner than the reference's exhaustive-deps disable). Diverges from
  the reference by extracting the reconciliation helpers as pure/tested functions.
- **`src/Atelier.tsx`** — subscribes to EXACTLY two absolute-IRI topics (collection
  `${API}/garage/vehicles` + session `${API}/agent/sessions/<sessionId>`; per-tab `sessionId`). Domain
  frames reconcile the collection (`upsert`/`remove`) and merge the resource into the open vehicle's
  bundle.vehicle — **no follow-up fetch** (`selectedIriRef` avoids the stale closure). Session frames
  route turn/status/toast → `useNoyau` (the agent posts onto the topic in slice 5).
- **Verified live (2026-06-29):** a `PATCH usageValue` on MAZDA re-rendered its parc card to "251 234 km"
  within ~300ms with **no reload** (the room is shared). Console clean; 159 tests; tsc clean; anti-reuse 0.
- **Deferred to the tail (per handoff):** forecast-bound deep-visual live refresh (rim/hotspots
  per-system need the forecast subresource, absent from the domain frame) + the §9 overdue crossing
  driven by a real domain frame (verify multi-tab).

## Stage C.5 — Agent turn (landed)

- **`src/agent.ts`** (Tier-1, fresh) — `postTurn` (POST `/agent/turn`, acks 202) + `cancelTurn` (POST
  `/agent/turn/cancel`) + a unit-tested pure `buildTurnBody` that pins the field names
  (`instruction/sessionId/selectedId?/summary?/pendingContext?`) and omits absent optionals. No action
  list in the response — the reply arrives as session frames.
- **`src/Atelier.tsx`** — the composer posts turns (`onSubmit` → `send`): optimistic `turn/start` (the
  worker is async), `summary` = a one-line on-screen-state, `selectedId` = the open IRI; a transport
  failure clears the optimistic working state + alarms. A ✕ cancel affordance shows while working.
  `mockStream` is NOT imported by the live path (retired there; the fixture playground keeps it).
- **Verified end-to-end (2026-06-29):** composer Enter → Noyau `working` + input cleared (browser);
  worker logs show `RunAgentTurn` received → `POST api.anthropic.com 200` (LLM ran) → `POST
  /.well-known/mercure 200` (session frames published) → handled; the Noyau then ran a full
  `working → done·vert → idle` lifecycle from the real session frames (browser). Intermediate `status`
  narration is wired + reducer-unit-tested but completes faster than a 200ms poll resolves; the agent's
  substantive `showFact`/`proposeActions` reply is intentionally ignored until the slice-6 CTA layer.
- **Dev-env note:** a burst of rapid edits wedged Vite HMR into a ~1s full-reload loop that churned the
  agent `sessionId` (frames orphaned); a `dashboard_2` container restart cleared it. See memory
  `vite-hmr-wedge-reload-loop`.

### Slices C.4+C.5 adversarial review — fixes + tracked gaps

3-lens review (contract/correctness/anti-reuse): contract FAITHFUL, anti-reuse CLEAN, 0 blockers.
Fixed the confirmed liveness **major**: an optimistic `turn/start` could hang Le Noyau in `working`
forever if the worker dies / a frame drops after the 202 (the cooperative cancel can't rescue a dead
worker). Added a **client watchdog** — armed on `turn/start`, re-armed by any session frame (a live
worker never trips it), cleared on `turn/end`; on 90s of silence it settles the orb + warns. Also
guarded **double-submit** (a second Enter while `working` is a no-op — one turn per session, ✕ cancels).

Tracked (NOT fixed here — deferred to the tail, consistent with the handoff slice/tail split):
- **Open-vehicle subresource staleness.** The domain frame's `resource` (`GarageVehicleView::toArray`)
  carries facts+health but OMITS `plannedTasks`/forecast/records (subresources), so after a
  checklist-changing write the open hero's établi/échéances show the last read until refreshed. The
  honest tail fix: on a domain frame for the SELECTED vehicle, re-fetch its bundle (forecast + records +
  tasks) — a follow-up fetch for the open car only; the parc cascade stays fetch-free. This subsumes the
  earlier "forecast-bound rim/hotspot live refresh" tail item.
- **Backend docstring defect (cross-stack).** `DomainPublisher.php` (and its echo in
  `LogMaintenanceProcessor`) claims the frame "ships the updated checklist", but `toArray` omits it
  (adding it would break a passing test). The docstring should be corrected backend-side, OR the client
  does the open-vehicle refetch above. Backend owner's call.

## Stage C.6 — UIAction choreography + CTA layer (landed)

- **`src/scene/CtaCard.tsx`** (Tier-2, fresh) — the CTA card raised by `proposeActions`: rises from Le
  Noyau's cradle (above the composer, never over it; `at-cta-rise` spring, `data-reduced` → instant).
  Cyan-FREE (rationed to the orb); the deterministic action reads brighter, decline is dimmed. Each
  action dual-shape: `instruction` → a fresh turn · `patchTask` → a deterministic parts merge, NO turn.
- **`src/Atelier.tsx`** — routes the reducer-state members: `selectVehicle` → drive-forward (hash to the
  car), `showFact` → `factFocus` (truth-panel etch), `proposeActions` → CTA, `setVehicles` → collection
  replace. `cta`/`factFocus` are turn-scoped (cleared on a new turn after `pendingContext` is captured,
  and on navigation). `send` now carries `pendingContext` (the CTA being replied to, one bounded line).
  `onCtaPatch` → `mergeTaskParts` (no turn) → re-reads the open bundle so the result shows.
- **`src/garage.ts`** — `mergeTaskParts` (PATCH `{taskIri}` merge-patch `{parts}`) + `getVehicleTasks`
  (planned-tasks subresource). `getVehicleBundle` now sources `plannedTasks` from the subresource and
  overrides the item-GET inline (see divergence below).
- **`TruthPanel`/`SceneView`** — `focusKeys` etches matching échéance rows (`at-truth-etch` light-sweep +
  persistent inset rail; reduced-motion → no sweep). Matches by service `type`; a fact-key focus that
  matches no row is a graceful no-op (the panel shows service types, not facts — a facts section is a
  possible follow-up).
- **Honesty fix:** the transport-failure / watchdog paths now toast BEFORE `turn/end`, so a failed turn
  settles RED/amber (worstTone), not a false green (caught live via the journal: "Failed to fetch" was
  settling "posé · tout est à jour VERT").

### Verified live (2026-06-29)

A real turn ("propose-moi d'ajouter les pièces de la vidange") → the agent emitted **`proposeActions`**
→ the CTA card rose with `Références pour la vidange` + part-add actions. Clicking "Ajouter les deux
pièces" → **`PATCH /garage/planned-tasks/01KVFEQV… [200]`** (correct normalized IRI, NO agent turn —
Noyau stayed idle) → bundle re-read → the établi now shows `MN7511-5 · MANNOL · 25,90 €` +
`J1313021 · HERTH+BUSS`. The dead-button bug class is fixed AND visible.

### Verified backend divergence — item-GET omits plannedTasks

`GET {iri}` inlines `plannedTasks: []` even when open tasks exist; the `/planned-tasks` SUBRESOURCE is
authoritative (returned 4 open tasks for MAZDA, all linked via `task.vehicle`). The bundle now reads the
subresource. CONTRACT.md says the item GET inlines plannedTasks — stale on this point; the subresource
is the source of truth. (Same class as the C.4 frame-omits-plannedTasks gap.)

## Stage C-tail (in progress)

### Open-vehicle live-refresh + §9 crossing (landed) — resolves the C.4/C.6 staleness gap

- **`Atelier.onFrame`** — a domain frame for the SELECTED vehicle now: (1) merges `resource` for the
  INSTANT health/scalar cascade, then (2) re-reads the open vehicle's bundle (forecast + records +
  tasks — the subresources the frame omits) so the rim/hotspots/établi/échéances update live. The parc
  cascade stays fetch-free. A `selectedIriRef` guard drops a refetch that lands after navigation.
- **§9 "service passant en rouge"** — `firstNewlyOverdue(prevDue, nextDue)` (pure, tested in
  `crossing.test.ts`) diffs the old vs refetched forecast; a service that crossed into overdue docks an
  ember + murmurs one concern line on Le Noyau (the existing `concern` event — NOT an agent turn), then
  auto-settles after 2s (guarded by `phaseRef` so it never cuts a live turn). The red bodywork rides the
  refetched bundle.
- **Verified live (2026-06-29):** `PATCH usageValue→320000` on MAZDA → échéances went 3→5 EN RETARD
  live (no reload) + Le Noyau murmured "Filtre à carburant en retard". tsc clean, 166 tests, anti-reuse 0.

### Agent debug journal (landed) — re-introduced per the owner's request ("the best debug feature")

- **`src/journal.ts`** (Tier-1, fresh) — `getTurns` (GET `/agent/turns`) + `getTurn` (GET `{iri}`) +
  the `TurnSummary`/`TurnDetail`/`JournalToolCall` types. Reuses garage's tested `hydraMembers`/`normalizeIri`.
- **`src/JournalPanel.tsx`** + **`src/journal.css`** (Tier-2, fresh, Atelier glass idiom) — a right-side
  drawer: turns newest-first (status chip · instruction · time · tool count · duration), a row expands
  lazily to its detail (prompt, model/tokens, LLM context, every tool call with args+result, web
  searches, final text, raw payloads). Escape closes. Reached via a 🪵 button in the top-right `Identity`
  chrome (parc + hero).
- **Verified live (2026-06-29):** 43 real turns listed; a detail showed `claude-haiku-4-5 (stream)`,
  `584 tokens (in 4/out 580)`, and the `propose_actions` tool call (the one that emitted the CTA).

## Stage-C parity port (old ./dashboard → Atelier) — IN PROGRESS

The owner asked for full feature parity with the old `./dashboard` (port what existed, nothing new,
minimal server change). Done: journal. Remaining transport+UI to port (Tier-1 verbs re-authored from
`dashboard/src/garage.ts`; Tier-2 UI rebuilt in the Atelier idiom): vehicle create/edit/soft-delete +
facts CRUD; planned-task tick/edit/dismiss/add + parts subresource CRUD + archive; maintenance-records
list/log/edit/delete + bill attachments (multipart upload, Bearer+objectURL download); service-schedule
GET/POST/DELETE. (The agent already does much of this via NL; parity adds the manual surfaces.)

### Tracked Stage-C backend gap (raised out of an inline comment, per the critique)

- **The parc collection needs a per-member maintenance signal.** `NextDue` (collection-only) carries
  `usageRemaining` but NOT the cadence interval, so the §2 *usage-10%* amber branch is uncomputable
  client-side: a vehicle amber by usage-only reads green on a parc card while its item view shows
  BIENTÔT (`ParcLot.parcSignal`). Overdue (red) + unknown are faithful. The honest fix is a
  server-provided collection signal (or the interval on `NextDue`), NOT a fabricated client threshold.

### #9 Vehicle CRUD + facts UI (landed) — all Tier-2, built fresh

The manual surfaces over the already-landed transport (`createVehicle`/`patchVehicle`/`deleteVehicle`/
`postVehicleFact`/`deleteVehicleFact`). Nothing lifted — the old `VehicleDetail`/`InlineEdit`/`facts.tsx`
were inventoried for parity (a parallel reader workflow) then rebuilt in the Atelier idiom:

- **`src/edit.ts`** (+ `edit.test.ts`) — pure coercion: `coerceInt` (strip non-digits; empty→null
  clears a nullable scalar), `emptyToNull` (blank text→null), `defaultUsageUnit` (groundscare→`h` else
  `km`), `VEHICLE_USAGE_UNITS`. TDD'd.
- **`src/safeHref.ts`** (+ `safeHref.test.ts`) — re-authored fresh (security primitive): only http(s)
  reaches a fact's citation `href` (an untrusted, agent/web-influenced url) — blocks `javascript:`/`data:`.
- **`src/labels.ts`** — added `factLabel` (known fact keys + the `{type}_ref` family + humanizing fallback).
- **`src/floor/InlineEdit.tsx`** — the Atelier click-to-edit primitive (button→input, commit on
  Enter/blur only when the trimmed draft changed, Escape resets, re-syncs on a live value change).
  Inherits the surrounding font/colour so each field reads as the text it replaces; dashed-underline
  hover affordance, **no cyan** (the edit cue is neutral chrome).
- **`src/floor/FactsSection.tsx`** — the facts/specs surface (always shown read-only; add-form +
  per-row remove/in-place-edit when the hero passes `edit`). Verified-source check, web-citation link
  via `safeHref`.
- **`src/floor/TruthPanel.tsx`** — gained an OPTIONAL `edit` prop. Absent → renders read-only exactly
  as before (the canvas-off floor + the fixture playground are unchanged — no regression). Present →
  identity scalars (plate/make/model/year/engineVariant/engineCode/kType) are inline-editable, the
  usage reading gets a value+unit editor, the facts section is interactive, and a discreet
  "Retirer du parc" soft-delete (native `window.confirm`) appears.
- **`src/scene/SceneView.tsx`** — threads the `edit` seam to both TruthPanel renders. Optional → the
  playground passes none.
- **`src/scene/ParcLot.tsx`** — an "+ Ajouter" dashed tile (`onAddVehicle`) → `createVehicle` → drive
  into the blank car's hero to fill it in. Neutral chrome (no health signal).
- **`src/Atelier.tsx`** — wires every verb with optimistic update + a bundle re-read for the
  subresources/forecast a write shifts; errors surface as an `alarm` toast on Le Noyau (never silent).
  `selectedIriRef` guards an async result that lands after navigation.

#### Verified backend divergence — TWO usageUnit enums (CRITICAL, handled)

The hours case is spelled differently per endpoint and a wrong spelling is rejected:
- **Vehicle merge-PATCH** (`PATCH {iri}`) binds **`App\Garage\UsageUnit` = `km|mi|h`** — hours is
  **`h`** (`api/src/Garage/UsageUnit.php`, `api/src/Entity/Vehicle.php:134`). So #9's usage-unit select
  and `defaultUsageUnit` emit `h`. (`api/src/Dto/UpdateVehicleInput.php:41` is the agent MCP DTO, also `h`.)
- **Maintenance + service-schedule DTOs** use **`App\Maintenance\UsageUnit` = `km|mi|hours`** — hours is
  **`hours`** (`api/src/Maintenance/UsageUnit.php`, `LogMaintenanceInput`/`MaintenanceRecordInput`/
  `EditMaintenanceInput`/`ServiceScheduleInput`). #11's usage selects must emit `hours`, NOT `h`.
- The strict `UsageUnit` union in `src/types.ts` (`km|mi|hours`) is the *maintenance* spelling; the
  vehicle scalar fields are typed plain `string`, so there is no collision. `edit.VEHICLE_USAGE_UNITS`
  is the garage spelling and is the source of truth for the #9 select.

#### Verified backend divergence — vehicle PATCH response omits facts (handled)

`PATCH {iri}` returns the vehicle read-model with **`facts:[]` / `factCount:0`** even when facts exist;
only the item **GET** inlines them (verified live 2026-06-29: a usage PATCH responded `facts:[]`, the
immediate GET re-read responded `facts:[{oil_type…}]`). Same class as the plannedTasks divergence. The
handler does NOT trust the PATCH response — it discards the return value and re-reads `getVehicleBundle`
(a GET) — so facts survive a scalar edit in the UI. (A `postVehicleFact` POST response DOES carry the
fresh facts, so the add path uses it directly.)

#### Slice #9 adversarial review (4-lens, refute-by-default) — fixes landed

contract-fidelity FAITHFUL · cyan ration CLEAN (zero cyan in any #9 surface) · French THIN/NBSP CLEAN ·
WCAG text-dim 5.62:1 on glass passes AA · every signal paired with a text tag. Two confirmed majors
(both independently verified) fixed:
- **`InlineEdit` value-sync stomped an in-flight draft** — `useEffect(setDraft, [value])` lacked an
  `editing` guard, so a concurrent same-field Mercure frame (another tab / the agent) would discard the
  user's typing mid-edit. Fixed: `if (!editing) setDraft(value)` + `[value, editing]` deps.
- **`onPatchVehicle` (+ the pre-existing `onCtaPatch`) wrote a stale bundle after fast nav** — the
  re-read guarded `selectedIriRef` only PRE-await, then `.then(setBundle)` bare; a re-read for vehicle
  A landing after a nav to B overwrote B → heroBundle @id-gate null → stuck "Chargement". Fixed: the
  post-await updater guard `setBundle((prev) => prev && prev.vehicle['@id'] === iri ? b : prev)`
  (matches the facts re-reads + `onFrame`). Refuted (not fixed): a blank-card aria-label nit — the name
  collapses to "NON SUIVI" (non-empty) and the backend defaults `make="Nouveau véhicule"`; a
  `'Véhicule sans nom'` fallback was added anyway as cheap polish.

### #10 Task-checklist CRUD UI (landed) — all Tier-2, built fresh

The interactive open-checklist + the "Planifier" hotspot bridge, over the already-landed transport
(`createTask`/`updateTask`/`patchTaskStatus`/`addTaskPart`/`updateTaskPart`/`removeTaskPart`/
`archiveTasks`/`getVehicleTasks`). The old `VehicleDetail` checklist was inventoried for parity then
rebuilt in the Atelier idiom:

- **`src/tasks.ts`** (+ `tasks.test.ts`) — pure derivations: `taskTitle`, `milestoneOf` (usage target →
  `À <reading>` · dueDate → `Avant le <date>` · else `Sans échéance`, French via format.ts), and
  `groupByMilestone` (bucket preserving backend order). TDD'd.
- **`src/labels.ts`** — added `SERVICE_TYPES` (the type datalist; free-text still allowed).
- **`src/scene/Checklist.tsx`** (+ `checklist.css`) — "Entretien en cours": milestone-grouped TaskRows
  (○/✓/↺ status toggle, ✎ inline `TaskEditForm` with the usage-wins-over-date toggle + a parts editor —
  `PartEditRow` commit-on-blur-when-changed, `AddPartRow` as its own form since nested forms are illegal,
  derived fluid lines read-only — and a × dismiss/restore), an `AddTaskForm`, an
  "Archiver les terminées (N)" action (shown only with done tasks), an open count, and the
  "Voir les tâches retirées" toggle. A live-hero glass panel; done = dimmed + struck, dismissed = faint;
  no cyan, no signal colour (a task is not a signal).
- **`src/scene/SceneView.tsx`** — threads an optional `checklist` prop into a new left column
  (`.at-scene-left`: l'établi/PartsShelf over the checklist); derives `onPlan` (a `ForecastDue` → a
  concrete task via the checklist's add path, pegged to the predicted-due reading in the GARAGE unit) and
  threads it to the Plateau. Absent → no checklist panel + no Planifier button (the playground unchanged).
- **`src/scene/Plateau.tsx` / `Hotspots.tsx`** — threaded `onPlan` to the hotspot card and wired the
  previously-dead "Planifier <service> →" button → `createTask`. Hidden when no `onPlan`.
- **`src/Atelier.tsx`** — checklist handlers via a `taskWrite(op, failMsg)` helper that null-guards the
  selection BEFORE the op fires (a thunk, not an eager call), then `afterTaskWrite` re-reads the bundle
  (guarded post-await — the domain frame omits plannedTasks) plus the includeDismissed list when the
  dismissed view is open. `showDismissed`/`dismissedTasks` state (reset on navigation), guarded by a
  `showDismissedRef`.

#### Verified live (2026-06-29, MAZDA — 4 open tasks)

Checklist rendered (milestone "À 250 000 km", 4 tasks with parts). Verified: tick done (PATCH status →
"3 ouvertes" + "Archiver les terminées (1)"), reopen (toggle back), dismiss (removed from the default
view), Planifier "Contrôle technique" hotspot → `POST /planned-tasks 201`
(`{type:"inspection",target:null,dueDate:"2027-03-21"}` — the time-axis dueDate path) → new milestone
"Avant le 21 mars 2027". Edit form renders the usage/date toggle (date disabled when a usage target is
set) + per-part edit rows + an add-part row. Console clean; tsc clean, 201 tests, anti-reuse 0.

### #11 Records + bills + service-schedule UI (landed) — all Tier-2, built fresh

A self-contained **"Dossier" right-side drawer** (mirrors the JournalPanel idiom — keeps the cinematic
hero uncluttered) with past-visit records (+ bills) and the recurring service-schedule. Opened via a 📁
chrome button in the hero `Identity` (vehicle-scoped; closed on navigation).

- **`src/records.ts`** (+ `records.test.ts`) — `parseEuros` (a euro string → MAJOR-unit number),
  `centsToEurosInput` (the read-model cents → an editable euro string for prefill), `visitTitle`,
  `maintUnitFor` + `MAINT_USAGE_UNITS`. TDD'd.
- **`src/schedule.ts`** (+ `schedule.test.ts`) — `actionVerb`, `ruleTitle`, `formatCadence` (French via
  format.ts; engine-hours shown as the short `h`). TDD'd.
- **`src/DossierPanel.tsx`** (+ `dossier.css`) — the drawer. RecordsSection (visit cards: title/date/
  reading/cost/performer/notes/items+parts/bill chips; ✎ edit · ✕ delete with confirm; "+ Entretien"
  form with dynamic service items + part lines; bill upload/view/delete) + ScheduleSection (rule rows:
  ruleTitle/cadence/source-badge/delete; row-click edits; "+ Règle" form; 422-no-cadence surfaces the
  RFC-7807 `detail`). Records ride the bundle (the parent re-reads via `onChanged`); the schedule is
  self-fetched on open. No cyan. Bills: Bearer→objectURL→`window.open`→revoke-after-60s download,
  multipart upload (no manual Content-Type), confirm-delete.
- **`src/Atelier.tsx`** — `dossierOpen` state + the 📁 button (hero only) + the drawer render with
  `onChanged` (guarded bundle re-read for records + forecast) + `onError` (alarm).

#### Verified backend divergence — maintenance money is euros-WRITE / cents-READ (CRITICAL, fixed)

This corrects the handoff's guidance ("the client converts → costCents/priceCents") AND a defect in the
landed transport. The maintenance-record **WRITE** shape takes MAJOR units (euros) — `cost` (and each
part's `price`) — and the BACKEND converts to the stored `costCents`/`priceCents`; the **READ** shape
returns cents. Verified against the backend's own functional tests (`GarageMaintenanceRecordTest`: writes
send `cost: 89.9` / `price: 12.5`, reads assert `costCents: 12050` / `priceCents: 1250`) and the
processors (`MaintenanceRecord{Create,Update}Processor` compute `round($data->cost * 100)`).
- The #8 transport batch had `MaintenanceRecordInput.costCents` + parts `priceCents` — a key that maps to
  NO DTO property, so API Platform silently dropped it (`ALLOW_EXTRA_ATTRIBUTES`): the cost never
  persisted on create, and the whole-visit PATCH WIPED cost+currency on edit. Caught live (a created
  record showed an empty cost) + by the #11 review (blocker). **Fixed**: `garage.ts` now sends `cost`/
  `price` (euros) via `MaintenanceRecordInput`/`MaintenanceRecordPart`; `DossierPanel` uses `parseEuros`;
  `centsToEurosInput` prefills from the read-model cents. Verified live: a record with `149,50 €` cost +
  a `12,50 €` part now persists and displays.
- **NOTE the planned-task parts subresource is DIFFERENT** — it stores `priceCents` directly
  (`PlannedMaintenance` entity); `TaskPartInput.priceCents` (#10) is correct and unchanged.
- **Record `usageUnit` is tolerant**: `MaintenanceRecordCreateProcessor` maps `'h' → 'hours'` then
  validates against `Maintenance\UsageUnit` (km|mi|hours), so BOTH spellings work; the form sends the
  maintenance spelling (`hours`) via `MAINT_USAGE_UNITS`. The service-schedule (`ServiceScheduleInput`)
  enforces `Assert\Choice(['km','mi','hours'])` — the form's `hours` is correct there too.

#### Slice #11 review (4-lens) — fixes landed

Two confirmed (both independently verified): the money blocker above (fixed), and a **major** — the
ScheduleSection self-fetch effect re-fired on every Atelier render because `onError` (the unmemoized
`alarm` closure) was a `load` dependency, spamming `GET /service-schedule` on every Mercure frame/Noyau
pulse while the drawer was open (+ no out-of-order guard). **Fixed**: `onError` is read through a ref so
`load`'s deps are `[vehicleIri]` (the effect fires only on open/vehicle change), plus a request-token
guard drops a stale/overlapping resolve. Verified live: dossier open → records (14) + schedule (10) load
once; schedule add `POST 201` + delete `204` with correct bodies; record create/delete + bill upload
`201`/delete all work; console clean. tsc clean, 229 tests, anti-reuse 0.

---

## Stage D — real Draco .glb vehicle meshes (blueprint overlay)

Replaces the procedural `CarProxy` boxes with real optimized glbs (pipeline: `theming/BLUEPRINT-PIPELINE.md`),
overlaying the same steel-blue `EdgesGeometry` look. All Tier-2 (fresh; the old `dashboard/` never had glb
code — nothing lifted).

- **`scene/vehicleMesh.ts`** — pure `resolveMesh(vehicle) → {url, edgeAngle, rotationY?}`. Make/model map
  (espace, mazda6) wins over a per-`VehicleType` `DEFAULT_URL` table; both spellings (`car/motorcycle`
  fixtures, `car/moto/cv/groundscare` API) normalize. `ROTATION_Y` per-asset yaw override table is **empty**
  — every shipped glb verified +x=front in-browser, none needed correction. 10 unit tests.
- **`scene/CarProxy.tsx`** — `bodyClassFor` rewritten to accept both spellings (fixed live bug: `moto`
  rendered as berline). `groundscare→utilitaire` (no dedicated mower body class — keeps `BodyClass`/`DIMS`/
  `SilhouetteCar`/anchors unchanged; precise mower proxy is a tracked follow-up). Exports `EDGE`/`FILL_OPACITY`.
  5 unit tests (tie `regionAnchors(bodyClassFor(t))` finite for every input).
- **`scene/VehicleMesh.tsx`** — `useGLTF` (decoder `/draco/`, all 5 preloaded) → `scene.clone(true)` →
  re-centre to origin+floor (per `viewer.html`) → faint blueprint fill (shared CarProxy recipe) + per-mesh
  `EdgesGeometry(geom, edgeAngle)` LineSegments (drei `<Edges>` is single-mesh; glb is multi). Disposes only
  per-instance edge geometries + the 2 materials we create (never the cached glb geometry).
- **`scene/VehicleBody.tsx`** — resolver + `<Suspense fallback={CarProxy}>` + a minimal `MeshErrorBoundary`
  class (first boundary in the app) catching `useGLTF`'s render-phase throw. CarProxy doubles as loading
  placeholder AND error fallback → never blank, never wrong-body flash. `key={spec.url}` resets a failed
  boundary on switch. Mounted at `Plateau.tsx:75` (replaces CarProxy; `BodyGlow`/`Hotspots` siblings unchanged).
- **`scene/Attribution.tsx`** — `Credits` chip in the `Identity` chrome → CC-BY panel for the 5 Sketchfab
  sources (model-page link where the full UID is known, title-search otherwise; `safeHref`). Espace author
  `tonielpro520` confirmed via the Sketchfab data API. 4 unit tests.
- **Assets**: 5 glbs + the three-0.169 DRACO decoder tree vendored to `dashboard/public/` (no CDN).

### Verified infra divergence (NOT an API/backend change)

`api/frankenphp/Caddyfile` `@frontend` matcher forwarded to Vite only on `Accept: text/html` navigations or
Vite-internal path prefixes — so `.glb`/`/draco/*` fetches (GLTFLoader sends `Accept: */*`) fell through to
PHP → 404. Added `'*.glb', '/draco/*'` to the matcher's `path()` branch so frontend static assets reach the
Vite dev server same-origin. (In prod the SPA ships these as built static assets.) Caddy caches upstream DNS:
after a `dashboard` container restart, restart `php` so Caddy re-resolves the new IP (else root 502).

Verified live (chrome-devtools, admin owner): Espace → make/model mesh; Peugeot 206 → mazda6 car default;
Toro Wheel Horse → groundscare mesh (small, real-scale) + hotspots render; all glb+`/draco/` 200, console
clean on the happy path. Fallback: a 404'd asset falls back to `CarProxy` (boxes), stage never blank — the
load-failure console errors are the genuine missing-asset signal, only on that path. Vehicle switch clean,
single canvas (no leak). Credits panel: 5 CC-BY lines + working links. tsc clean · 248 tests · anti-reuse 0.

---

## Stage D.2 — live blueprint thumbnails on Le Parc

Replaces the flat SVG `SilhouetteCar` on each parc card with a small spinning blueprint mesh, rendered
through ONE shared WebGL canvas via drei `<View>` (never N canvases — that blows the browser context cap).
Reuses Stage D end-to-end. All Tier-2 / browser-verified (R3F leaves aren't unit-tested; pure resolver is).

- **`vehicleMesh.ts`** — `MeshSpec` gains `thumbUrl` (pure `-web.glb`→`-thumb.glb`, falls back to `url`
  when no `-web` suffix). 3 new unit tests.
- **`blueprintBuild.ts`** (new) — the clone/recenter/per-mesh-edges/**dispose-safe** logic extracted from
  `VehicleMesh.tsx` so the hero leaf and the thumb share ONE copy (incl. the "never dispose cached glb
  geometry" rule). `VehicleMesh` now calls it. **Session edges cache** (keyed `url|edgeAngle`): the
  O(triangles) `EdgesGeometry` is computed once per mesh — the deterministic recentre lets the world
  transform be BAKED into the geometry, so it is shared read-only across remounts AND concurrent parc
  cards. Killed the parc↔hero navigation lag (was rebuilding 185k-tri edges on every mount).
- **`ParcThumb.tsx`** (new) — loads `spec.thumbUrl`, builds the blueprint, **normalizes each vehicle to a
  unit box centred at the origin** (unlike the hero, which keeps real-metre scale — a thumb should fill its
  own card) and spins via `useFrame`, gated by `active` (IntersectionObserver) and `reduced` (static, no
  spin) and tab visibility. Edges stay steel-blue `#a8c4dc` (signal is carried by the ring + tag).
- **`ParcLot.tsx`** — mounts one `<Canvas frameloop="always" …><View.Port/></Canvas>` (fixed, transparent,
  `pointer-events:none` so card-button clicks pass through), each card renders `<View className>` (drei
  HtmlView tracks its OWN div — do NOT pass `track` from outside a Canvas; that was the bug that left the
  grid blank) wrapping `MeshErrorBoundary`+`Suspense`(fallback `CarProxy`)+`ParcThumb`. Per-card
  `IntersectionObserver` + `visibilitychange` gating. WebGL-unavailable → whole grid degrades to the SVG.
- **Thumb assets** (`public/*-thumb.glb` ×5) — gltf-transform draco decimation; see BLUEPRINT-PIPELINE.

### Key gotchas (verified live)
- drei `<View>` OUTSIDE a Canvas = HtmlView: it renders + tracks its OWN div; the `track` prop is for the
  in-canvas variant. Pass `className`/`style` to the `<View>` and let it be the stage element.
- The shared canvas is `pointer-events:none` (clicks reach the card `<button>`) and one context for the
  whole grid (verified: 1 `<canvas>` for 6 cards). The `favicon.ico` 404 is pre-existing, not a thumb.
- `frameloop="always"` (not `demand` under reduced): demand risked a blank thumb (no frame after the async
  glb resolves); `reduced` only stops the spin (static re-render isn't motion).

### Hero progressive low→high reveal (landed)
- **`VehicleBody.ThumbThenFull`** — nested Suspense: the `-thumb.glb` renders first (inner fallback) while
  the full `-web.glb` streams in on demand, then swaps. Both go through VehicleMesh/buildBlueprint at real
  scale → in-place swap (edges densify, no jump). Collapses to one load when `thumbUrl === url`.
- **`VehicleMesh` now preloads the THUMBS, not the full glbs** — eager full-preload would decode the full
  mesh before the hero mounts, so the thumb stage would never show. Now: thumb instant (preloaded), full
  on-demand per vehicle. Lighter initial warm (~850 KB of thumbs vs ~7 MB of fulls) too.

Verified live (admin owner): each card shows its mesh (Espace→specific, Peugeots/Mazda→car default,
Toro→groundscare tractor), normalized + spinning over the signal ring; click → hero; leaving `#/` releases
the parc canvas. Hero progressive load verified in the network (5 thumbs preloaded → espace full glb
fetched on demand → dense full mesh swapped in). tsc clean · 251 tests · anti-reuse 0.

### Reveal part meshes — generic stand-ins (decided 2026-09-04, built 2026-09-05)

- **`assembly.ts`** — one table `PART_MESHES` (oil_filter/spark_plug/battery/brake_discs/engine → their
  generic mesh), `mesh?: PartMeshSpec` added to `AssemblyPart` (not `PartSpec` — `brake_discs` appears in
  two regions, kept as ONE table). `DISC_UPRIGHT` corrects the `disc` primitive to stand on the axle
  (`π/2` about x) after it was caught drawing flat since C3. New pure helpers: `partMeshUrl`,
  `schematicBox`/`meshFit` (the fit rule — longest axis of the mesh matched to the primitive's extent on
  THAT axis, corrected 2026-09-05 after a first draft matched largest-to-largest and stood the engine
  block above the roofline), `riseT`/`ASSEMBLY_RISE`/`RISE_FROM` (moved out of `Assembly.tsx` so both
  `Part` and the new `MeshPart` read one clock). `PartMeshSpec.rotationX` stands the spark plug up (it
  lies along z on disk; a plug stands in its socket). 35 tests in `assembly.test.ts` (was 13).
- **`Assembly.tsx`** — rewritten around ONE diagram clock (`clock`, a ref) owned by `Assembly` and read by
  every part via `riseT`, so a glb that resolves mid-rise (Suspense swapping the primitive out) joins the
  rise at the current instant instead of replaying it. `Part` (primitive) now clock-driven and draws the
  disc upright (`DISC_UPRIGHT` on the mesh). New `MeshPart` runs a part's generic glb through the SAME
  `buildBlueprint` pass as the shell (`edge`/`paint` = the highlight colour, so a highlighted part is
  operator-hued on edges, fill, fresnel AND scanlines — CHOREOGRAPHY "Truth before spectacle"), fits it
  into the primitive's `schematicBox` via `meshFit`, and drives its `uRise` from the same clock. New
  `PartSlot` picks `Part` or `MeshErrorBoundary`+`Suspense` around `MeshPart`, with the PRIMITIVE as both
  the loading and the error fallback — a slow or missing glb degrades to today's diagram, never to
  nothing. No `useGLTF.preload` for parts (the fetch starts with `MeshPart`'s first render; an earlier
  moment would be idle, which § Priority forbids).
- **`blueprintBuild.ts`** — new `edge` (line/fresnel/scanline hue override) and `edgeBoost` (HDR line
  boost override, default `EDGE_BOOST` = 1.7) options, plus `rotationX` (applied before the re-centre,
  alongside the existing `rotationY`) for the spark plug. First unit test file for this module
  (`blueprintBuild.test.ts`, 9 tests) — EdgesGeometry/Box3 are pure JS, so it is testable headless.
- **`holoFill.ts`** — `uRise` driver uniform (default 1, the shell never touches it) scales the whole
  fragment alpha, fresnel included — a `ShaderMaterial` has no `.opacity` to write, and scaling
  `uFillOpacity` alone left the rim glowing at full `FRESNEL_GAIN` from the first frame. `buildHoloFill`
  takes an `edge` hue (default `BLUEPRINT_EDGE`) so a highlighted part's fresnel/scanlines take the
  operator hue too. 4 new tests.
- **`VehicleMesh.tsx`** — `HoloRunner` exported so `MeshPart` can drive its own arrival flicker + scanline
  clock (no ScanRunner/PulseRunner/GhostRunner mounted on a part: those uniforms stay inert at 0 and a
  part is never ghosted — it is what appears as the shell ghosts).
- **`Attribution.tsx`** — +2 credits (Oil Filter — nodelet, CC-BY-NC; Old Engine — TraianDumbrava,
  CC-BY), `Credit.licence?` (absent = CC-BY) drives the panel's per-row suffix. spark-plug/brake-disc/
  battery stay uncredited (author not captured) — an invented author is worse than none; tracked as a
  `SOURCES.md` gap.
- **Assets** — the 5 files under `public/parts/` are all wired (`oil-filter`, `spark-plug`, `battery`,
  `brake-disc`, `engine-block`); `engine-v6.glb` refused (vehicle-specific detail no vehicle datum can
  select) and `spark-plug-worn.glb` refused (a claim about the operator's own plug); both removed from
  disk 2026-09-05, see `public/parts/SOURCES.md`.
- **`AtelierScene.tsx`** — `toggleReveal` takes `(region, keys)`; a second simulate button added
  (hood · oil filter + spark plugs) alongside the existing bay button. Dev harness only (`#/scene`).

Verified live 2026-09-05: `curl` confirmed `/parts/oil-filter.glb` already serves 200 through Caddy (no
`@frontend` matcher gap). Hood reveal (`#/scene`, admin owner, Chrome 1600×900): oil filter and spark
plug render as recognisable meshes in operator orange, edges bloomed; the spark plug stands upright
after wiring `rotationX` into `PART_MESHES.spark_plug` and `MeshPart`'s `buildBlueprint` call (a first
pass had the `rotationX` OPTION in `blueprintBuild.ts` but never wired it into the diagram table — caught
live, fixed, re-verified); the engine block renders inside the housing's former footprint, not above the
roofline. Bay reveal: battery renders as a detailed blue mesh, fuel filter stays its orange canister
primitive (the honesty line, visible side by side) — hue check (sampled pixels, `magick`) put both the
hood meshes' bloomed edge (~254,80,30) and the bay primitive's flat fill (~226,83,79) within the same
narrow red-orange hue band as `OPERATOR_HEX` (#ee4322) — no pink/white reading, so `PART_EDGE_BOOST`
stayed at 1.7. `PART_EDGE_ANGLE` stayed at 40° — the plug read as a small recognisable dart, not a smear
or hairball. Console clean apart from the pre-existing favicon.ico 404 and the pre-existing
`glBlitFramebuffer` depth/stencil warning; no GLTFLoader or shader-compile errors. Reloading the hero
(`#/v/01KVFEQA72XQMWSS3HPBD93ASR`) showed the unchanged rest state (edges, fill, no fade). The upright
`disc` primitive (`DISC_UPRIGHT`) has no simulate button on `frontAxle`/`rearAxle` in this harness, so it
was verified by the unit tests only (`schematicBox`/`meshFit`/`DISC_UPRIGHT` cases in `assembly.test.ts`),
not by a live screenshot.

tsc clean (whole project) · touched files only, never the full suite: `assembly.test.ts` 35,
`blueprintBuild.test.ts` (new) 9, `holoFill.test.ts` 7, `Attribution.test.ts` 6 — all passing.

---

## Peugeot 206 blueprint mesh (2026-09-28)

A second make/model specific vehicle mesh, and the first asset built without Blender. Source:
Sketchfab `Peugeot-206` by bimboit34, CC-BY-4.0, UID `2a41b3b90a2f464192787d7a5567c04b`, 172k
triangles, no textures, real scale already (3.91 m along its long axis).

- **`scene/vehicleMesh.ts`** — `specificUrl` gained a Peugeot 206 branch. It tests `\b206\b` against the
  model, so a 2008 or a 406 still falls back to the car default. `ROTATION_Y` is no longer empty. It
  carries `-Math.PI / 2` for this asset, because the source glb has its front at `+z`.
- **The facing convention is `-x`, not the `+x` that `anchors.ts:13` claims.** Measured in the shipped
  glbs: the Espace lies along x with its `r_glass` taillights at `+x`, and the Mazda6 lies along z with
  its `redglass` taillights at `-z` under a 180° x node rotation. Both therefore point nose left under
  the hero camera, and a new asset must match them. The anchors table stays `+x` based because it only
  drives the `CarProxy` primitive, so the two never met. Left as it is, and recorded here.
- **`scene/VehicleMesh.tsx` / `scene/ParcThumb.tsx`** — the thumb preload lists gained the new file.
- **`scene/Attribution.tsx`** — an eighth CC-BY credit. The test count moves from 7 to 8 sources.
- **Assets** — `public/peugeot-206-web.glb` (271 KB) and `public/peugeot-206-thumb.glb` (116 KB).

### The build, and the gotcha that cost the most time

The source is a clean glTF, so the Blender leg of `theming/BLUEPRINT-PIPELINE.md` was not needed. The
asset was built with `@gltf-transform/cli` alone. Two deltas from the thumb recipe in that document:

1. **Bake every node transform into the vertex data first** (`theming/bake-flat.py`). The same script
   drops primitives by material name and drops non-triangle primitives, which a `LineSegments` in the
   source would otherwise render as stray lines (`blueprintBuild.ts` only walks `isMesh`).
2. Then run `optimize --compress draco --simplify true --simplify-ratio 0.75 --simplify-error 0.005
   --texture-compress false`, and the same command at ratio 0.2 on the result for the thumb.

Step 1 is not cosmetic. Without it, `optimize` leaves several unmerged nodes whose transforms it cannot
flatten, one of them below the floor. `buildBlueprint` then recentres on `box.min.y`
(`blueprintBuild.ts:132`) and the whole car floats a body height above the plateau. Passing
`--instance false` does not fix it. Verified twice in the browser, once floating and once seated.

Detail was cut because the wireframe reads through the body. 41% of the source triangles are the brake
discs and calipers behind the rims, which no viewer can name and which turn the wheels into noise. The
materials `disk`, `Matte__FFB50000__env_50_spec` and `Matte__FF20A3FF__env_5_spec` are dropped at step 1.
The interior stays, so the 206 reads like the Espace and the Mazda6 next to it.

An edge threshold of 45° was tried and rejected. It removes the door and roof lines before it removes the
wheel clutter, so this asset keeps the car default of 30°.

Verified live 2026-09-28 (Chrome, admin owner, `#/v/01M3KFPM06Y3EGGPQGMCQ1NAPJ`): the 206 resolves to its
own mesh, sits on the plateau, and points nose left like the Mazda6 and the Espace. The parc grid card
shows the same silhouette from the thumb. tsc clean. Touched files only, never the full suite:
`vehicleMesh.test.ts` 15, `Attribution.test.ts` 6, all passing.
