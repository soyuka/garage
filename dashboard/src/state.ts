import type { DashboardState, Toast, UIAction, Vehicle, VehicleSummary } from './types';

export type Event =
  | { kind: 'action'; action: UIAction }
  | { kind: 'dismissToast'; id: number }
  | { kind: 'live'; message: string };

let toastSeq = 0;

export function reduce(state: DashboardState, ev: Event): DashboardState {
  switch (ev.kind) {
    case 'dismissToast':
      return { ...state, toasts: state.toasts.filter((t) => t.id !== ev.id) };
    case 'live':
      return { ...state, live: [ev.message, ...state.live].slice(0, 6) };
    case 'action':
      return applyAction(state, ev.action);
  }
}

function applyAction(state: DashboardState, a: UIAction): DashboardState {
  switch (a.type) {
    case 'setVehicles': {
      const vehicles = a.vehicles.map(vehicleFromSummary);
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
  return {
    id: s['@id'],
    initials: s.make.slice(0, 2).toUpperCase(),
    name: `${s.make} ${s.model}`.trim(),
    subtitle: s.year ? `${type} · ${s.year}` : type,
    usage: `${s.factCount} fait${s.factCount > 1 ? 's' : ''}`,
    ago: '',
    status: 'ok',
    year: s.year ?? undefined,
    kType: s.kType ?? undefined,
    verified: 'verified' === s.source,
    health: 100,
    healthLabel: 'Véhicule suivi',
    mileage: '—',
    mileageUnit: '',
    nextService: { value: 0, unit: '' },
    openTasks: 0,
    cost: { total: '—', period: '12 derniers mois', delta: '' },
  };
}
