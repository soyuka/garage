// ─────────────────────────────────────────────────────────────────────────────
// Atelier — frozen backend-contract types (Stage 0 deliverable).
//
// These mirror the shapes the Symfony/API-Platform backend actually serves and the
// frames it publishes over Mercure. They are contract-level type shapes: a faithful
// client of the same contract converges on the same fields by necessity. They were
// re-derived field-by-field from the live backend (see CONTRACT.md), then
// re-authored here in Atelier's own structure (see PROVENANCE.md). Nothing here is a
// display/view model — those are rebuilt fresh from fixtures in Stage B.
//
// The vehicle handle EVERYWHERE is the dereferenceable `@id` IRI (e.g.
// "/garage/vehicles/01J…"), never a bare ULID.
// ─────────────────────────────────────────────────────────────────────────────

/** An IRI string, e.g. "/garage/vehicles/01JABC…" or "/garage/planned-tasks/01J…". */
export type Iri = string;

/** Signal semantics, locked: vert = ok, ambre = bientôt, rouge = en retard. */
export type Signal = 'green' | 'amber' | 'red';

/** Toast tone. `alarm` is the only tone that surfaces a real toast popup; the rest feed narration. */
export type Tone = 'info' | 'ok' | 'warn' | 'alarm';

/** A usage axis unit. Drives whether a service forecasts on km / miles / engine-hours. */
export type UsageUnit = 'km' | 'mi' | 'hours';

/** What a service does to its component — shapes the verb in labels. */
export type ServiceAction = 'change' | 'replace' | 'clean' | 'inspect';

// ── Vehicle facts ────────────────────────────────────────────────────────────

/** One stored spec about a vehicle, with provenance. Keyed by `key` (one fact per key per vehicle). */
export interface VehicleFact {
  key: string;
  value: string;
  unit?: string | null;
  source: string;
  /** true only for catalog sources (partsapi/siv) — render a verified badge. */
  verified: boolean;
  url?: string | null;
}

/** Derived fluid spec for a ref-less fluid line ("which oil, how much"), projected from the facts. */
export interface FluidSpec {
  /** true when the standard (manufacturer spec) is catalog-sourced. */
  verified: boolean;
  viscosity?: string; // "5W-30"
  standard?: string; // "ACEA A3/B3|ACEA A5/B5"  ("|" separates alternatives)
  capacity?: string; // "4.3"
  unit?: string; // "L"
}

// ── Parts ──────────────────────────────────────────────────────────────────--

/** A part line on a task's shopping list — or a derived fluid line (carries `spec`, no `id`/`ref`). */
export interface PlannedPart {
  /** stable ULID of a stored line — the edit/remove handle. Absent on a derived fluid spec line. */
  id?: string;
  ref: string;
  label?: string;
  brand?: string;
  qty?: number;
  /** unit price, integer minor units (cents). */
  priceCents?: number;
  /** present only on a server-computed fluid line; never written back. */
  spec?: FluidSpec;
}

// ── Planned tasks (the open checklist) ────────────────────────────────────────

/** An open task: intended work plus the parts it needs. `id` is the task IRI, PATCHed to mutate it. */
export interface PlannedTask {
  id: Iri;
  type: string;
  label?: string | null;
  target?: { value: number; unit: string | null } | null;
  dueDate?: string | null; // YYYY-MM-DD
  parts: PlannedPart[];
  status: string; // "open" | "done" | "dismissed"
}

// ── Maintenance records (past visits) ─────────────────────────────────────────

/** One service line of a visit: a single service type with the parts it consumed. */
export interface MaintenanceItem {
  type: string;
  label?: string | null;
  parts: PlannedPart[];
}

/** A bill attached to a visit. `url` is the owner-gated download IRI — fetch with the Bearer, not <img src>. */
export interface MaintenanceAttachment {
  id: string;
  filename: string;
  contentType: string;
  size: number;
  url: Iri;
}

/** One past service event — a VISIT carrying one or many service items + visit-level fields. */
export interface MaintenanceRecord {
  id: Iri;
  items: MaintenanceItem[];
  attachments?: MaintenanceAttachment[];
  label?: string | null;
  performedAt: string; // YYYY-MM-DD
  usageValue?: number | null;
  usageUnit?: string | null;
  notes?: string | null;
  performedBy?: string | null;
  costCents?: number | null; // visit bill total, integer minor units
  currency?: string | null;
  source: string;
}

