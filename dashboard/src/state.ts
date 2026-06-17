import type { DashboardState, Toast, UIAction } from './types';

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
        vehicles: state.vehicles.map((v) =>
          v.id === state.selectedId ? { ...v, nextService: a.data } : v,
        ),
      };
  }
}
