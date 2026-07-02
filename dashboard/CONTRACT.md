# Atelier — the backend contract (distilled, build-against)

> Authored from the live backend + a field-by-field read of `dashboard/src/*.ts` and `api/src`, then
> restated in Atelier's own words. **Build against THIS doc + `src/types.ts`**, not against the old
> source. Re-open `dashboard/` only to verify a specific payload, never as a copy buffer. Verified
> against the running stack on 2026-06-29 (`GET /garage/vehicles` → JSON-LD `member[]`; hub `:` keep-alive).

## Origin & transport

Atelier is served **same-origin behind Caddy at `https://localhost`** (it is Caddy's `@frontend`
upstream — see `../api/frankenphp/Caddyfile` + `../api/compose.override.yaml`). Therefore:

- All REST + the hub + Casdoor are same-origin → **no CORS, no mixed-content**.
- `VITE_API_BASE = https://localhost` is used for **both** fetch URLs **and** the absolute Mercure
  topic IRIs (the publisher builds topics from `DEFAULT_URI = https://localhost`, so a subscription
  string must equal that exactly).
- `VITE_MERCURE_URL = /.well-known/mercure` (relative; resolves against the page origin).

## Auth — Casdoor OAuth2 Authorization Code + PKCE (public client, no secret)

- **Login** = full-page redirect to `${ISSUER}/login/oauth/authorize` with `code_challenge` (S256),
  `state`, `redirect_uri = ${origin}/callback`. Verifier + state in `sessionStorage` (single-use);
  the resulting access token (a Casdoor-signed **JWT**) in `localStorage`.
- **Token exchange** is a `fetch()` POST to **`${origin}/casdoor/api/login/oauth/access_token`** — the
  same-origin Caddy proxy to `http://casdoor:8000` (a direct `fetch` to plain-http Casdoor from the
  https page is blocked as mixed content). The token's `iss` stays Casdoor's app.conf origin, so the
  API's issuer check is unaffected.
- **`authFetch`** attaches `Authorization: Bearer <jwt>` when a non-expired token exists. **Public
  `GET` reads need no Bearer**; `/agent/turn` + **all writes are gated**. A **401 wipes the token and
  bounces to login** (re-auth, never silent-fail). ISSUER `http://casdoor:8000`, CLIENT_ID
  `monparc-dashboard-0a1b2c3d`, redirect URI `https://localhost/callback` (already seeded).
- ⚠️ Reads working without a Bearer gives false confidence — gate-verify with a real **write** 2xx.

## REST (API Platform, `application/ld+json`)

Collections are hydra — read members from **`member` ?? `hydra:member`**. The vehicle handle is the
**`@id` IRI**. Subresource items often serve the IRI as `@id` **and** a bare ULID as `id`; normalize
`id := @id` for anything we PATCH (a bare ULID builds a broken `https://host<ulid>` URL).

