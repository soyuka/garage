# FUTURE — mon-parc-auto

Forward-looking direction, not yet committed work. Parked here so a session can pick a slice
without re-deriving the analysis. For active near-term work see `TODO.md`; for evaluated-and-
rejected data sources see `TODO.md` § "Reference — don't re-investigate".

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
- Surface: dashboard badge first (cheapest, already have the UI seam); email / web-push later.
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
- auto-data.net does **NOT** carry intervals (evaluated — see `TODO.md` reference section).
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
