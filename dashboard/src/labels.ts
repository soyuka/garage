import type { MaintenanceHealth } from './types';

// French display labels for backend snake_case service types + health status. A lookup with an
// honest humanizing fallback (so an unmapped type still reads, never a raw key on screen).

const SERVICE_LABEL: Record<string, string> = {
  oil_change: 'Vidange',
  technical_inspection: 'Contrôle technique',
  inspection: 'Contrôle technique', // the backend's periodic-inspection type key (live)
  spark_plug: 'Bougies', // backend uses the singular key; keep the plural alias below too
  brake_fluid: 'Liquide de frein',
  brake_pads: 'Plaquettes de frein',
  brake_discs: 'Disques de frein',
  air_filter: 'Filtre à air',
  cabin_filter: "Filtre d'habitacle",
  fuel_filter: 'Filtre à carburant',
  oil_filter: 'Filtre à huile',
  timing_belt: 'Courroie de distribution',
  spark_plugs: 'Bougies',
  coolant: 'Liquide de refroidissement',
  chain: 'Chaîne',
  tyres: 'Pneus',
};

export function serviceLabel(type: string): string {
  return SERVICE_LABEL[type] ?? type.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

/** Common service types, for the checklist add/edit type datalist (free-text still allowed). */
export const SERVICE_TYPES: readonly string[] = [
  'oil_change',
  'oil_filter',
  'air_filter',
  'cabin_filter',
  'fuel_filter',
  'brake_pads',
  'brake_discs',
  'brake_fluid',
  'timing_belt',
  'spark_plug',
  'coolant',
  'tyres',
  'inspection',
];

// French labels for known vehicle-fact keys. The `{type}_ref` family mirrors the part-derived facts
// the backend projects from logged maintenance items. Unmapped keys fall back to a humanized form
// (so a free-text key the user adds still reads, never a raw snake_case key on screen).
const FACT_LABEL: Record<string, string> = {
  oil_type: 'Type d’huile',
  oil_capacity: 'Capacité d’huile',
  coolant: 'Liquide de refroidissement',
  coolant_type: 'Liquide de refroidissement',
  brake_fluid: 'Liquide de frein',
  spark_plug_ref: 'Référence bougie',
  oil_filter_ref: 'Référence filtre à huile',
  air_filter_ref: 'Référence filtre à air',
  fuel_filter_ref: 'Référence filtre à carburant',
  cabin_filter_ref: 'Référence filtre habitacle',
  timing_belt_ref: 'Référence courroie de distribution',
  battery_ref: 'Référence batterie',
  brakes_ref: 'Référence freins',
  tires_ref: 'Référence pneus',
  gearbox_oil_ref: 'Référence huile de boîte',
};

export function factLabel(key: string): string {
  return FACT_LABEL[key] ?? key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

export const HEALTH_LABEL: Record<MaintenanceHealth['status'], string> = {
  green: 'Sain',
  amber: 'Service à prévoir',
  red: 'En retard',
  unknown: 'Non suivi',
};
