import type { MaintenanceHealth } from './types';

// French display labels for backend snake_case service types + health status. A lookup with an
// honest humanizing fallback (so an unmapped type still reads, never a raw key on screen).

// Backend spelling variants → the canonical service-type key. Declared ONCE here (the service-type
// vocabulary's home) and reused by the scene's region lookups, so an alias never drifts between the
// label table and the car-region table. Resolve every incoming type through this before a lookup.
export const SERVICE_ALIASES: Record<string, string> = {
  technical_inspection: 'inspection', // the backend's live periodic-inspection key is `inspection`
  spark_plugs: 'spark_plug', // backend uses the singular key
  tires: 'tyres', // spelling variant
};

/** Fold a backend spelling variant onto its canonical service-type key (identity for unknown types). */
export function canonicalService(type: string): string {
  return SERVICE_ALIASES[type] ?? type;
}

const SERVICE_LABEL: Record<string, string> = {
  oil_change: 'Vidange',
  inspection: 'Contrôle technique',
  spark_plug: 'Bougies',
  brake_fluid: 'Liquide de frein',
  brake_pads: 'Plaquettes de frein',
  brake_discs: 'Disques de frein',
  air_filter: 'Filtre à air',
  cabin_filter: "Filtre d'habitacle",
  fuel_filter: 'Filtre à carburant',
  oil_filter: 'Filtre à huile',
  timing_belt: 'Courroie de distribution',
  coolant: 'Liquide de refroidissement',
  chain: 'Chaîne',
  tyres: 'Pneus',
};

export function serviceLabel(type: string): string {
  const t = canonicalService(type);
  return SERVICE_LABEL[t] ?? t.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
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
