import type { DashboardState, Toast, UIAction, Vehicle, VehicleId, VehicleStatus, VehicleSummary } from './types';

export type Event =
  | { kind: 'action'; action: UIAction }
  | { kind: 'dismissToast'; id: number }
  | { kind: 'live'; message: string }
  // UI-local dismissals of the transient agent surfaces — no agent round-trip.
  | { kind: 'dismissFactPanel' }
  | { kind: 'expandFactPanel' }
  | { kind: 'dismissCta' }
  // Merge fields fetched/edited locally (facts from the item GET, an optimistic plate/usage edit)
  // onto one vehicle — distinct from the agent's UIAction vocabulary.
  | { kind: 'patchVehicle'; id: VehicleId; patch: Partial<Vehicle> }
  // A garage write pushed the full vehicle read-model over Mercure — merge it in, no refetch.
  | { kind: 'upsertVehicle'; summary: VehicleSummary };

let toastSeq = 0;

export function reduce(state: DashboardState, ev: Event): DashboardState {
  switch (ev.kind) {
    case 'dismissToast':
      return { ...state, toasts: state.toasts.filter((t) => t.id !== ev.id) };
    case 'live':
      return { ...state, live: [ev.message, ...state.live].slice(0, 6) };
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
      const vehicles = exists
        ? state.vehicles.map((v) =>
            v.id === fresh.id ? { ...fresh, plannedTasks: v.plannedTasks, openTasks: v.openTasks } : v,
          )
        : [...state.vehicles, fresh];
      // Auto-focus the first vehicle to land, so a freshly-created one is shown without a click.
      const selectedId = state.selectedId === '' ? fresh.id : state.selectedId;
      return { ...state, vehicles, selectedId };
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
      const vehicles = a.vehicles.map((s) => {
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
          plannedTasks: s.plannedTasks?.length ? fresh.plannedTasks : prev.plannedTasks,
        };
      });
      const selectedId = vehicles.some((v) => v.id === state.selectedId)
        ? state.selectedId
        : (vehicles[0]?.id ?? state.selectedId);
      return { ...state, vehicles, selectedId };
    }
    case 'selectVehicle':
      // Focusing a new vehicle drops the previous vehicle's transient answer surfaces.
      return state.vehicles.some((v) => v.id === a.id)
        ? { ...state, selectedId: a.id, factPanel: null, cta: null }
        : state;
    case 'showFact':
      return { ...state, factPanel: { keys: a.keys, title: a.title, expanded: false } };
    case 'proposeActions':
      return { ...state, cta: { headline: a.headline, finding: a.finding, actions: a.actions } };
    case 'toast': {
      const toast: Toast = { id: ++toastSeq, tone: a.tone, message: a.message };
      return { ...state, toasts: [...state.toasts, toast] };
    }
    case 'turn':
      // A turn-lifecycle cue is consumed in App.onFrame to drive the busy state; it never carries
      // reducer state. Listed so the switch stays exhaustive over UIAction.
      return state;
  }
}

/**
 * Coarse, honest health from the checklist — no invented telemetry. No open tasks ⇒ healthy; an
 * open task whose usage target is already passed ⇒ overdue; otherwise service is due soon. The
 * overdue check needs the per-task targets, which only the item GET carries, so the listing (which
 * has the count but not the tasks) lands on "due soon" until the item refines it.
 */
function computeHealth(s: VehicleSummary): { value: number; label: string; status: VehicleStatus } {
  const open = s.openTasks ?? 0;
  if (0 === open) return { value: 100, label: 'Véhicule suivi', status: 'ok' };
  const usage = s.usageValue ?? null;
  const overdue =
    null !== usage && (s.plannedTasks ?? []).some((t) => t.target != null && t.target.value <= usage);
  if (overdue) return { value: 55, label: 'Entretien en retard', status: 'overdue' };
  return { value: 78, label: `${open} tâche${open > 1 ? 's' : ''} en attente`, status: 'soon' };
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
 * cost total lands — see TODO_UI Part B), while `health`/`status` are computed read-only from the
 * open/overdue checklist. None of these are agent-piloted.
 */
export function vehicleFromSummary(s: VehicleSummary): Vehicle {
  const type = VEHICLE_TYPE_LABELS[s.vehicleType] ?? s.vehicleType;
  const hasUsage = s.usageValue != null && !!s.usageUnit;
  const health = computeHealth(s);
  return {
    id: s['@id'],
    initials: s.make.slice(0, 2).toUpperCase(),
    name: `${s.make} ${s.model}`.trim(),
    subtitle: s.year ? `${type} · ${s.year}` : type,
    vehicleType: s.vehicleType,
    usage: hasUsage ? `${formatReading(s.usageValue!)} ${s.usageUnit}` : `${s.factCount} donnée${s.factCount > 1 ? 's' : ''}`,
    ago: '',
    status: health.status,
    year: s.year ?? undefined,
    modification: s.modification ?? undefined,
    kType: s.kType ?? undefined,
    plate: s.plate ?? undefined,
    verified: 'verified' === s.source,
    health: health.value,
    healthLabel: health.label,
    mileage: hasUsage ? formatReading(s.usageValue!) : '—',
    mileageUnit: hasUsage ? s.usageUnit! : '',
    usageValue: s.usageValue ?? undefined,
    usageUnit: s.usageUnit ?? undefined,
    facts: s.facts,
    nextService: { value: 0, unit: '' },
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