| Resource | Verb · Path | Notes |
|---|---|---|
| Vehicles collection | `GET /garage/vehicles` | listing carries `nextDue`, `openTasks`; **omits** `health`, `facts`, `plannedTasks` |
| Vehicle item | `GET {iri}` | inlines `facts`, `health`, `plannedTasks` |
| Create vehicle | `POST /garage/vehicles` `{}` | blank vehicle |
| Edit vehicle | `PATCH {iri}` (merge-patch) | scalars: make/model/year/engine*/kType/vehicleType/plate/usageValue/usageUnit |
| Soft-delete vehicle | `DELETE {iri}` | drops off parc, history survives |
| Facts | `POST {iri}/facts` · `DELETE {iri}/facts/{key}` | source "user", unverified |
| Planned tasks | `GET/POST {iri}/planned-tasks` (`?includeDismissed`) | each task `@id`→`id` |
| Task mutate | `PATCH {taskIri}` (merge-patch) | `{status}` tick · `{parts}` merge (dedup by ref) · `{type,label,target,dueDate}` edit |
| Task parts subresource | `POST/PATCH/DELETE {taskIri}/parts[/{partId}]` | part lines by ULID; POST upserts by ref |
| Archive done tasks | `POST {iri}/planned-tasks/archive` | idempotent → `{archived:n}` |
| Forecast | `GET {iri}/forecast` | `{vehicle, due[], gaps[]}`, value objects (no `@id` norm) |
| Service schedule | `GET/POST {iri}/service-schedule` · `DELETE …/{ruleId}` | upsert by (type,action,component); POST 422 if no cadence — surface `detail` |
| Maintenance records | `GET/POST {iri}/maintenance-records` | newest first; each `@id`→`id` |
| Record mutate | `PATCH/DELETE /garage/maintenance-records/{id}` | PATCH body replaces the whole visit |
| Bill attachments | `POST {recordIri}/attachments` (multipart, **don't set Content-Type**) · `DELETE {url}` | owner-gated download → **fetch with Bearer + objectURL**, not `<img src>` |

Money is integer **minor units** (`costCents`, `priceCents`); the manual-form `price`/`cost` are
major-unit euros the client converts.

## Agent turn — async (202), reply arrives over Mercure

- `POST /agent/turn` body `{instruction, sessionId, selectedId?, summary?, pendingContext?}`
  (`summary` = compact on-screen state; `pendingContext` = the card being replied to; both optional,
  one bounded line — not a transcript). Acks **202**; the response has **no action list**.
- `POST /agent/turn/cancel` `{sessionId}` — cooperative; busy clears on the streamed `turn` end, not
  on this ack.
- **Hidden chat: never render a transcript.** The reply IS the state mutations below.

## Mercure — subscribe to exactly TWO absolute-IRI topics

1. **Collection topic** `${VITE_API_BASE}/garage/vehicles` — the publisher fans the *same* full-vehicle
   payload to item + collection, so the collection subscription **alone covers every vehicle**. Do
   **not** add per-item subscriptions.
2. **Session topic** `${VITE_API_BASE}/agent/sessions/<sessionId>`.

**Frame types** (told apart by `kind` vs `type`):

- **Domain event** (collection topic): `{kind:'vehicle', event:'created'|'updated'|'deleted',
  vehicle, iri, resource}` — `resource` is the full `VehicleSummary`; drives the live data cascade,
  **no follow-up fetch**.
- **Session frames** (session topic): the real `UIAction` union +
  - `{type:'turn', phase:'start'|'end'}` → busy
  - `{type:'status', message}` → narration line + Noyau pulse
  - `{type:'toast', tone, message}` → ember / toast

## The REAL `UIAction` union (verified — the only members the agent tool surface emits)

`setVehicles` · `selectVehicle` (`.id` = `@id` IRI) · `showFact` `{keys[], title?}` · `focusView`
`{panel: "specs"|"tasks", keys?[]}` (raises one panel into the centre slot + spotlights the car
region for each key) · `proposeActions` `{headline, finding?, actions: CtaAction[]}` · `toast`
`{tone, message}` · `status` `{message}` · `turn` `{phase}`.

`PanelKind` (`src/types.ts`) also has a third value, `'carnet'`, but `focus_view` only accepts
`specs`/`tasks` server-side — the carnet panel is raised client-only (see next paragraph).

⚠️ **`renderSuggestions` and `patchPanel` DO NOT EXIST** (a `docs/ui.md` sketch artifact). Never build
against them.

**Client-synthesized, not on the wire:** `showParts` `{service, taskId, parts?}` and `showHistory`
(the carnet projection) are never emitted by the agent — they're produced locally by the
deterministic wayfinder chips (`runLocalAnswer` in `Atelier.tsx`) so a chip click never costs a
turn. `focusRegion` `{region}` (earned-camera dolly) is likewise client-only today — the type is a
seed for a future server emission, and the client already dollies to the region a `focusView`/
`showFact` resolves to, so handling both is idempotent.

**`CtaAction` is dual-shape — exactly one payload:**
- `{label, instruction}` → re-runs the agent with that NL instruction (a fresh turn), **or**
- `{label, patchTask:{id, parts}}` → a **deterministic PATCH** merging parts onto a task, **NO agent
  turn**. Wire this path — skipping it is the known dead-button bug class.

## Routing rules that bite

- **Narration line** is fed by `status` frames **AND non-alarm `toast` frames**. Only **`alarm`-tone
  toasts** surface as real toast popups. Le Noyau's narration queue consumes both.
- **Pulse granularity:** `status` frames fire only for a **whitelisted subset of tools** (a step
  narrator) — **not one per tool-call**. Le Noyau pulses **per narrated step, sparsely**. Mock
  streams must fire `status` sparsely to match. ⚠️ This **supersedes DESIGN-VISION §1** ("Every
  tool-call fires one discrete pulse" / "visibly pulses four times") — the live contract narrates per
  step, not per tool-call. Build against the contract; the vision text on this point is stale.

## Choreography map (real vocabulary → Atelier motion) — frozen in Stage B.0

`selectVehicle` → drive-forward · `showFact` → focus-panel / etch-in · `proposeActions` → CTA rises
from cradle (incl. `patchTask` deterministic path) · `toast` → ember docks to Noyau · `status` →
Noyau pulse · `turn` → busy state.
