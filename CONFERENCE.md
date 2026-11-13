# CONFERENCE — driving the Atelier live, from voice, on a projector

Plan for the API Platform talk. Not committed work; this is the roadmap a session picks slices from.
For the mechanism it builds on, read `docs/pilot.md` first — it is short and it is ground truth.

---

## The act

Laptop → videoprojector shows the Atelier at `#/v/<mazda>`. The presenter speaks to Claude (Claude
Desktop / mobile voice, holding this repo's MCP server as a remote connector). Nothing is typed, no
terminal is on screen, no devtools. Three beats:

1. **« Sur ma Mazda, qu'est-ce que j'ai à faire ? »** — the car drives in, the échéance rail unrolls,
   the soonest item is pinned on the body and the camera earns its way there.
2. **« Quelle pièce je commande ? »** — the reveal: the shell ghosts out, the assembly under it rises
   as a schematic, the part is pinned inside it with its real catalogue reference, brand and price.
   A CTA writes those refs back onto the open task.
3. **« Note que je l'ai fait. »** — a write. The health ring recomputes, the forecast shifts, the task
   closes itself and leaves the rail. Live, in front of the room.

Then the pivot: *maybe* this is the future of applications — but the API is the invariant either way.
That is the talk.

## The one rule that governs all of it

The rule is **no theatre**: a staged 320/950/1900 ms beat
sheet was deleted on 2026-08-14 (`0b7fdb5`) because it narrated at the user.

That rule is not repealed here. Read it precisely: **it bans padded *time*, not spatial drama.** A
reveal that shows you the inside of the car is information a still screenshot loses — exactly what the
motion layer is for. So:

- Motion may **overlap** an arrival. It may never **gate** one. If the panel can open now, it opens
  now and animates while open.
- No `sleep` anywhere in the render path, no artificial step pacing, no "thinking…" that isn't.

If a beat only works because something was slowed down, the beat is wrong and gets cut.

## The act in English — the 90 s hackathon video (added 2026-11-02)

Same three beats, same rule, for an international jury and against the **public deployment**, not
the laptop: MCP `https://garage.bonneref.fr/mcp`, Atelier `https://garage.bonneref.fr/?lang=en`, the
`demo` account (`docs/deploy-garage.md` § Demo account). Launch: `stage/bin/atelier --public --lang en`
(Claude Code, `Atelier EN` output style). Recording checklist: `docs/showcase.md` § 5.

Kept here rather than in a `CONFERENCE.en.md`: this file is a plan, not a translation, and "the one
rule" above must have exactly one copy. A second file would carry either a stale duplicate of it or
a name promising a translation of 300 lines of French-talk workstreams it does not contain.

**Ground truth the prompts rest on** — read on 2026-11-02 from the owner's garage, which the demo
garage was copied from on 2026-10-31. Re-read it on the demo account before a take (checklist):

| | Mazda6 2007 (GG, petrol), 252 524 km |
| --- | --- |
| open planned task | **one**: `fuel_filter`, target 258 896 km — 6 372 km left, forecast lands it 2027-04-30 |
| forecast, soonest by date | `inspection` 2027-03-21 (no planned task), then the fuel filter, then oil change + oil filter at 270 347 km / 2027-07-06 |
| catalogue, fuel filter (kType 19107) | every tier-1 line is a **placeholder** (`Life-Time Filter`, `LIFETIME FUEL`, `LFT`). The last one fitted is logged as `BOLK fuel filter`, no part number |
| catalogue, oil filter | real refs: MANN-FILTER W 712/73, MAHLE OC 1182, PURFLUX LS357, BOSCH 0 451 103 363 |

The other four (Espace IV: clutch task with LuK refs; 307: inner tie rod; 206: nothing open; Wheel
Horse 246-H: front tyres) are not in the video — the 3D mesh is the Mazda6.

| t | the presenter says | what the screen does | tool chain |
| --- | --- | --- | --- |
| 0–8 s | *(title, one line of voice-over: "My garage is an MCP server. Claude drives the workshop.")* | Atelier at rest, orb in veille | — |
| 8–30 s | **"What's due on the Mazda?"** | the Mazda drives in, the rail unrolls, the fuel filter row is pinned on the bay, voice: "Fuel filter in 6,372 km" | `narrate_step` → `open_vehicle('mazda')` → `focus_view('tasks', ['fuel_filter'])` → `settle_dashboard` |
| 30–58 s | **"Which part do I order?"** | the shell ghosts, the bay assembly rises, the part is called out; the card carries brand + reference (+ price when the catalogue has one); the button is clicked and the refs land on the task | `narrate_step` → `get_service_parts` / `list_articles` → burst `reveal_assembly('bay', ['fuel_filter'])` + `propose_actions` (`taskId` + `parts`) → `settle_dashboard` |
| 58–80 s | **"I've just replaced it. Log it."** | health ring recomputes, the task strikes and leaves the rail, the forecast moves to 292 524 km | `narrate_step` → `log_maintenance` (one visit, `items[{type:'fuel_filter', parts}]`, today, 252 524 km) → `settle_dashboard` |
| 80–90 s | *(voice-over: "Three tools, one API. The UI is just another client.")* | the answer stays on screen | — |

**Beat 2 is not safe as written** — the catalogue's own answer for this car's fuel filter is "lifetime,
no service part", and the protocol now forbids putting a placeholder on a button. Two honest ways out,
the owner picks:

- **A. Keep the fuel filter.** Check live, as `demo`, that `list_articles` on the fuel filter category
  (`categoryId` 100261) returns a real, fitting reference past the placeholders. If it does, the act
  stands as above. If it does not, A is dead: do not invent a ref for the camera.
- **B. Re-seed the demo with an oil-filter task.** `plan_maintenance` on the Mazda, `oil_filter`,
  target 270 347 km, as `demo`; then `~/bonneref/bin/demo-reset.sh --snapshot` so nightly resets keep
  it. Beat 2 becomes "Which oil filter do I order?" (real MANN / MAHLE / PURFLUX refs, region
  `hood`), beat 3 "I've done the oil change, log it" (`items[{oil_change}, {oil_filter, parts}]`,
  which closes that task). Beat 1 then spotlights both tasks. Cost: the seed is no longer a pure copy
  of the owner's garage.

Cuts between beats are editing, not theatre: dead air while Claude reasons can be cut **between**
beats. Inside a beat, nothing is sped up or slowed down (§ the one rule).

---

## Workstream A — the transport (highest risk, therefore first)

> **2026-10-18 — auth moved off this stack.** Casdoor is no longer local: the issuer is the hosted
> `https://auth.bonneref.fr` (discovery + DCR verified live). There is one public host
> (`garage.soyuka.me`), no `auth.soyuka.me`, no `docker/casdoor/*`, no `/casdoor/*` Caddy proxy and no
> `stage/bin/mint-token`; the stage Claude session does native MCP OAuth. Items below describing the
> local Casdoor (dev `admin/123`, origin in `app.conf`, the empty-`cert` DCR bug) are the history of that
> setup and no longer apply; whether the hosted instance's DCR clients sign JWTs correctly is unverified.

Voice comes from Claude Desktop / mobile. That makes the MCP server a **remote connector**, not a
localhost one, and rewrites the auth story. Good news: most of it already exists.

**Already shipped, do not rebuild:**

- RFC 9728 protected-resource metadata — `src/Controller/Mcp/ProtectedResourceMetadataController.php:28`
  serves `/.well-known/oauth-protected-resource`, allowed anonymously in `config/packages/security.yaml:15`.
- The 401 challenge — `src/EventListener/McpProtectedResourceChallengeListener.php:38` attaches the
  `WWW-Authenticate` header pointing at that metadata.
- Casdoor was chosen *precisely* for this scenario: anonymous RFC 7591 DCR at `/api/oauth/register`,
  advertised in discovery, wildcard loopback redirects. Claude Desktop self-registers with no manual
  client creation.

**What is missing:**

1. **A publicly-trusted origin.** Caddy currently serves a self-signed localhost cert; Claude Desktop
   will not touch it. **Decided: a named tunnel from the presenter laptop** (Cloudflare Tunnel, or
   Tailscale Funnel if no domain sits on Cloudflare DNS), real TLS terminated at the edge.

   A VPS deploy on `soyuka.me` was considered and **rejected**, on two grounds:

   - **Failure mode.** `DashboardChannel::pilotTopic()` (`src/Agent/Ui/DashboardChannel.php:66`)
     derives the topic from the server-side `DEFAULT_URI`, **not from the request host**. So the
     topic string is identical whichever hostname a caller arrives on — which means the projector
     browser can stay on `https://localhost` while Claude Desktop talks to the tunnel hostname, and
     both land on the same pilot topic. **The Mercure render path never leaves the machine; only the
     control plane crosses the internet.** Wifi dying mid-talk costs the next command, not the frames
     already on screen. A remote deploy round-trips every frame and cannot offer this.
   - **Cost.** The tunnel deletes three blockers outright: no prod frontend build (the Vite dev server
     keeps serving through `@frontend`, `frankenphp/Caddyfile:78`), no port collisions, no footprint
     or secrets on a box with production neighbours. `compose.prod.yaml` is not needed at all.

   **The tunnel is still internet exposure** — it publishes the stack at a public hostname; it does
   not hide it. What it buys is no inbound firewall/NAT holes, no shared host, and instant revocation
   (exposure lasts exactly as long as `cloudflared` runs). The credential hardening below is therefore
   NOT optional under this option.

   **It must be a NAMED tunnel.** A quick tunnel hands out a fresh random `*.trycloudflare.com`
   hostname per restart, and that hostname is baked into `MCP_ALLOWED_HOSTS` and the Claude Desktop
   connector entry — reconfigurations before every rehearsal. Unusable.

   Network fallback regardless: **4G hotspot, tested under stage-like conditions.**

1b. **Harden the dev defaults before the tunnel opens, not after.**
   *(Obsolete for Casdoor since 2026-10-18: the local seed with `built-in/admin` password `123` is gone.)*
   Same class, lower severity: Postgres `!ChangeMe!`
   (`compose.yaml:110`), and `APP_SECRET` must be freshly
   generated for this — it is the HMAC key deriving the pilot topic, and `mercure { anonymous }`
   (`frankenphp/Caddyfile:25`) means that topic string *is* the access control.
2. **Casdoor reachable at a public origin** with DCR enabled. Now the hosted `https://auth.bonneref.fr`
   (`CASDOOR_ISSUER` in `.env` and `.env.stage`) — `CasdoorTokenHandler` validates issuer + signature, so
   the value must equal the discovery `issuer` exactly. The SPA reaches it directly (no proxy), so that
   instance's CORS must allow `https://localhost`.
3. **Verify the full discovery chain end to end**, once, and write the transcript into `docs/pilot.md`:
   `GET /mcp` → 401 + `WWW-Authenticate` → protected-resource metadata → Casdoor authorization-server
   metadata → DCR → PKCE → bearer → `tools/list`.
4. **The identity trap — this one will silently kill the demo.** The pilot topic is
   `…/agent/pilots/<hmac_sha256(owner_sub, APP_SECRET)>`. The *host* is not a factor (see A.1: the
   base is server-side `DEFAULT_URI`), so mixed-origin is safe — but the **`sub` is**. If Claude
   Desktop authenticates as a different Casdoor user than the browser showing the dashboard, every
   tool call succeeds, returns cleanly, and renders **nothing**. The preflight in workstream B must
   assert both sides derive the same topic, not merely that both are logged in.

Sub-note (resolved upstream): the SDK's `InitializeHandler` used to echo the server's own protocol
version instead of the client's. `mcp/sdk` v0.9.0 negotiates it (`InitializeHandler::negotiate()`
answers the requested version when supported, else the latest), verified 2026-11-13.