// ── Service schedule (recurring rules) ────────────────────────────────────────

/**
 * One recurring maintenance rule — a `ServiceInterval`. Cadence drives the forecast: a usage cadence
 * (everyUsage), a time cadence (everyMonths), or a break-in first interval (firstUsageValue).
 * Identity is (type, action, component); re-POSTing the same identity corrects the cadence (upsert).
 */
export interface ServiceRule {
  id: string; // the rule's ULID — used to DELETE it
  vehicleId: Iri;
  type: string; // snake_case service type
  action: ServiceAction;
  component?: string | null; // part of the dedup identity
  everyUsage?: { value: number; unit: UsageUnit } | null;
  everyMonths?: number | null;
  firstUsageValue?: number | null; // break-in override
  source: 'user' | 'web' | 'partsapi' | 'siv' | 'llm';
  verified: boolean; // true only for partsapi/siv (catalog)
  url?: string | null;
}

// ── Forecast ─────────────────────────────────────────────────────────────────

/** The usage axis of a forecast item — last reading / due-at / remaining against the cadence. */
export interface ForecastUsage {
  last: number;
  due: number;
  remaining: number;
  unit: UsageUnit;
}

/**
 * One predicted-due service. Every due is `rule: "schedule"` (per-vehicle cadence; no generic
 * fallback). Usage-axis items carry `usage`; time-axis items carry `dueAt`/`projectedDueAt`.
 */
export interface ForecastDue {
  type: string;
  action: ServiceAction | null;
  component: string | null;
  rule: 'schedule';
  lastPerformedAt: string | null; // YYYY-MM-DD
  dueAt: string | null; // YYYY-MM-DD, time axis
  projectedDueAt: string | null; // YYYY-MM-DD, usage axis projected onto the calendar (estimate)
  overdue: boolean;
  daysUntilDue: number | null;
  usage: ForecastUsage | null;
}

/** The per-vehicle forecast (GET …/forecast): due items soonest-first + coverage gaps. */
export interface VehicleForecast {
  vehicle: Iri;
  due: ForecastDue[];
  gaps: string[]; // recurring types with a known cadence but no logged baseline
}

/**
 * The compact next-due summary carried on each parc-listing member (collection only), driving the
 * parc rail's one-line forecast. Null when nothing is predictable yet.
 */
export interface NextDue {
  type: string;
  action: ServiceAction | null;
  label: string;
  rule: 'schedule';
  overdue: boolean;
  dueAt: string | null;
  projectedDueAt: string | null;
  daysUntilDue: number | null;
  usageRemaining: number | null;
  usageUnit: UsageUnit | null;
}

// ── Maintenance health rollup ─────────────────────────────────────────────────

/**
 * The API's maintenance-health rollup — served on the ITEM GET only (the collection omits it).
 * `red` = ≥1 overdue; `amber` = due soon or a coverage gap; `green` = all tracked & current;
 * `unknown` = nothing tracked yet → render neutral/grey, NOT as healthy.
 */
export interface MaintenanceHealth {
  status: Signal | 'unknown';
  score: number | null; // null only when status is "unknown"
  overdue: number;
  // due soon — within 30 days OR within ~10% of the usage interval (the §2 amber rule; composite,
  // not day-only). Verify the exact backend rollup definition when wiring live data in Stage C.
  dueSoon: number;
  tracked: number; // services with a logged baseline
  gaps: number; // recurring services with NO logged baseline
}

// ── The vehicle read-model the API serves ─────────────────────────────────────

/**
 * A garage vehicle as the API serves it (GET /garage/vehicles[/{id}]) — the same projection the MCP
 * list_vehicles tool returns and the shape `resource` carries on a domain Mercure frame. `@id` is the
 * dereferenceable IRI used as the vehicle handle everywhere.
 *
 * Field availability differs by endpoint: `health`, `facts`, and `plannedTasks` ride the ITEM GET
 * only; the collection listing carries `nextDue` and `openTasks` but omits the rest.
 */
