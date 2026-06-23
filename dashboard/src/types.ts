export type VehicleId = string;
export type VehicleStatus = 'ok' | 'soon' | 'overdue';
export type Tone = 'info' | 'ok' | 'warn' | 'alarm';

/**
 * The API's maintenance-health rollup, served on the item GET only (the collection listing omits it).
 * `red` = at least one overdue service; `amber` = something due soon or a coverage gap; `green` = all
 * tracked & current; `unknown` = nothing tracked yet — render neutral/grey, NOT as a healthy state.
 */
export interface MaintenanceHealth {
  status: 'green' | 'amber' | 'red' | 'unknown';
  score: number | null; // null only when status is "unknown"
  overdue: number; // overdue services
  dueSoon: number; // due within 30 days
  tracked: number; // services with a logged baseline
  gaps: number; // recurring services with NO logged baseline
}

export interface NextService {
  value: number;
  unit: string;
}

/** Derived (read-only) fluid spec for a ref-less fluid line — "which oil, what quantity", projected from the vehicle facts. */
export interface FluidSpec {
  verified: boolean; // true when the standard (manufacturer spec) is catalog-sourced
  viscosity?: string; // e.g. "5W-30"
  standard?: string; // e.g. "ACEA A3/B3|ACEA A5/B5" ("|" separates alternatives)
  capacity?: string; // e.g. "4.3"
  unit?: string; // e.g. "L"
}

export interface PlannedPart {
  id?: string; // stable ULID of a stored line — the edit/remove handle (absent on a derived fluid spec line)
  ref: string;
  label?: string;
  brand?: string;
  qty?: number;
  priceCents?: number; // unit price, integer minor units (like MaintenanceRecord.costCents)
  spec?: FluidSpec; // present only on a derived fluid line (server-computed, never written back)
}

/** An open task on a vehicle's checklist — intended work plus the parts it needs (shopping list). */
export interface PlannedTask {
  id: string; // the task IRI, PATCHed to tick it off
  type: string;
  label?: string | null;
  target?: { value: number; unit: string | null } | null;
  dueDate?: string | null;
  parts: PlannedPart[];
  status: string;
}

/** One past service event — the dashboard's "past maintenance" rail reads these read-only. */
/** One line-item of a service visit: a single service type with the parts it consumed. */
export interface MaintenanceItem {
  type: string;
  label?: string | null;
  parts: PlannedPart[];
}

/** A bill attached to a visit. `url` is the gated download IRI (fetch with the Bearer, not <img src>). */
export interface MaintenanceAttachment {
  id: string;
  filename: string;
  contentType: string;
  size: number;
  url: string;
}

export interface MaintenanceRecord {
  id: string; // the record IRI (@id, normalized)
  items: MaintenanceItem[]; // the services this visit covered — one row, many items
  attachments?: MaintenanceAttachment[]; // the visit's bills (PDF/JPEG/PNG)
  label?: string | null; // visit-level label, e.g. "révision annuelle"
  performedAt: string; // YYYY-MM-DD
  usageValue?: number | null;
  usageUnit?: string | null;
  notes?: string | null;
  performedBy?: string | null;
  costCents?: number | null; // visit bill total, integer minor units
  currency?: string | null;
  source: string;
}

/** The usage axis of a forecast item — engine-hours / km / mi against the service's cadence. */
export interface ForecastUsage {
  last: number;
  due: number;
  remaining: number;
  unit: 'hours' | 'km' | 'mi';
}

/**
 * One predicted-due service from the per-vehicle forecast. Every due is `rule: "schedule"` — driven by
 * a per-vehicle cadence the user/LLM set (there is no generic fallback). Usage-axis items carry
 * `usage`; time-axis items carry `dueAt`/`projectedDueAt`.
 */
export interface ForecastDue {
  type: string;
  action: 'change' | 'replace' | 'clean' | 'inspect' | null;
  component: string | null;
  rule: 'schedule';
  lastPerformedAt: string | null; // Y-m-d
  dueAt: string | null; // Y-m-d, time axis
  projectedDueAt: string | null; // Y-m-d, usage axis projected onto the calendar (estimate)
  overdue: boolean;
  daysUntilDue: number | null;
  usage: ForecastUsage | null;
}

/** The per-vehicle forecast (GET …/forecast): due items sorted soonest-first + coverage gaps. */
export interface VehicleForecast {
  vehicle: string; // the vehicle IRI
  due: ForecastDue[];
  gaps: string[]; // recurring types with a known cadence but no logged baseline
}

/**
 * The compact next-due summary carried on each parc-listing member (collection only), driving the
 * sidebar rail's one-line forecast. Null when nothing is predictable yet.
 */
export interface NextDue {
  type: string;
  action: 'change' | 'replace' | 'clean' | 'inspect' | null;
  label: string;
  rule: 'schedule';
  overdue: boolean;
  dueAt: string | null;
  projectedDueAt: string | null;
  daysUntilDue: number | null;
  usageRemaining: number | null;
  usageUnit: 'hours' | 'km' | 'mi' | null;
}

