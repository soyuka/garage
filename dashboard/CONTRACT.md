# Atelier — the backend contract (distilled, build-against)

> Authored from the live backend + a field-by-field read of `dashboard/src/*.ts` and `api/src`, then
> restated in Atelier's own words. **Build against THIS doc + `src/types.ts`**, not against the old
> source. Re-open `dashboard/` only to verify a specific payload, never as a copy buffer. Verified
> against the running stack on 2026-06-29 (`GET /garage/vehicles` → JSON-LD `member[]`; hub `:` keep-alive).

## Origin & transport

Atelier is served **same-origin behind Caddy at `https://localhost`** (it is Caddy's `@frontend`
upstream — see `../api/frankenphp/Caddyfile` + `../api/compose.override.yaml`). Therefore:

- All REST + the hub are same-origin → **no CORS, no mixed-content**. Casdoor is NOT: it is the hosted
  `https://auth.bonneref.fr`, so its CORS must allow `https://localhost` (token POST is cross-origin).
- `VITE_API_BASE = https://localhost` is used for **both** fetch URLs **and** the absolute Mercure
  topic IRIs (the publisher builds topics from `DEFAULT_URI = https://localhost`, so a subscription
  string must equal that exactly).
- `VITE_MERCURE_URL = /.well-known/mercure` (relative; resolves against the page origin).

## Auth — Casdoor OAuth2 Authorization Code + PKCE (public client, no secret)

- **Login** = full-page redirect to `${ISSUER}/login/oauth/authorize` with `code_challenge` (S256),
  `state`, `redirect_uri = ${origin}/callback`, scope `openid profile email offline_access` (no
  `resource`: the API checks `aud` = the client id). Verifier + state + the hash route to return to
  (`#/…` only) in `sessionStorage` (single-use); one redirect per page however many callers ask.
  The tokens (access **JWT**, refresh, id) live in memory mirrored to `sessionStorage` — this tab,
  surviving a reload, not a browser restart.
- **Token exchange** is a `fetch()` POST straight to **`${ISSUER}/api/login/oauth/access_token`**
  (`https://auth.bonneref.fr`; no same-origin proxy any more). The token's `iss` is that issuer, which
  must equal the API's `CASDOOR_ISSUER`.
