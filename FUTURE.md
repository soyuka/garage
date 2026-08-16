# FUTURE — mon-parc-auto

Forward-looking direction, not yet committed work. Parked here so a session can pick a slice
without re-deriving the analysis. Near-term work is tracked here too — the old `TODO.md` is history,
archived under `docs/archive/`; its § "Reference — don't re-investigate" still holds the
evaluated-and-rejected data sources.

---

## GMAO core — turn the pull-only forecast into a real maintenance manager

**Positioning (decided 2026-06-21):** we are an **AI-native personal / light-fleet maintenance
tracker** (GMAO-leaning) with a diagnostic-DB data layer. NOT a commercial DMS (no invoicing,
no customer records, no labor-time billing — that's a deliberate B2B pivot, not a drift).
The one gap that makes us legibly "GMAO" is a proactive **preventive-plan + alert** loop.

### Already shipped — DO NOT rebuild

The forecast skeleton exists and is clean:

- `MaintenanceType` enum (`src/Maintenance/MaintenanceType.php`) — recurring types
  (OilChange, OilFilter, Cabin/Air/FuelFilter, Brakes, Inspection, SparkPlug, Coolant,
  Battery, Tires) + non-recurring (Repair, Other).
- `MaintenanceInterval::forType()` (`src/Maintenance/MaintenanceInterval.php:25`) —
  general-knowledge default intervals; **time axis primary, usage advisory only**.
- `DueMaintenanceCalculator::forVehicle()` (`src/Maintenance/DueMaintenanceCalculator.php:24`)
  — next-due = latest `performedAt` + interval; flags `overdue`, computes `daysUntilDue`;
  honestly surfaces `unknownBaseline` (recurring types never logged → "when last done?").
- Planned-task checklist — `PlannedMaintenance` entity + `PlannedMaintenancePlanner`
  (UPSERT, `dismiss`, `closeMatching` auto-clears a task when its service is logged).
- API surface — `ListDueMaintenanceProcessor`, `PlanMaintenanceInput`, `DismissMaintenanceInput`.
- Vehicle stores a `usageValue`/`usageUnit` reading (`src/Entity/Vehicle.php:102`) — but it's
  a PAST reading, never trusted as live (see item 2).

What's missing is what turns a pull-only forecast into a GMAO. Build order = value/cost.

---

### 1. Alert sweep — proactive due/overdue notifications  ★ highest value, no external data

**Gap:** forecast is **pull-only** — computed only when someone calls `ListDueMaintenance`.
A GMAO *pushes*: scans the parc on a schedule, raises alerts. We have Messenger (async worker)
but **no Symfony Scheduler and no Notifier/Mailer** (verified 2026-06-21 — none in `src/` or
`config/`).

**Scope:**
- Symfony Scheduler → periodic sweep message (daily) → per-vehicle `DueMaintenanceCalculator`
  → emit alert when a service crosses due/overdue (and de-dupe so one alert per (vehicle, type)
  transition, not every sweep).
- Surface: the landing zone already exists — Le Noyau's "à-décider" ambient inbox
  (`dashboard/src/noyau/state.ts` `DecisionItem` kind `'notify'` + `AmbientInbox.tsx`) queues
  concerns behind the ember badge with a `planifier` affordance keyed by `serviceType`/`vehicleIri`.
  Today it's fed only by a live, in-session Mercure health-crossing (the existing §9 "overdue
  crossing" `concern` event) — still reactive, not a push, and gone once the tab closes. The sweep's
  job is to feed this same queue for real: cross-session, from the scheduler, not from a live write.
  Email / web-push later.
- Respect owner tenancy (`owner` seam) so alerts route to the right user once multi-tenancy
  lands — single shared garage for now.
- Reuses existing forecaster untouched; only adds the trigger + delivery + alert state.

**Tests:** sweep emits on the overdue transition, stays silent on a no-change sweep, clears
when the service is logged (`closeMatching` already exists).

---

### 2. Dual-axis forecasting — revive the km/hours axis  ★ big correctness win for fleet

**Gap:** `MaintenanceInterval.php:7-13` is explicit — stored usage is a *past* reading, never
live, so "due in X km" is disabled. But the real rule is **whichever comes first: time OR
usage** (vidange = 12 months OR 10 000 km). Today a 40 000 km/yr van forecasts identically to
a 3 000 km/yr classic.

**Scope:** need a **live odometer estimate**. Two paths (pick or combine):
- prompt the user for current km/hours (simple, accurate, manual);
- model a usage-rate (avg km/month from successive logged readings) and extrapolate
  current ≈ last reading + rate × elapsed.
Then due-date = min(time-axis due, usage-axis due). Keep surfacing which axis triggered.

**Depends on:** a clean mileage-update flow (see item 4's lifecycle note).

---

### 3. DB-backed per-vehicle interval override

**Gap:** `forType()` is a hardcoded `match()`, general-knowledge defaults, one-size-fits-all,
no override. Diesel DPF ≠ petrol; severe-duty ≠ normal. The file itself says a per-vehicle
override "belongs on the future DB-backed schedule, not here."

**Scope:** layered resolution — code default → per-model → per-vehicle override. Schema is
easy. The HARD, possibly-never-solved part = an **authoritative OEM service-interval data
source**:
- auto-data.net does **NOT** carry intervals (evaluated — see `docs/archive/TODO.md` reference section).
- No open service-schedule dataset exists and won't (licensed IP, like TecDoc).
- Treat like the parts moat: vendor-sourced if one is found, else LLM-sourced-**unverified**
  with provenance, same pattern as the garage-fact `source`/`verified` seam.

Ship the override schema independent of the data source — manual per-vehicle override is
useful on day one even with no upstream interval feed.

---

### 4. FR regulatory calendar — Contrôle Technique & co.  ★ deterministic, cheap, high visibility

**Gap:** GMAO for French vehicles must track legal deadlines. Today `Inspection` is a flat
24-month generic interval, NOT modeled as a regulated deadline.

**Scope:** model CT with its real periodicity — **1st CT at 4 years, then every 2 years**;
annual pollution/contre-visite rules for utilitaires; optionally insurance/assurance renewal.
Age-based first-due derived from the vehicle's first-registration date. No external data
needed — pure deterministic rules. Plugs straight into the item-1 alert sweep.

**Lifecycle (shared with the alerts):** snooze / acknowledge / "remind me in 2 weeks" on a due
*alert* (distinct from the planned-task `Dismiss`, which manages the checklist), plus the
mileage-update flow item 2 needs.

---

### Suggested first slice

**1 + 4 together** — they make it legibly a GMAO without needing any data source we don't
already have. 2 follows (usage model). 3's schema is easy but gated on the interval data
source, so it trails.

---

## Render vocabulary — surfaces the agent cannot reach

Found 2026-08-15 by auditing ~28 real garage prompts against the 29 live MCP tools and the render
surfaces in `dashboard/`. Full analysis + the design brief: `docs/design-inspiration.md`.

**The shape:** the dashboard grew surfaces the wire vocabulary cannot address, so the agent reads
real data and has nowhere to put it — falling back to a prose `toast`, which `stage/CLAUDE.md`
explicitly forbids.

### Closed (shipped 2026-08-15)

- **`focus_view` accepts `carnet`.** `CarnetProjection` existed but only the "Carnet · dépenses"
  wayfinder chip could raise it, so « quand j'ai fait la dernière vidange ? » and « j'ai dépensé
  combien ? » had no agent-driven surface.
- **`show_fact` accepts projected `nodes`.** `Focus.nodes: SpecNode[]` was client-only, so
  `get_fill_volumes` / `get_motul_oil` / `lookup_plate` results could not be projected —
  `show_fact(['oil_capacity'])` rendered an EMPTY panel precisely when the agent had done the work.

### 5. Parc-level surface  ★ biggest hole, needs design first

`list_due_maintenance` already forecasts the **whole garage** and always has. Every render intent
acts on the *selected* vehicle, so no cross-vehicle question has any surface at all: « qu'est-ce qui
est en retard partout ? », « c'est laquelle la prochaine à passer ? », « quelle bagnole me coûte le
plus ? ». `ParcLot` exists but no agent verb addresses it.

Not a plumbing job — a new surface with no design. Ranked, never enumerated (see the design brief).

### 6. Entretien rail is item-shaped, should be visit-shaped

The plan side does not mirror the log side. A `MaintenanceRecord` is a **visit** carrying `items[]`;
the rail lists services one per row, so *vidange* + *filtre à huile* falling on the same date read as
two jobs instead of one trip to the lift. Four further defects (parts catalogue inside the ambient
layer, seven rows saying "OK", two competing time formats, a 2030 entry treated as a task) are
worked through in `docs/design-inspiration.md` § 5.

### 7. Smaller gaps found in the same audit

- **No visual for a recurring schedule.** `set_maintenance_schedule` writes rules that are invisible —
  only their consequences ever appear. Ties into item 3 above.
- **No MCP tool for bill attachments.** The API has `POST {recordIri}/attachments`; the agent cannot
  reach it, so « ajoute la facture » is unanswerable.
- **No possession/stock model**, so « j'ai déjà le filtre, il me manque quoi ? » cannot be answered.
- **Region vocabulary tops out at five zones** (`dashboard/src/scene/anchors.ts:26`), so « montre-moi
  où c'est » is coarse. The engine-bay reveal (workstream C, `CONFERENCE.md`) is what raises it.
- **`brake_pads_front` / `brake_pads_rear` are not declared aliases** (`dashboard/src/labels.ts:9`).
  The backend emits them — `AtelierScene`'s own history fixture uses `brake_pads_front` — but
  `SERVICE_ALIASES` folds neither, so `regionForType` misses `TYPE_REGION` and falls through to its
  unmapped-→`hood` default: a front brake job spotlights the **engine**, and `serviceLabel` prints the
  humanized raw key instead of « Plaquettes de frein ». Found 2026-08-16 while writing the C3 assembly
  tests. The fix is two rows in `SERVICE_ALIASES`, but front/rear are genuinely different parts on
  different axles, so folding both onto `brake_pads` throws away the axle — which is exactly the datum
  the region lookup needs. Decide whether the axle survives as a distinct region key before folding.

---

## Audition — the two open questions after the 2026-08-16 measurement

Plan and research live in `docs/voice-plan.md` and `docs/voice.md`. Parked here is only what a future
session must not re-derive.

### 8. Hosted TTS was measured and the premise it rested on is gone

`docs/voice.md:57` excluded ElevenLabs on "no paid API", not on merit. The voice plan then assumed a
free key was a working middle ground. **It is not, and this is measured, not inferred:** a free-tier
key sees exactly 21 voices, all `language: en`; every French voice is a shared-library voice; and the
API returns `402 paid_plan_required — "Free users cannot use library voices via the API"` for all of
them, *including* voices whose `shared-voices` record says `free_users_allowed: true` (that flag
governs the web app). Free-tier cloning is off, so there is no workaround. **A French hosted voice
costs a paid plan.**

That puts the decision back where `docs/voice.md` already left it — Piper route A, which is installed
on this machine, French, 0.4–0.7 s cold, and immune to the plan's own top risk (conference wifi). The
`/tts` seam in phases 1–5 is provider-agnostic and is the real work; it is worth building against
Piper regardless. Do not re-run the ElevenLabs free-tier experiment — the 402 above is the answer.

### 9. Generated SFX are probably the wrong tool for the `échéance` icon

`POST /v1/sound-generation` **is** open on the free tier (verified — it is the one paid-adjacent thing
a free key can do), and phase 7 of the voice plan uses it at build time only, so it carries no runtime
dependency. But eleven generations across three prompt strategies produced audio that is consistently
**noisy** — room tone and texture baked in — which is the opposite of what a caution signal peaking at
gain `0.14` needs. Denoising a generated clip down to a clean two-pulse tone may cost more than
rendering the tone physically or sourcing a CC0 sample. Try one clean-source route before assuming
generation is the path. The *design* target found in the same session still holds and is the useful
output: **no strike transient, physically-credible machine resonance** (coil, tuning fork) — a bell,
a chime or a kitchen timer reads domestic, and a sci-fi UI blip is forbidden outright by
`docs/prompt-pilot-motion.md:124` § Taste.

**Licensing gate, unresolved:** free-tier ElevenLabs output may be attribution-bound or
non-commercial. The icon would be *committed into the product*, so this must be checked against the
live terms — not from memory — before any generated asset lands in git.


---

## Internationalisation — the app speaks fr and en (2026-08-16)

The chrome, the formatting and the voice's pronunciation now follow `mpa.locale`. What follows is the
part deliberately left French, with the traps found while doing the rest.

### Already shipped — DO NOT rebuild

`dashboard/src/i18n/` (`locale.ts` pure core + `LocaleProvider.tsx` + `fr.ts`/`en.ts` catalogs typed
`Messages = typeof fr`) · `format.ts` and `labels.ts` take a **required** `locale` · the switch beside
the audition toggle (`AmbientInbox.tsx`) · `Accept-Language` on `authFetch` and `/tts` ·
`framework.yaml` `default_locale`/`enabled_locales`/`set_locale_from_accept_language` ·
`App\I18n\Locale`, `LocaleContext`, `AgentMessages` · `SpeechClient::synthesize($text, Locale)`
sending `language_code` · `status {tool}` on the wire so the step chips no longer keyword-match
French prose · the per-turn output-language directive and the `NO_RESULTS` token family.

### Closed (shipped 2026-08-17) — the agent answers in the interface language

`ai.yaml:82` no longer names a language: it defers to a per-turn directive built by
`api/src/Agent/TurnContextBuilder.php`, which is the first line of every turn context. The locale
reaches the worker on `RunAgentTurn.locale` (resolved from `Accept-Language` in
`AgentTurnController`) and is bound into `App\I18n\LocaleContext` — the same shape as `OwnerContext`,
for the same reason. `StepNarrator`, the two failure toasts in `RunAgentTurnHandler` and
`CancelTurnListener` read their wording from `App\I18n\AgentMessages`. Every `#[ApiProperty]` and
tool description that used to say "in French" now says "in the interface language".

**The sentinel is gone too.** `WebSearchTool` returns `NO_RESULTS` / `SEARCH_UNAVAILABLE` /
`SEARCH_BUDGET_SPENT` as leading tokens and `ai.yaml` keys its retry rule on those, so the agent's
output language and its control flow are no longer the same string. Tool RESULTS and tool
DESCRIPTIONS stay English on purpose — they are model-facing, and the model already reads English
while writing French.

A hand-written catalog rather than `symfony/translation`: `composer require` cannot run in this repo
at all, because two local path repositories under `~/forks` are not visible inside the php container
(verified 2026-08-17 — it aborts on `PathRepository … does not exist` and reverts cleanly). Revisit
if that setup changes.

### 11. Catalog + domain text that is French at the source  ★ the parts of the screen no catalog can reach

**Gap:** four different French-by-origin surfaces, each needing a different answer, none of them a
translation key.

- `api/src/ApiResource/FillVolume.php:45-66` `LABELS_FR` is a Russian→French map. **Careful:**
  `App\Catalog\ServiceKind::fluidLabels()` matches against its French *output*, so translating it in
  place silently breaks fluid matching.
- `api/src/Maintenance/MaintenanceTypeNormalizer.php` — ~80 French input aliases. English aliases must
  be **added**, never swapped: French input has to keep parsing whatever the UI language is.
- `PARTSAPI_LANG=6` / `MOTUL_LOCALE=fr-FR` are global singletons **and part of the cache key**
  (`api/docs/PARTSAPI.md:11-16`), so per-request catalog language means per-locale cache keys and a
  re-fetch per method per language.
- `dashboard/src/siv.ts` (carte grise) and the Contrôle Technique work are French-regulatory: the
  field labels translate, the documents do not. `CV` is a French tax band — rendering it `hp` would
  state something false, and `en.ts` deliberately keeps `CV`.

### 12. The room around the app  ★ an English demo is more than an English UI

**Scope:** `stage/CLAUDE.md:21,50,60-64,75-93` and `stage/.claude/output-styles/atelier.md:11` mandate
French presenter copy and French narration — an English demo needs an `en` output style and an English
beat sheet. Casdoor's login is `defaultLanguage = "en"` in all three `api/docker/casdoor/app.conf*`
files, i.e. already English and already inconsistent with a French app; a language change there must
land in all three or it becomes a fourth stage/dev divergence. `admin/` has zero French strings and
needs nothing. `dashboard/src/scene/shopping.ts:68-70` links Mister-Auto and Auto-Doc, French
storefronts — a real choice, not a string.

### 13. Speaking French well costs a paid ElevenLabs plan  ★ English TTS is currently BETTER than French

**Gap:** `language_code` now rides every `/tts` call, which is the right fix and improves French
pronunciation from the configured voice. It does not change the measured ceiling in item 8: a
free-tier key sees 21 voices, all `language: en`, and every French voice is a library voice returning
`402 paid_plan_required`. So `ELEVENLABS_VOICE_ID` is Sarah, an English voice — English now sounds
native and French sounds like an English speaker reading French. That inversion is new and worth
stating before anyone demos it. The Piper route (item 8, `docs/voice.md` route A) remains the
alternative and the `/tts` seam is already provider-agnostic.
