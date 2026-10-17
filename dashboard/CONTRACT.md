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
| Speak a line | `POST /tts` `{text, voice?}` | same-origin, same bearer as other reads — **not** `authFetch` (a 401 must stay silent, never bounce to login); `Accept-Language` picks pronunciation; optional `voice` is a `SpeechVoice.id` from `GET /tts/voices`, omitted (never `null`) for the server default; a stale/foreign id is silently ignored server-side, falling back to default |
| Voice list | `GET /tts/voices` | same auth posture as `/tts`; returns `[{id, label}, …]` for the resolved `Accept-Language`, **first entry is the backend's default**; `Cache-Control: private, max-age=3600` + `Vary: Accept-Language` |
| Transcribe a line | `POST /stt` (raw audio body) | same auth posture as `/tts` — bearer, **not** `authFetch` (a 401 must stay silent, never bounce to login). Body is the recorder's blob as-is with its own `Content-Type` (`audio/webm;codecs=opus`), **not** multipart: one part, no filename, so `post_max_size` is the only limit to check. `Accept-Language` picks the transcription language. → `{text}`, the operator's words **verbatim** (no cleanup, no rephrase); `{text:''}` on silence. Empty or over-cap body → 400 with no backend call; backend refusing/unreachable → **503 empty**, never a 500 — the composer keeps what it had and the operator types |