### Verified (transport) — 2026-08-15

Provisioned and confirmed working end to end, from the public internet.

- Tunnel `mpa-stage` = `e445b3a1-1362-4816-804a-5d0379458b46`, 4 QUIC edge connections (cdg).
  CNAME `garage.soyuka.me` routes to it (`auth.soyuka.me` retired 2026-10-18). Config:
  `api/docker/cloudflared/config.yml` (gitignored; template `.dist` alongside).
- Stage profile: `api/.env.stage` + `api/compose.stage.yaml`.
  Bring up with
  `docker compose --env-file .env.stage -f compose.yaml -f compose.override.yaml -f compose.stage.yaml up -d`,
  then `rm -rf var/cache` **inside** the php container and restart `php` + `worker`.
- `APP_SECRET` confirmed byte-identical across `php` and `worker` (digest `8b3e1782be4d2dfd`) — if
  these ever drift the two publish to different pilot topics and half the frames vanish silently.
- **`SYMFONY_TRUSTED_PROXIES=private_ranges` is load-bearing.** Without it the 401 challenge derives
  `http://` from the request and advertises a non-HTTPS metadata URL that Claude Desktop refuses.
  With it: `www-authenticate: Bearer resource_metadata="https://garage.soyuka.me/.well-known/oauth-protected-resource"`.
