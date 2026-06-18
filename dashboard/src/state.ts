import type { DashboardState, Toast, UIAction, Vehicle, VehicleId, VehicleSummary } from './types';

export type Event =
  | { kind: 'action'; action: UIAction }
  | { kind: 'dismissToast'; id: number }
  | { kind: 'live'; message: string }
  // Merge fields fetched/edited locally (facts from the item GET, an optimistic plate/usage edit)
  // onto one vehicle — distinct from the agent's UIAction vocabulary.
  | { kind: 'patchVehicle'; id: VehicleId; patch: Partial<Vehicle> };

let toastSeq = 0;

export function reduce(state: DashboardState, ev: Event): DashboardState {
  switch (ev.kind) {
    case 'dismissToast':
      return { ...state, toasts: state.toasts.filter((t) => t.id !== ev.id) };
    case 'live':
      return { ...state, live: [ev.message, ...state.live].slice(0, 6) };
    case 'patchVehicle':
      return {
        ...state,
        vehicles: state.vehicles.map((v) => (v.id === ev.id ? { ...v, ...ev.patch } : v)),
      };
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
        return {
          ...fresh,
          facts: undefined === s.facts ? prev.facts : fresh.facts,
          plannedTasks: undefined === s.plannedTasks ? prev.plannedTasks : fresh.plannedTasks,
        };
      });
      const selectedId = vehicles.some((v) => v.id === state.selectedId)
        ? state.selectedId
        : (vehicles[0]?.id ?? state.selectedId);
      return { ...state, vehicles, selectedId };
    }
    case 'selectVehicle':
      return state.vehicles.some((v) => v.id === a.id) ? { ...state, selectedId: a.id } : state;
    case 'renderSuggestions':
      return { ...state, suggestions: a.chips };
    case 'toast': {
      const toast: Toast = { id: ++toastSeq, tone: a.tone, message: a.message };
      return { ...state, toasts: [...state.toasts, toast] };
    }
    case 'patchPanel':
      return {
        ...state,
        vehicles: state.vehicles.map((v) => (v.id === state.selectedId ? patchPanel(v, a) : v)),
      };
  }
}

function patchPanel(v: Vehicle, a: Extract<UIAction, { type: 'patchPanel' }>): Vehicle {
  switch (a.panel) {
    case 'nextService':
      return { ...v, nextService: a.data };
    case 'health':
      return { ...v, health: a.data.value, healthLabel: a.data.label ?? v.healthLabel };
    case 'cost':
      return { ...v, cost: a.data };
  }
}

const VEHICLE_TYPE_LABELS: Record<string, string> = {
  car: 'Voiture',
  motorcycle: 'Moto',
  truck: 'Utilitaire',
  groundscare: 'Matériel espaces verts',
};

/**
 * Projects a raw API vehicle onto the dashboard's display model. The API serves the durable facts
 * (make/model/type/plate…); the UI-only fields (health, cost, next service) have no source yet, so
 * they get neutral placeholders until the agent patches them or a future read fills them in.
 */
export function vehicleFromSummary(s: VehicleSummary): Vehicle {
  const type = VEHICLE_TYPE_LABELS[s.vehicleType] ?? s.vehicleType;
  const hasUsage = s.usageValue != null && !!s.usageUnit;
  return {
    id: s['@id'],
    initials: s.make.slice(0, 2).toUpperCase(),
    name: `${s.make} ${s.model}`.trim(),
    subtitle: s.year ? `${type} · ${s.year}` : type,
    vehicleType: s.vehicleType,
    usage: hasUsage ? `${formatReading(s.usageValue!)} ${s.usageUnit}` : `${s.factCount} fait${s.factCount > 1 ? 's' : ''}`,
    ago: '',
    status: 'ok',
    year: s.year ?? undefined,
    kType: s.kType ?? undefined,
    plate: s.plate ?? undefined,
    verified: 'verified' === s.source,
    health: 100,
    healthLabel: 'Véhicule suivi',
    mileage: hasUsage ? formatReading(s.usageValue!) : '—',
    mileageUnit: hasUsage ? s.usageUnit! : '',
    usageValue: s.usageValue ?? undefined,
    usageUnit: s.usageUnit ?? undefined,
    facts: s.facts,
    nextService: { value: 0, unit: '' },
    openTasks: s.openTasks ?? 0,
    plannedTasks: s.plannedTasks ?? [],
    cost: { total: '—', period: '12 derniers mois', delta: '' },
  };
}

// French thousands grouping (142000 → "142 000"), matching the wireframe's tabular readings.
function formatReading(value: number): string {
  return value.toLocaleString('fr-FR').replace(/ /g, ' ');
}
