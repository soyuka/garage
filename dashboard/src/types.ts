export type VehicleId = string;
export type VehicleStatus = 'ok' | 'soon' | 'overdue';
export type Tone = 'info' | 'ok' | 'warn' | 'alarm';

export interface NextService {
  value: number;
  unit: string;
}

export interface PlannedPart {
  ref: string;
  label?: string;
  brand?: string;
  qty?: number;
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
  modification?: string; // engine/trim label, e.g. "2.0 16V Coupé"
  engineCode?: string; // motor code / displacement, e.g. "WJY" or "1.9"
  kType?: number;
  plate?: string;
  verified?: boolean;
  health: number; // 0–100
  healthLabel: string; // "Véhicule sain"
  mileage: string; // "142 000" — display form of usageValue
  mileageUnit: string; // "km"
  usageValue?: number; // raw reading, for inline editing
  usageUnit?: string; // "km" | "mi" | "h"
  facts?: VehicleFact[]; // populated from the item GET on select
  nextService: NextService;
  openTasks: number;
  plannedTasks: PlannedTask[]; // the open checklist, populated from the item GET
  maintenanceRecords?: MaintenanceRecord[]; // past services, loaded from the records subresource
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
  modification?: string | null;
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
