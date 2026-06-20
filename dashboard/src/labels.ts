/** French labels for the known maintenance/service types (shared by the checklist + history rail). */
export const SERVICE_LABELS: Record<string, string> = {
  oil_change: 'Vidange',
  oil_filter: 'Filtre à huile',
  air_filter: 'Filtre à air',
  fuel_filter: 'Filtre à carburant',
  cabin_filter: 'Filtre d’habitacle',
  spark_plug: 'Bougies',
  tires: 'Pneus',
  brakes: 'Freins',
  coolant: 'Liquide de refroidissement',
  brake_fluid: 'Liquide de frein',
  timing_belt: 'Courroie de distribution',
  gearbox_oil: 'Huile de boîte',
  battery: 'Batterie',
  inspection: 'Contrôle technique',
  repair: 'Réparation',
  other: 'Autre',
};

/** A service type rendered for humans; unknown types fall back to the raw snake_case label. */
export function serviceLabel(type: string): string {
  return SERVICE_LABELS[type] ?? type;
}

/** The known types offered in the quick-add datalist. */
export const SERVICE_TYPES = Object.keys(SERVICE_LABELS);