export interface Vehicle {
  id: VehicleId;
  initials: string;
  make: string;
  model: string;
  name: string;
  subtitle: string; // "Voiture · 2007"
  vehicleType?: string; // raw type — drives the default usage unit (groundscare → h)
  usage: string; // "142 000 km"
  ago: string; // "il y a 4 h"
  status: VehicleStatus;
  year?: number;
  engineVariant?: string; // engine/trim label, e.g. "2.0 16V Coupé"
  engineCode?: string; // motor code / displacement, e.g. "WJY" or "1.9"
  kType?: number;
  plate?: string;
  verified?: boolean;
  mileage: string; // "142 000" — display form of usageValue
  mileageUnit: string; // "km"
  usageValue?: number; // raw reading, for inline editing
  usageUnit?: string; // "km" | "mi" | "h"
  facts?: VehicleFact[]; // populated from the item GET on select
  maintenanceHealth?: MaintenanceHealth; // API-computed rollup, item GET only (listing omits it)
  nextService: NextService;
  openTasks: number;
  plannedTasks: PlannedTask[]; // the open checklist, populated from the item GET
  maintenanceRecords?: MaintenanceRecord[]; // past services, loaded from the records subresource
  forecast?: VehicleForecast; // maintenance forecast, loaded from the forecast subresource
  serviceSchedule?: ServiceRule[]; // the recurring maintenance rules, loaded from the service-schedule subresource
  nextDue?: NextDue | null; // compact next-due, carried on the listing for the rail line
  cost: { total: string; period: string; delta: string };
}

/** A stored spec about a vehicle, with provenance — read-only on the card. Keyed by `key`
 * (one fact per key per vehicle); it has no own identity since facts are embedded server-side. */
export interface VehicleFact {
  key: string;
  value: string;
  unit?: string | null;
  source: string;
  verified: boolean;
  url?: string | null;
}

/**
 * One recurring maintenance-schedule rule on a vehicle — a `ServiceInterval`. The cadence drives the
 * forecast: a usage cadence (everyUsage), a time cadence (everyMonths), or a break-in first interval
 * (firstUsageValue). Identity is (type, action, component) — re-POSTing the same identity corrects the
 * cadence in place (upsert). `verified` is true only for catalog sources (partsapi/siv) — show a badge.
 */
export interface ServiceRule {
  id: string; // the rule's ULID — used to DELETE it
  vehicleId: string;
  type: string; // service, snake_case (SERVICE_TYPES)
  action: 'change' | 'replace' | 'clean' | 'inspect';
  component?: string | null; // sub-part; part of the dedup identity
  everyUsage?: { value: number; unit: 'hours' | 'km' | 'mi' } | null;
  everyMonths?: number | null;
  firstUsageValue?: number | null; // break-in override
  source: 'user' | 'web' | 'partsapi' | 'siv' | 'llm';
  verified: boolean; // true only for partsapi/siv (catalog)
  url?: string | null;
}

/**
 * A call-to-action button. Either kind, never both:
 *  - `instruction`: re-runs the agent with that text (NL follow-up).
 *  - `patchTask`: merges parts onto an existing task via a direct PATCH — no agent turn; the
 *    shopping-list block re-renders from the live task.
 */
export interface CtaAction {
  label: string;
  instruction?: string;
  patchTask?: { id: string; parts: PlannedPart[] };
}

/** The agent's call-to-action card: a headline, an optional finding line, and next-step buttons. */
export interface Cta {
  headline: string;
  finding?: string;
  actions: CtaAction[];
}

/** The transient focused fact panel (UI label "Données"): which fact keys to spotlight. */
export interface FactPanel {
  keys: string[];
  title?: string;
  expanded: boolean;
}

/**
 * The raw garage vehicle as the API serves it (GET /garage/vehicles) — the same projection the MCP
 * list_vehicles tool returns. `@id` is the dereferenceable IRI the agent uses as the vehicle handle
 * (select_vehicle, log_maintenance), so it doubles as our VehicleId.
 */
export interface VehicleSummary {
  '@id': string;
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
  // The maintenance-health rollup — served on the item GET only, absent on the collection listing.
  health?: MaintenanceHealth;
  // The compact next-due summary — carried on the collection listing for the rail line; may be null.
  nextDue?: NextDue | null;
  // Populated on the item GET only. The API serves the task IRI as `@id`; vehicleFromSummary
  // normalizes it to `id` for the UI.
  plannedTasks?: Array<PlannedTask & { '@id'?: string }>;
  addedAt: string;
}

export interface Toast {
  id: number;
  tone: Tone;
  message: string;
}

export interface DashboardState {
  vehicles: Vehicle[];
  selectedId: VehicleId;
  factPanel: FactPanel | null; // transient agent answer surface; null when dismissed
  cta: Cta | null; // the agent's call-to-action card; null when dismissed
  toasts: Toast[];
  live: string[]; // recent Mercure events (debug rail)
  recency: string[]; // vehicle segments, most-recently-visited first (persisted to localStorage)
}

/**
 * The headless agent → UI vocabulary. The agent never returns chat; it returns these typed
 * actions and the reducer maps them to state. Starts small, converges toward AG-UI.
 */
export type UIAction =
  | { type: 'setVehicles'; vehicles: VehicleSummary[]; preferredId?: VehicleId }
  | { type: 'selectVehicle'; id: VehicleId }
  | { type: 'showFact'; keys: string[]; title?: string }
  | { type: 'proposeActions'; headline: string; finding?: string; actions: CtaAction[] }
  | { type: 'toast'; tone: Tone; message: string }
  // Transient progress narration ("Je consulte le catalogue…"), emitted per tool the agent runs.
  // Feeds only the heartbeat line — never a toast popup — and carries no reducer state.
  | { type: 'status'; message: string }
  | { type: 'turn'; phase: 'start' | 'end' };