export interface VehicleSummary {
  '@id': Iri;
  make: string;
  model: string;
  year?: number | null;
  engineVariant?: string | null;
  engineCode?: string | null;
  vehicleType: string;
  source: string;
  kType?: number | null;
  plate?: string | null;
  vin?: string | null;
  usageValue?: number | null;
  usageUnit?: string | null;
  factCount: number;
  openTasks?: number;
  facts?: VehicleFact[];
  /** item GET only — absent on the collection listing. */
  health?: MaintenanceHealth;
  /** collection listing only — may be null. */
  nextDue?: NextDue | null;
  /** item GET only. The API serves each task IRI as `@id`; normalize it to `id` for our model. */
  plannedTasks?: Array<PlannedTask & { '@id'?: Iri }>;
  addedAt: string;
}

// ── Display composition (Tier-2) ──────────────────────────────────────────────

/**
 * The three reads that compose one vehicle's full Atelier view: the item read-model, its forecast,
 * and its past visits. The scene renders a pure function of this bundle — fixtures build it for the
 * playground, the live orchestrator builds the identical shape from the API.
 */
export interface VehicleBundle {
  vehicle: VehicleSummary;
  forecast: VehicleForecast;
  records: MaintenanceRecord[];
}

// ── The headless agent → UI vocabulary (the "chat hidden" contract) ───────────

/**
 * A call-to-action button. Exactly ONE of the two payloads is present:
 *  - `instruction`: re-runs the agent with that NL follow-up (a fresh turn).
 *  - `patchTask`: a deterministic PATCH that merges parts onto an existing task — NO agent turn.
 *    Wiring this path is mandatory; skipping it is the known dead-button bug class.
 */
export interface CtaAction {
  label: string;
  instruction?: string;
  patchTask?: { id: Iri; parts: PlannedPart[] };
}

/**
 * The REAL, verified agent→UI action union — the only members that exist on the wire. The agent never
 * returns chat; it emits these and the reducer maps them to state.
 *
 * NOTE: `renderSuggestions` and `patchPanel` from the docs/ui.md sketch DO NOT EXIST — never build
 * against them. The choreography map (Stage B.0) binds each real member to an Atelier motion.
 */
/** The single swappable slot's content: the spec/échéance panel or the maintenance to-do list. */
export type PanelKind = 'specs' | 'tasks';

export type UIAction =
  | { type: 'setVehicles'; vehicles: VehicleSummary[]; preferredId?: Iri }
  | { type: 'selectVehicle'; id: Iri }
  | { type: 'showFact'; keys: string[]; title?: string }
  // Point the operator at what matters: raise one panel into the centre slot + spotlight `keys`
  // (each lights its car region and etches matching rows). showFact is the specs-only subset.
  | { type: 'focusView'; panel: PanelKind; keys?: string[] }
  | { type: 'proposeActions'; headline: string; finding?: string; actions: CtaAction[] }
  | { type: 'toast'; tone: Tone; message: string }
  // Transient per-narrated-step cue (sparse — only a whitelisted subset of tools narrate). Feeds the
  // Noyau narration/heartbeat line and a Noyau pulse; carries no reducer state.
  | { type: 'status'; message: string }
  // Turn lifecycle — drives the busy/working state. Carries no reducer state.
  | { type: 'turn'; phase: 'start' | 'end' };

// ── Mercure frames ────────────────────────────────────────────────────────────

/**
 * A garage write fanned out on the public vehicle topics. The payload CARRIES the full vehicle
 * read-model (`resource`), so the UI renders fresh state with no follow-up fetch. The publisher fans
 * the same payload to both the item AND the collection topic, so subscribing to the collection topic
 * alone covers every vehicle.
 */
export interface DomainEvent {
  kind: 'vehicle';
  event: 'created' | 'updated' | 'deleted';
  vehicle: string;
  iri: Iri;
  resource: VehicleSummary;
}

/**
 * Any frame arriving on a subscribed Mercure topic: a domain event (collection topic) or an agent
 * UIAction intent / transient cue (per-session topic). Told apart by the discriminating `kind`
 * (DomainEvent) vs `type` (UIAction) field.
 */
export type MercureFrame = DomainEvent | UIAction;
