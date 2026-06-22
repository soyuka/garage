import { orderByRecency, recordVisit } from './recency';
import type { DashboardState, Toast, UIAction, Vehicle, VehicleId, VehicleStatus, VehicleSummary } from './types';

export type Event =
  | { kind: 'action'; action: UIAction }
  | { kind: 'dismissToast'; id: number }
  | { kind: 'live'; message: string }
  // The live feed sentence is transient — cleared on a timer after the last frame (see App.onFrame).
  | { kind: 'clearLive' }
  // UI-local dismissals of the transient agent surfaces — no agent round-trip.
  | { kind: 'dismissFactPanel' }
  | { kind: 'expandFactPanel' }
  | { kind: 'dismissCta' }
  // Merge fields fetched/edited locally (facts from the item GET, an optimistic plate/usage edit)
  // onto one vehicle — distinct from the agent's UIAction vocabulary.
  | { kind: 'patchVehicle'; id: VehicleId; patch: Partial<Vehicle> }
  // A garage write pushed the full vehicle read-model over Mercure — merge it in, no refetch.
  | { kind: 'upsertVehicle'; summary: VehicleSummary }
  // A vehicle was soft-deleted (this tab or another) — drop it from the parc and reselect.
  | { kind: 'removeVehicle'; id: VehicleId };

let toastSeq = 0;

export function reduce(state: DashboardState, ev: Event): DashboardState {
  switch (ev.kind) {
    case 'dismissToast':
      return { ...state, toasts: state.toasts.filter((t) => t.id !== ev.id) };
    case 'live':
      return { ...state, live: [ev.message, ...state.live].slice(0, 6) };
    case 'clearLive':
      return state.live.length === 0 ? state : { ...state, live: [] };
    case 'dismissFactPanel':
      return { ...state, factPanel: null };
    case 'expandFactPanel':
      return state.factPanel ? { ...state, factPanel: { ...state.factPanel, expanded: true } } : state;
    case 'dismissCta':
      return { ...state, cta: null };
    case 'patchVehicle':
      return {
        ...state,
        vehicles: state.vehicles.map((v) => (v.id === ev.id ? { ...v, ...ev.patch } : v)),
      };
    case 'upsertVehicle': {
      // The pushed resource is the vehicle scalars + facts (no checklist — that is its own
      // subresource). Replace the row wholesale, but carry over the locally-loaded checklist so a
      // live frame doesn't blank it; App.loadTasks refetches it right after for this vehicle.
      const fresh = vehicleFromSummary(ev.summary);
      const exists = state.vehicles.some((v) => v.id === fresh.id);
      const next = exists
        ? state.vehicles.map((v) =>
            v.id === fresh.id
              ? { ...fresh, plannedTasks: v.plannedTasks, openTasks: v.openTasks, maintenanceRecords: v.maintenanceRecords, forecast: v.forecast, serviceSchedule: v.serviceSchedule }
              : v,
          )
        : [...state.vehicles, fresh];
      const vehicles = orderByRecency(next, state.recency);
      // Auto-focus the first vehicle to land, so a freshly-created one is shown without a click.
      const selectedId = state.selectedId === '' ? fresh.id : state.selectedId;
      return { ...state, vehicles, selectedId };
    }
    case 'removeVehicle': {
      const vehicles = state.vehicles.filter((v) => v.id !== ev.id);
      // If the removed vehicle was selected, fall back to the first remaining one.
      const selectedId = state.selectedId === ev.id ? (vehicles[0]?.id ?? '') : state.selectedId;
      return { ...state, vehicles, selectedId, factPanel: null, cta: null };
    }
    case 'action':
      return applyAction(state, ev.action);
  }
}