- **`authFetch`** attaches `Authorization: Bearer <jwt>` only to URLs on the API origin, and
  `garage.ts` refuses any IRI that is not a plain path (`apiUrl`). **Public `GET` reads need no
  Bearer**; `/agent/turn` + **all writes are gated**. The access token is refreshed a minute before
  expiry; a **401 gets one refresh + retry, then wipes the token and bounces to login** (re-auth,
  never silent-fail). **Logout** clears the tab's `sessionStorage` and goes to the issuer's
  `end_session_endpoint` (`/api/logout`, `id_token_hint`, `post_logout_redirect_uri = ${origin}/`). ISSUER `https://auth.bonneref.fr`, CLIENT_ID
  `671b8c86d75444f3f4c4` (default in `src/auth.ts`; the public PKCE app must exist on the hosted
  Casdoor), redirect URI `https://localhost/callback`.
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
| Agent link + capabilities | `GET /agent/link` | `{topic, capabilities:{agent, voice, dictation, web_search}}` (`types.ts` `Capabilities`), read once on mount by `fetchAgentLink`. A `false` is a provider with no key on the server: `agent` false → dock input disabled with the reason; `voice` false → « Voix » switch disabled with the reason, no `/tts` call; `dictation` false → no mic. Missing field or failed fetch → everything assumed on (the endpoints' 503s stay the fallback) |
| Speak a line | `POST /tts` `{text, voice?}` | same-origin, same bearer as other reads — **not** `authFetch` (a 401 must stay silent, never bounce to login); `Accept-Language` picks pronunciation; optional `voice` is a `SpeechVoice.id` from `GET /tts/voices`, omitted (never `null`) for the server default; a stale/foreign id is silently ignored server-side, falling back to default |
| Voice list | `GET /tts/voices` | same auth posture as `/tts`; returns `[{id, label}, …]` for the resolved `Accept-Language`, **first entry is the backend's default**, `[]` when voice is not configured; `Cache-Control: private, max-age=3600` + `Vary: Accept-Language` |
| Transcribe a line | `POST /stt` (raw audio body) | same auth posture as `/tts` — bearer, **not** `authFetch` (a 401 must stay silent, never bounce to login). Body is the recorder's blob as-is with its own `Content-Type` (`audio/webm;codecs=opus`), **not** multipart: one part, no filename, so `post_max_size` is the only limit to check. `Accept-Language` picks the transcription language. → `{text}`, the operator's words **verbatim** (no cleanup, no rephrase); `{text:''}` on silence. Empty or over-cap body → 400 with no backend call; backend refusing/unreachable → **503 empty**, never a 500 — the composer keeps what it had and the operator types |

**The opening line is deliberately NOT on this table.** It had a row here for a few hours on
2026-09-11 (`GET /agent/greeting?name=`, a model-written welcome); the endpoint was deleted the same day
and the line is drawn from the i18n catalogs in the browser (`noyau/greeting.ts`). Nothing is fetched, so there is nothing to contract.

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
  - `{type:'status', tool}` → a tool is running: step chip + Noyau pulse + the waiting bed fades; the
    narration line is **left alone**. Emitted by `StepNarrator` for its whitelist of tools only, with
    **no sentence** (owner decision 2026-11-09: the canned per-tool lines, « Je consulte le
    catalogue… », were noise; the ticker carries only what the model chose to say).
  - `{type:'status', message}` → the model's own `narrate_step` line (in-app or pilot): narration line
    + pulse + a chip classified from the prose (keyword fallback, French-only by nature). The server
    never sends both fields; the client still accepts both (a mock script, an older server).
  - `{type:'toast', tone, message}` → retained notice card (every tone) + narration (non-alarm)
    + **spoken aloud when the audition is on** — no new frame, no new field: the voice reads the card's
    own `message` verbatim, so the wire contract is UNCHANGED by the audition

## The REAL `UIAction` union (verified — the only members the agent tool surface emits)

`selectVehicle` (`.id` = `@id` IRI) · `revealAssembly` `{region, partKeys?}` ·
`showFact` `{keys[], title?, nodes?}` ·
`focusView` `{panel: "specs"|"tasks"|"carnet"|"parts"|"docs", keys?[]}` (raises one panel into the centre slot +
spotlights the car region for each key) · `proposeActions` `{headline, finding?, actions:
CtaAction[]}` · `showDocument` `{url, title, contentType, filename, page?, highlight?, pagesUrl?}` ·
`documentScan` `{vehicle, query, hits[]}` · `toast` `{tone, message}` · `status` `{tool}` | `{message}` · `turn` `{phase}`.
`parseFrame` drops any frame whose `type` is not one of these (and any malformed domain event)
before a reducer sees it. A domain event only updates a car already in the owner's listing: an
unknown `@id` triggers a re-read of `GET /garage/vehicles`, never an append from the frame.

**`documentScan` `{vehicle, query, hits: [{documentId, page, pageLabel, url, title, contentType, filename,
pagesUrl}]}` (2026-11-09)** — published by `SearchDocumentationProcessor` itself on every
`search_documentation` that resolves its vehicle (`ok` **and** `no_match`, then `hits: []`; nothing on
`invalid_input` / `not_found`), through `RenderIntentTools::documentScan()`, so the same `DashboardChannel`
routing as `showDocument` applies to both drivers and the model spends no call on it. `vehicle` is the
canonical `/garage/vehicles/{id}` IRI; `hits` are the ranked search hits, best first, at most 6, each
carrying what the viewer needs to open it without a lookup (`url`/`pagesUrl` as in `showDocument`).
The client drives that car in if another is on screen (a synthetic `selectVehicle`, then the scan is
held by the staging gate like any per-vehicle surface), opens Documents on the best hit with `query`
highlighted, and plays the scan (`workshop/docScan.ts`). A later `showDocument` settles it: the scan
stops and the viewer lands on the agent's pick.

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
body seam (`fluidVolumes.ts`), not a display value: litres straight, `null` for a mass, absent for an info-only
node. **2026-10-21:** its only producer (`fluids.ts`, the fill-volumes fetch) was removed; the field is kept for
wire-supplied `nodes`.

⚠️ **`renderSuggestions` and `patchPanel` DO NOT EXIST** (an early-sketch artifact). Never build
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
  **retained notice card** that stays until the operator dismisses it,
  and an **`alarm`**-tone toast additionally docks a `notify` item on the à-décider queue.
- **Narration line vs. step:** a tool-only `status` (`{tool}`) never touches the narration line;
  only `narrate_step` (`{message}`) and toasts write it.
- **Pulse granularity:** `status` frames fire only for a **whitelisted subset of tools** (`StepNarrator::TOOLS`)
  — **not one per tool-call**. Le Noyau pulses **per narrated step, sparsely**. Mock
  streams must fire `status` sparsely to match. ⚠️ This **supersedes the early vision text** ("Every
  tool-call fires one discrete pulse" / "visibly pulses four times") — the live contract narrates per
  step, not per tool-call. Build against the contract; the vision text on this point is stale.

## Choreography map (real vocabulary → Atelier motion)

`selectVehicle` → drive-forward · `showFact` → focus-panel / etch-in (stored facts OR projected
`nodes`) · `focusView {panel:'carnet'}` → carnet projection · `proposeActions` → CTA rises
from cradle (incl. `patchTask` deterministic path) · `toast` → retained notice card · `status` →
Noyau pulse · `documentScan` → Documents opens, the viewer scans the hits · `turn` → busy state.