**The opening line is deliberately NOT on this table.** It had a row here for a few hours on
2026-09-11 (`GET /agent/greeting?name=`, a model-written welcome); the endpoint was deleted the same day
and the line is drawn from the i18n catalogs in the browser (`noyau/greeting.ts`, CHOREOGRAPHY § "The
opening"). Nothing is fetched, so there is nothing to contract.

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
2. **Session topic** `${VITE_API_BASE}/agent/sessions/<sessionId>`. The id is the **tab's**, held in
   `sessionStorage` (`mpa.session`, since 2026-09-05) — a reload rejoins the same topic, so a turn that
   is queued or running when the page reloads still lands. Two tabs are two sessions; a *duplicated* tab
   inherits the storage and therefore the topic (both then render the same turns).

**Frame types** (told apart by `kind` vs `type`):

- **Domain event** (collection topic): `{kind:'vehicle', event:'created'|'updated'|'deleted',
  vehicle, iri, resource}` — `resource` is the full `VehicleSummary`; drives the live data cascade,
  **no follow-up fetch**.
- **Session frames** (session topic): the real `UIAction` union +
  - `{type:'turn', phase:'start'|'end', outcome?:'failed'}` → busy (`outcome` only on a turn that died server-side)
  - `{type:'status', message, tool?}` → narration line + Noyau pulse. `tool` is the server's own tool
    key (`StepNarrator` is keyed by it) and is what the step chip is derived from; `message` is prose.
    Optional: a pilot's `narrate_step` has no tool to name, and a server predating the field still
    renders — the client then keyword-matches the French sentence, which is a FALLBACK, not the
    contract (it was the contract until 2026-08-16, and it made the chips hostage to the wording)
  - `{type:'toast', tone, message}` → retained notice card (every tone) + narration (non-alarm) / ember (alarm)
    + **spoken aloud when the audition is on** — no new frame, no new field: the voice reads the card's
    own `message` verbatim, so the wire contract is UNCHANGED by the audition (CHOREOGRAPHY
    § "Audition — speech, for ANSWERS")

## The REAL `UIAction` union (verified — the only members the agent tool surface emits)

`setVehicles` · `selectVehicle` (`.id` = `@id` IRI) · `revealAssembly` `{region, partKeys?}` ·
`showFact` `{keys[], title?, nodes?}` ·
`focusView` `{panel: "specs"|"tasks"|"carnet"|"parts"|"docs", keys?[]}` (raises one panel into the centre slot +
spotlights the car region for each key) · `proposeActions` `{headline, finding?, actions:
CtaAction[]}` · `showDocument` `{url, title, contentType, filename, page?, highlight?, pagesUrl?}` ·
`toast` `{tone, message}` · `status` `{message, tool?}` · `turn` `{phase}`.

**`showDocument` `{url, title, contentType, filename, page?, highlight?, pagesUrl?}` (2026-10-05)** — opens the
in-Atelier **document viewer** on one vehicle document. A REAL server emission: the MCP tool
`show_document` (`api/src/ApiResource/Dashboard.php`, `ShowDocumentProcessor`) takes `vehicle` (IRI) +
`document` (the bare ULID from `list_documents` / a `search_documentation` hit) + optional `page` +
`highlight`, resolves the document **owner-scoped**, checks `1 ≤ page ≤ pageCount`, and publishes the
frame with `url`/`title`/`contentType`/`filename` taken from `VehicleDocumentView` — the agent never
types a URL. A miss (`not_found`) or a page out of range (`invalid_input`) is a tool RESULT and
publishes nothing. Unlike the other render intents it is NOT on `McpToolbox::EXCLUDED` and has no
`#[AsTool]` twin: it needs the domain to resolve, so — like `open_vehicle` — the single MCP tool serves
both drivers; inside an in-app turn `DashboardChannel` routes its frame to the bound session topic,
outside one to the pilot topic.
- `url` is the owner-gated download path (`/garage/vehicles/{id}/documents/{documentId}/file`, or a
  bill's attachment IRI when the operator opens a bill) — fetched with the Bearer (`authFetch`), never
  handed to the browser as a bare href.
- `page` is the **1-based physical page** (the `page` of a search hit), not the manual's printed label.
- `pagesUrl` (optional) is the owner-gated labels endpoint (`/garage/vehicles/{id}/documents/{documentId}/pages`,
  `[{number, label|null}]`, no page text) from `VehicleDocumentView::pagesUrl()`. The viewer fetches it once,
  non-blocking (failure = no labels), shows the current page's manual label after « p. N / total » and lets
  the page field jump to a label (`23A-19`) as well as a number. Bills carry none: label absent.
- `highlight` is free text (typically the search query): the client folds it exactly like
  `DocumentationSearcher` (NFD, strip marks, lowercase; split on non-letter/digit; drop the same French
  stopwords; keep terms ≥ 2 chars) and marks every occurrence in the PDF text layer.
- It PLACES a per-vehicle surface, so the pilot staging gate holds it while a car drives in, and
  navigating to another vehicle closes it.
- The same surface is opened locally (no frame) by « Ouvrir » in the documentation panel and by a bill
  chip in Le Dossier.

**`revealAssembly` `{region, partKeys?}` (2026-08-16)** — a REAL server emission (`reveal_assembly`,
`api/src/ApiResource/Dashboard.php` + the `#[AsTool]` twin, so both drivers reach it). It ghosts the
car's shell to a wireframe and raises that region's schematic assembly under it, camera in the reveal
pose. `region` is a `BodyRegion`, and the enum is duplicated **once** server-side as
`RenderIntentTools::BODY_REGIONS` — a region absent there is one the agent has no word for.

`partKeys` **call out** parts of the diagram; they never filter it. The whole circuit is always drawn,
because "where does this part sit" is answered by what surrounds it. Omitted `partKeys` is absent from
the wire, not `[]` — "call nothing out" and "call out nothing" must not look the same.

The diagram is **authored, not extracted**: `public/mazda6-gh-web.glb` is one node and one mesh, so
there is no engine to reveal. It is a diagram of that class of assembly and the tool description says
so — the agent must never present it as a scan of the user's specific car.

`focusView` covers the whole `PanelKind` (`src/types.ts`), `'carnet'` included: the history/spend
projection is a real server emission, not a chip-only surface. The agent raises it after
`get_maintenance_history` instead of answering « quand ai-je fait la vidange ? » in prose.

**`showFact.nodes` — a FETCHED surface on the wire.** `keys` filters the vehicle's STORED facts;
`nodes` (`SpecNode[]` = `{key, label, value, verified, info?}`) ARE the panel content, bypassing that
filter, so a tool can project what it just looked up — fill volumes, an oil grade, the SIV carte
grise — none of which is a fact on the vehicle. Given both, `nodes` win the content and `keys` keep
only their car-region spotlight. `value` arrives display-ready (the server formats it; the panel
renders it verbatim) and a node surface is read-only — a fetched value is not the operator's to edit.
Without `nodes`, `show_fact(['oil_capacity'])` after a successful lookup renders an EMPTY panel.

`SpecNode` also carries an optional `volumeLitres?: number | null` (2026-09-05) — the fluid station's
body seam (`fluidVolumes.ts`), not a display value: `fluids.ts` sets it from the raw `FillVolume`
volume/unit (litres straight, `ml` divided down, `null` for a mass), absent for an info-only node.

⚠️ **`renderSuggestions` and `patchPanel` DO NOT EXIST** (a `docs/ui.md` sketch artifact). Never build
against them.

**Client-synthesized, not on the wire:** `showParts` `{service, taskId, parts?}` and `showHistory`
are never emitted by the agent — they're produced locally by the deterministic wayfinder chips
(`runLocalAnswer` in `Atelier.tsx`) so a chip click never costs a turn. `showHistory` stays
client-only as a *frame*, but its SURFACE no longer is: the agent reaches the same carnet projection
with `focusView {panel:'carnet'}`, which both paths funnel into one focus state through
`focusForAction`. `focusRegion` `{region}` (earned-camera dolly) is likewise client-only today — the type is a
seed for a future server emission, and the client already dollies to the region a `focusView`/
`showFact` resolves to, so handling both is idempotent.

`PanelKind` also gained **`parts`** (2026-08-16) — the shopping list across the open checklist, a REAL
server emission (`focus_view` accepts it) plus a « Pièces à commander » wayfinder chip. It exists because
`PartsManifestModel` carries one `taskId`, so « prochaines pièces à commander ? » — a question spanning the
checklist — had no surface: asked live, the agent showed one freshly-fetched ref while the seven already on
three open tasks never appeared.

`PanelKind` gained a fourth value, **`visits`** (2026-08-15) — the visit ladder the rail's « Plus tard · n »
control projects. Client-synthesized: `focus_view` accepts `specs`/`tasks`/`carnet` server-side and the
agent never emits it. Exactly the status `carnet` held until the same day, so if a tool ever needs to
project the ladder, the server enum is the only thing in the way.

**`CtaAction` is dual-shape — exactly one payload:**
- `{label, instruction}` → re-runs the agent with that NL instruction (a fresh turn), **or**
- `{label, patchTask:{id, parts}}` → a **deterministic PATCH** merging parts onto a task, **NO agent
  turn**. Wire this path — skipping it is the known dead-button bug class.

## Routing rules that bite

- **Narration line** is fed by `status` frames **AND non-alarm `toast` frames** — it is a HEARTBEAT: the
  next `status` overwrites it and `settle` clears it. **Every** toast, alarm or not, additionally raises a
  **retained notice card** that stays until the operator dismisses it (CHOREOGRAPHY § "The notice stack"),
  and an **`alarm`**-tone toast additionally docks a `notify` item on the à-décider queue.
- **Pulse granularity:** `status` frames fire only for a **whitelisted subset of tools** (a step
  narrator) — **not one per tool-call**. Le Noyau pulses **per narrated step, sparsely**. Mock
  streams must fire `status` sparsely to match. ⚠️ This **supersedes DESIGN-VISION §1** ("Every
  tool-call fires one discrete pulse" / "visibly pulses four times") — the live contract narrates per
  step, not per tool-call. Build against the contract; the vision text on this point is stale.

## Choreography map (real vocabulary → Atelier motion) — see CHOREOGRAPHY.md, which is revisable

`selectVehicle` → drive-forward · `showFact` → focus-panel / etch-in (stored facts OR projected
`nodes`) · `focusView {panel:'carnet'}` → carnet projection · `proposeActions` → CTA rises
from cradle (incl. `patchTask` deterministic path) · `toast` → retained notice card (+ ember on alarm) · `status` →
Noyau pulse · `turn` → busy state.