function applyAction(state: DashboardState, a: UIAction): DashboardState {
  switch (a.type) {
    case 'setVehicles': {
      // The listing omits facts + the planned-task detail (only the item GET carries them), so carry
      // those over — otherwise a live collection refetch wipes the open card's facts/checklist. The
      // openTasks COUNT does ride the listing, so it stays fresh from `s`.
      const merged = a.vehicles.map((s) => {
        const fresh = vehicleFromSummary(s);
        const prev = state.vehicles.find((v) => v.id === fresh.id);
        if (!prev) return fresh;
        // The listing never carries facts/checklist detail (item GET only) — it sends empty
        // arrays, not absent ones. Treat absent OR empty as "not loaded" and keep what the item
        // GET merged, so a collection refetch (incl. StrictMode's double mount) never blanks the
        // open card. Real clearing flows through the item GET → patchVehicle path.
        return {
          ...fresh,
          facts: s.facts?.length ? fresh.facts : prev.facts,
          // The rollup rides the item GET only; a listing refetch must not blank what it loaded.
          maintenanceHealth: s.health ? fresh.maintenanceHealth : prev.maintenanceHealth,
          plannedTasks: s.plannedTasks?.length ? fresh.plannedTasks : prev.plannedTasks,
          maintenanceRecords: prev.maintenanceRecords,
          // The forecast rides its own subresource (item GET only); a listing refetch must not blank it.
          forecast: prev.forecast,
          // The schedule rides its own subresource too — preserve it across a listing refetch.
          serviceSchedule: prev.serviceSchedule,
        };
      });
      const vehicles = orderByRecency(merged, state.recency);
      const selectedId = vehicles.some((v) => v.id === state.selectedId)
        ? state.selectedId
        // Prefer the URL-derived vehicle (reload restoration), else the most-recent (first) car.
        : (vehicles.find((v) => v.id === a.preferredId)?.id ?? vehicles[0]?.id ?? state.selectedId);
      return { ...state, vehicles, selectedId };
    }
    case 'selectVehicle': {
      // Records the visit and re-orders the rail so the clicked card jumps to front immediately. Does
      // NOT clear the transient answer surfaces (cta/factPanel): the agent emits select_vehicle in the
      // SAME turn as propose_actions/show_fact — and sometimes AFTER them — so clearing here wiped the
      // very CTA/panel it had just raised (the fade-in-then-away bug). Those surfaces are turn-scoped,
      // not vehicle-scoped; they are cleared at user boundaries instead (a new turn in App.send, manual
      // navigation in App.onSelect).
      if (!state.vehicles.some((v) => v.id === a.id)) return state;
      const recency = recordVisit(state.recency, a.id);
      const vehicles = orderByRecency(state.vehicles, recency);
      return { ...state, selectedId: a.id, vehicles, recency };
    }
    case 'showFact':
      return { ...state, factPanel: { keys: a.keys, title: a.title, expanded: false } };
    case 'proposeActions':
      return { ...state, cta: { headline: a.headline, finding: a.finding, actions: a.actions } };
    case 'toast': {
      const toast: Toast = { id: ++toastSeq, tone: a.tone, message: a.message };
      return { ...state, toasts: [...state.toasts, toast] };
    }
    case 'status':
    case 'turn':
      // Transient cues consumed in App.onFrame (status → heartbeat line, turn → busy state); neither
      // carries reducer state. Listed so the switch stays exhaustive over UIAction.
      return state;
  }
}

/**
 * Coarse, honest status from the checklist — no invented telemetry. Drives the sidebar rail dot
 * (CarRail's STATUS_COLOR) on the collection listing, which the API maintenance-health rollup does
 * not cover (item GET only). No open tasks ⇒ healthy; an open task whose usage target is already
 * passed ⇒ overdue; otherwise service is due soon. The overdue check needs the per-task targets,
 * which only the item GET carries, so the listing (which has the count but not the tasks) lands on
 * "due soon" until the item refines it.
 */
function computeStatus(s: VehicleSummary): VehicleStatus {
  const open = s.openTasks ?? 0;
  if (0 === open) return 'ok';
  const usage = s.usageValue ?? null;
  const overdue =
    null !== usage && (s.plannedTasks ?? []).some((t) => t.target != null && t.target.value <= usage);
  return overdue ? 'overdue' : 'soon';
}

const VEHICLE_TYPE_LABELS: Record<string, string> = {
  car: 'Voiture',
  motorcycle: 'Moto',
  truck: 'Utilitaire',
  groundscare: 'Matériel espaces verts',
};

/**
 * Projects a raw API vehicle onto the dashboard's display model. The API serves the durable facts
 * (make/model/type/plate…); the UI-only `cost` has no source yet (a placeholder until the maintenance
 * cost total lands — see TODO_UI Part B), while `status` is computed read-only from the
 * open/overdue checklist. None of these are agent-piloted.
 */
export function vehicleFromSummary(s: VehicleSummary): Vehicle {
  const type = VEHICLE_TYPE_LABELS[s.vehicleType] ?? s.vehicleType;
  const hasUsage = s.usageValue != null && !!s.usageUnit;
  return {
    id: s['@id'],
    initials: s.make.slice(0, 2).toUpperCase(),
    make: s.make,
    model: s.model,
    name: `${s.make} ${s.model}`.trim(),
    subtitle: s.year ? `${type} · ${s.year}` : type,
    vehicleType: s.vehicleType,
    usage: hasUsage ? `${formatReading(s.usageValue!)} ${s.usageUnit}` : `${s.factCount} donnée${s.factCount > 1 ? 's' : ''}`,
    ago: '',
    status: computeStatus(s),
    year: s.year ?? undefined,
    modification: s.modification ?? undefined,
    engineCode: s.engineCode ?? undefined,
    kType: s.kType ?? undefined,
    plate: s.plate ?? undefined,
    verified: 'verified' === s.source,
    mileage: hasUsage ? formatReading(s.usageValue!) : '—',
    mileageUnit: hasUsage ? s.usageUnit! : '',
    usageValue: s.usageValue ?? undefined,
    usageUnit: s.usageUnit ?? undefined,
    facts: s.facts,
    maintenanceHealth: s.health,
    nextService: { value: 0, unit: '' },
    // The compact next-due rides the listing (collection) for the rail line; absent on a stale row.
    nextDue: s.nextDue ?? null,
    // The open checklist + its count load separately from the planned-tasks subresource (see
    // App.loadTasks); the projection starts empty and is filled by a patchVehicle merge.
    openTasks: 0,
    plannedTasks: [],
    cost: { total: '—', period: '12 derniers mois', delta: '' },
  };
}

// French thousands grouping (142000 → "142 000"), matching the wireframe's tabular readings.
function formatReading(value: number): string {
  return value.toLocaleString('fr-FR').replace(/ /g, ' ');
}
