export type VehicleId = string;
export type VehicleStatus = 'ok' | 'soon' | 'overdue';
export type Tone = 'info' | 'ok' | 'warn' | 'alarm';

export interface NextService {
  value: number;
  unit: string;
}

export interface Vehicle {
  id: VehicleId;
  initials: string;
  name: string;
  subtitle: string; // "Voiture · 2007"
  usage: string; // "142 000 km"
  ago: string; // "il y a 4 h"
  status: VehicleStatus;
  year?: number;
  kType?: number;
  verified?: boolean;
  health: number; // 0–100
  healthLabel: string; // "Véhicule sain"
  mileage: string; // "142 000"
  mileageUnit: string; // "km"
  nextService: NextService;
  openTasks: number;
  cost: { total: string; period: string; delta: string };
}

export interface SuggestionChip {
  label: string;
}

export interface Toast {
  id: number;
  tone: Tone;
  message: string;
}

export interface DashboardState {
  vehicles: Vehicle[];
  selectedId: VehicleId;
  suggestions: SuggestionChip[];
  toasts: Toast[];
  live: string[]; // recent Mercure events (debug rail)
}

/**
 * The headless agent → UI vocabulary. The agent never returns chat; it returns these typed
 * actions and the reducer maps them to state. Starts small, converges toward AG-UI.
 */
export type UIAction =
  | { type: 'selectVehicle'; id: VehicleId }
  | { type: 'renderSuggestions'; chips: SuggestionChip[] }
  | { type: 'patchPanel'; panel: 'nextService'; data: NextService }
  | { type: 'toast'; tone: Tone; message: string };