- Chain confirmed publicly: metadata → `resource: https://garage.soyuka.me/mcp`,
  `authorization_servers: [https://auth.bonneref.fr]` → AS discovery (`issuer` matches
  `CASDOOR_ISSUER` exactly) → JWKS 200 → **anonymous DCR issues a client_id**. Two probe clients
  were created during this and deleted afterwards.
- **`dcr_policy` was already `open`** on the `built-in` org — this Casdoor build defaults to it, so
  there is no toggle to hunt for in the UI. Settles the "docs inconsistent" note. The column is
  `organization.dcr_policy`, and Casdoor's tables live in the **`postgres`** database, not `casdoor`.
- Postgres credentials deliberately left at dev values — not tunnel-routed, and it applies
  root credentials only when initialising an empty volume. See the note in `.env.stage.dist`.
- Pre-existing: 9 stray `dcr_*` clients from old Goose experiments, all loopback redirect URIs
  (low risk — codes go to the victim's own machine). Left in place.

### The two bugs that made this look like an auth failure

Claude Desktop reported only "Authorization with Garage failed" plus an opaque support reference.
Both causes were downstream of a *successful* OAuth exchange. Casdoor's `record` table
(`select action, request_uri, response from record order by id desc`) is what finally showed the
truth: the token was issued, so authorization was never the problem. Check that table first next time.

1. **Casdoor DCR issues apps with an empty `cert`.** Casdoor signs JWTs with the application's cert;
   `token_format = JWT` with no cert cannot mint one. Worse, Desktop registers a NEW client on every
   attempt, so repairing existing rows is useless — it just makes fresh broken ones. **Fix: pin a
   static client** in the connector's Advanced settings. `claude-desktop` was cloned from the working
   `dashboard` app (org `built-in`, `cert-built-in`, authorization_code + refresh_token, 168 h).
   The old Goose `dcr_*` clients had the same empty cert — likely the cause of past Goose auth
   trouble too.
2. **The MCP SDK's DNS-rebinding guard is localhost-only and had no config hook.**
   `DnsRebindingProtectionMiddleware` defaults to `['localhost','127.0.0.1','[::1]']`, and
   symfony/mcp-bundle's controller hardcodes `middleware: null`
   (`vendor/symfony/mcp-bundle/src/Controller/McpController.php:38`), which selects it. On a public
   hostname the endpoint answers **403 `Forbidden: Invalid Host header.` to every AUTHENTICATED
   request while unauthenticated ones still 401 correctly** — which reads exactly like a credentials
   problem. Fix: `http.allowed_hosts` in `api/config/packages/mcp.yaml`, driven by
   **`MCP_ALLOWED_HOSTS`** (csv). The guard is kept, not disabled. (Until mcp-bundle 0.14 this
   needed a hand-written controller; the bundle exposes it as an option now.) Covered by `tests/Functional/McpAllowedHostsTest.php` (accept + reject).

   Two traps inside that fix, both already paid for:
   - `MCP_ALLOWED_HOSTS` must be **container env** in `compose.stage.yaml`; a value only in
     `.env.stage` loses to `/app/.env`, since Dotenv never overwrites an existing variable.
   - For the same reason the test value is forced in `phpunit.dist.xml`, not `.env.test` — otherwise
     the suite asserts against whatever hosts the running stack happens to allow, and passes or fails
     depending on which compose profile is up.

**Proven end to end over the public tunnel** with Claude's own issued token: `initialize` → HTTP/2
200 + `Mcp-Session-Id`; `tools/list` → **28 tools**, all 7 pilot tools present; `tools/call toast` →
`{"status":"rendered","intent":"toast"}`. Suites green: `McpAllowedHostsTest`, `McpOAuthTest`,
`McpAppsUiTest`, `DashboardPilotTest`, `AgentMcpToolboxTest` (18 tests).

Note for later: `garage.soyuka.me` in a *browser* will hit Vite's own host check
(403 "Invalid Host header" — same wording, different layer). Harmless while the projector browser
stays on `https://localhost` as designed; if that ever changes, add the host to Vite's
`server.allowedHosts`.

## Workstream B — `mpa`, the stage rig

The binary asked for. One Go binary, no runtime deps, because it must work when the container stack is
the thing that is broken.

| command | does |
| --- | --- |
| `mpa stage up` | compose up, wait healthy, `rm -rf var/cache`, **restart `php` AND `worker`** (FrankenPHP worker mode holds the compiled container *and* the tool schemas — `rm -rf` alone is not enough), open the tunnel, print the connector URL + a QR for the phone, open the dashboard kiosk-mode on the projector display |
| `mpa stage check` | the preflight. Green/red, no prose. Asserts: Mercure stream open · pilot topic **matches on both sides** (A.4) · `tools/list` returns the expected count · a real `toast` round-trip observed on the SSE stream · cert accepted by the browser profile · GPU frame budget sane · **audio muted** and, if ever unmuted, `AudioContext.state === 'running'` (Chrome gates the unlock on a per-profile Media Engagement Index, so it can work on the dev laptop and fail on the stage laptop with identical code) |
| `mpa stage rehearse` | replays the three beats deterministically from a recorded frame script, **no LLM, no network**. This is both the rehearsal loop and the live fallback if voice or wifi dies. |
| `mpa stage reset` | restore the demo database to a known snapshot — between rehearsals, and once before walking on stage |

`stage check` is the answer to "why were the devtools open on my screen last time": the check belongs
in a command, run before the room fills, not in a browser panel during the talk.

## Workstream C — the visual layer

The current focus behaviour is `presetForRegion` (`dashboard/src/scene/cameraRig.ts:89`): keep the home
viewing angle, retarget on the region anchor, pull in to `DOLLY_RADIUS_FACTOR` = 0.62. That is a polite
zoom on the outside of a closed car — which is exactly the "pas terrible" being reported. It is working
as designed; the design is what is short.

**Constraint that decides the approach:** `dashboard/public/mazda6-gh-web.glb` is **one node, one mesh**
(`Mazda6_Web`, 17 materials, 1.5 MB). There is no hood object, no door, no engine. Real panel animation
would mean mesh surgery in Blender on a merged mesh, per body class. Rejected — cost and risk both
wrong, and it buys realism the blueprint language does not even want.

Instead: **X-ray cutaway + schematic assembly.** Four pieces.

### C1 — the ghost uniform

The shell fades to edges-only while the assembly under it rises. Implement as one more uniform
(`uGhost`) on the existing single shader patch: `blueprintBuild.ts:66` `patchHolo()` already merges the
scan and pulse uniforms into **one** `onBeforeCompile` because a material only gets one
(`blueprintBuild.ts:62`). Add `uGhost` there, in the same call, driven by a `GhostRunner` alongside
`ScanRunner`/`PulseRunner` in `VehicleMesh.tsx:105`. Inert at `uGhost=0` so parc thumbs are untouched.

### C2 — a reveal camera pose

`presetForRegion` cannot express "descend to a near-vertical over-the-bay pose" — it is hardcoded to
reuse the home polar (`cameraRig.ts:78`). Add a second preset family `presetForReveal(bodyClass, region)`
with its own polar band and radius factor, and widen `POLAR.min` for the reveal hold only. Same pure-core
shape, same tests.

### C3 — the schematic assembly

Authored primitives placed in the region's anchor space (`anchors.ts` `regionAnchors`), **not** geometry
pulled from the glb. This is the honest move: it is visibly a *diagram* of a fuel-filter circuit, not a
claim about that specific engine bay. It also generalises across body classes for free, which the glb
never would. Blueprint edges, existing signal colours, cyan stays rationed to Le Noyau.

### C4 — a new pilot tool: `reveal_assembly(region, part_keys)`

Server side this is one `McpTool` entry on `src/ApiResource/Dashboard.php`, one Input DTO, one Processor
reusing `RenderIntentTools` — the same seven-tool pattern already there. Client side: a `CONTRACT.md`
frame shape, a reducer case, the scene wiring.

**This is the slide.** Adding a whole new capability to a voice-driven 3D interface is a resource, a DTO
and a processor. That is the API Platform argument, made in code the room can read in one screen.

### C5 — projector legibility

The whole design was tuned for a desk at 60 cm. Audit at 1920×1080, projected, viewed from 10 m: type
scale, hairlines (1 px will disappear), and cyan-on-graphite under projector gamma — the `--at-vib-*`
vibrancy tiers and the ≥.86 scrim law were verified on an LCD, not a DLP. Ship it as an `--at-stage`
scale layer toggled by `mpa stage up`, **not** a redesign of the tokens.

Keep: bloom `multisampling` MUST stay 0 (any MSAA blacks the whole canvas, three 0.169 +
postprocessing 6.39, no console error).

## Workstream D — the write beat

`log_maintenance` (`src/ApiResource/Maintenance.php:39`) is a normal synchronous processor — no Messenger
hop, so no worker latency on stage — and it already fans out on the public vehicle topic, so every open
dashboard live-updates. `PlannedMaintenancePlanner::closeMatching` already auto-closes the matching task.

So the beat mostly works. What is missing is that **the recompute is invisible**: the ring just has a
different value next frame. Make the change itself legible — the health arc animates from the old value
to the new one, the closed task strikes and leaves the rail rather than vanishing between renders. That
is caused-by-data motion, so it is inside the rule.

## Workstream E — rehearsal and failure drills

- Run `mpa stage rehearse` until the three beats are muscle memory, including what is said while the
  camera moves.
- Rehearse each failure with its recovery: **no network** → `rehearse` replays the script locally;
  **Claude answers in the transcript instead of the UI** → the ⇄ composer button replays a pilot burst;
  **cert dropped by the browser** → `stage check` catches it before the room fills, never during.
- Record a screen capture of a clean run. If everything fails, the talk still has its demo.

---

## Ordering (>6 weeks of runway)

| when | what | why then |
| --- | --- | --- |
| weeks 1–2 | A (transport) + B `up`/`check` | the only parts that can fail in a way no amount of polish rescues. Nothing else is worth building until voice reaches the UI at least once. |
| weeks 2–4 | C1–C4 (the reveal) | the actual new capability; TDD first, per repo convention |
| weeks 4–5 | C5 projector pass + D | both are tuning passes that need C landed to be worth doing |
| weeks 5–6 | B `rehearse`/`reset` + E | rehearsal is the last thing and must not be compressed |

## Open questions

1. Which domain sits on **Cloudflare DNS** — `soyuka.me` or another? A named tunnel needs one. If
   none, fall back to Tailscale Funnel (free, stable `*.ts.net`, real cert, one command).
2. Does the talk show code on screen? If yes, C4's `McpTool` definition is the natural slide and I
   should shape the DTO with that reading in mind.
3. Will the browser on the projector be logged in as the same Casdoor identity Claude Desktop
   authenticates as? See A.4 — this is the silent killer.
4. Talk length, and how much of it is this demo versus API Platform proper? It decides whether three
   beats is the right number.
