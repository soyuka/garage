import type { Locale } from './i18n/locale';
import type { MaintenanceHealth } from './types';

// Display labels for the backend's snake_case service types + fact keys, in both languages. A lookup
// with an honest humanizing fallback (so an unmapped type still reads, never a raw key on screen).
//
// These live here rather than in i18n/fr.ts because they are keyed by the BACKEND's vocabulary, not
// by UI location — a different shape from chrome copy, and one that has to stay aligned with
// `SERVICE_ALIASES` and the scene's region lookups.

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

export const SERVICE_LABEL: Record<Locale, Record<string, string>> = {
  fr: {
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
    // Repairs, not scheduled services — they carry no cadence but they DO land on the checklist, and
    // without a label here they rendered as the humanized raw key ("Engine mount").
    engine_mount: 'Support moteur',
    coolant_temperature_sensor: 'Sonde de température',
    water_pump: 'Pompe à eau',
    thermostat: 'Thermostat',
    tensioner_pulley: 'Galet tendeur',
    ignition_coil: "Bobine d'allumage",
    injector: 'Injecteur',
    brakes: 'Freins',
    gearbox_oil: 'Huile de boîte',
    clutch: 'Embrayage',
    battery: 'Batterie',
    distribution: 'Distribution',
  },
  en: {
    oil_change: 'Oil change',
    // The French Contrôle Technique is a specific national inspection; "Roadworthiness test" is what
    // it IS, and is the term used across the EU. Not "MOT", which is the British scheme.
    inspection: 'Roadworthiness test',
    spark_plug: 'Spark plugs',
    brake_fluid: 'Brake fluid',
    brake_pads: 'Brake pads',
    brake_discs: 'Brake discs',
    air_filter: 'Air filter',
    cabin_filter: 'Cabin filter',
    fuel_filter: 'Fuel filter',
    oil_filter: 'Oil filter',
    timing_belt: 'Timing belt',
    coolant: 'Coolant',
    chain: 'Chain',
    tyres: 'Tyres',
    engine_mount: 'Engine mount',
    coolant_temperature_sensor: 'Coolant temp. sensor',
    water_pump: 'Water pump',
    thermostat: 'Thermostat',
    tensioner_pulley: 'Tensioner pulley',
    ignition_coil: 'Ignition coil',
    injector: 'Injector',
    brakes: 'Brakes',
    gearbox_oil: 'Gearbox oil',
    clutch: 'Clutch',
    battery: 'Battery',
    distribution: 'Timing',
  },
};

/** Humanize a raw key: it is the operator's OWN wording echoed back, so it is language-neutral. */
function humanize(key: string): string {
  return key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

export function serviceLabel(type: string, locale: Locale): string {
  const t = canonicalService(type);

  return SERVICE_LABEL[locale][t] ?? humanize(t);
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

// Labels for known vehicle-fact keys. The `{type}_ref` family mirrors the part-derived facts the
// backend projects from logged maintenance items. Unmapped keys fall back to a humanized form (so a
// free-text key the user adds still reads, never a raw snake_case key on screen).
export const FACT_LABEL: Record<Locale, Record<string, string>> = {
  fr: {
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
    vin: 'Numéro VIN',
    first_registration_date: 'Première immatriculation',
    cnit: 'Code national (CNIT)',
    displacement: 'Cylindrée',
    power_kw: 'Puissance nette',
    energy: 'Énergie',
    fiscal_power: 'Puissance fiscale',
  },
  en: {
    oil_type: 'Oil type',
    oil_capacity: 'Oil capacity',
    coolant: 'Coolant',
    coolant_type: 'Coolant',
    brake_fluid: 'Brake fluid',
    spark_plug_ref: 'Spark plug ref.',
    oil_filter_ref: 'Oil filter ref.',
    air_filter_ref: 'Air filter ref.',
    fuel_filter_ref: 'Fuel filter ref.',
    cabin_filter_ref: 'Cabin filter ref.',
    timing_belt_ref: 'Timing belt ref.',
    battery_ref: 'Battery ref.',
    brakes_ref: 'Brakes ref.',
    tires_ref: 'Tyres ref.',
    gearbox_oil_ref: 'Gearbox oil ref.',
    vin: 'VIN',
    first_registration_date: 'First registration',
    cnit: 'National type code (CNIT)',
    displacement: 'Displacement',
    power_kw: 'Net power',
    energy: 'Energy',
    fiscal_power: 'Fiscal power',
  },
};

export function factLabel(key: string, locale: Locale): string {
  return FACT_LABEL[locale][key] ?? humanize(key);
}

export const HEALTH_LABEL: Record<Locale, Record<MaintenanceHealth['status'], string>> = {
  fr: {
    green: 'Sain',
    amber: 'Service à prévoir',
    red: 'En retard',
    unknown: 'Non suivi',
  },
  en: {
    green: 'Healthy',
    amber: 'Service due',
    red: 'Overdue',
    unknown: 'Untracked',
  },
};
