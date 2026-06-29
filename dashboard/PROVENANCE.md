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
