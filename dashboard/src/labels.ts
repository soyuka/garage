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
    organisation_number: 'Numéro d’organisation (OPR)',
    build_date: 'Date de fabrication',
    first_registration_date: 'Première immatriculation',
    cnit: 'Code national (CNIT)',
    displacement: 'Cylindrée',
    power_kw: 'Puissance nette',
    energy: 'Énergie',
    fiscal_power: 'Puissance fiscale',
    engine_power: 'Puissance',
    engine_displacement: 'Cylindrée',
    fuel_type: 'Carburant',
    distribution_type: 'Distribution',
    tires_front_dimensions: 'Pneus avant',
    tires_rear_dimensions: 'Pneus arrière',
    tire_pressure_normal: 'Pression des pneus',
    tire_pressure_loaded: 'Pression en charge',
    tire_pressure_spare: 'Pression roue de secours',
    tires_original_fitment: 'Pneus d’origine',
    body_type: 'Carrosserie',
    seats: 'Places assises',
    co2_emissions: 'Émissions de CO₂',
    emission_standard: 'Norme antipollution',
    noise_level: 'Niveau sonore',
    gross_vehicle_weight: 'PTAC',
    gross_combination_weight: 'PTRA',
    kerb_weight: 'Poids à vide',
    kerb_weight_national: 'Poids à vide (national)',
    front_axle_max_load: 'Charge max. essieu avant',
    rear_axle_max_load: 'Charge max. essieu arrière',
    paint_code: 'Code peinture',
    type_variant: 'Variante',
    type_approval: 'Réception CE',
    build_plant: 'Usine',
    build_dam: 'Numéro DAM',
    clutch_kit_ref: 'Référence kit d’embrayage',
    dual_mass_flywheel_ref: 'Référence volant bi-masse',
    differential_seal_ref: 'Référence joint de différentiel',
    crankshaft_rear_seal_ref: 'Référence joint de vilebrequin arrière',
    water_pump_ref: 'Référence pompe à eau',
    thermostat_ref: 'Référence thermostat',
    accessory_belt_ref: 'Référence courroie d’accessoires',
    engine_mount_ref: 'Référence support moteur',
    tensioner_pulley_ref: 'Référence galet tendeur',
    temperature_sensor_ref: 'Référence sonde de température',
    build_apv_tail: 'Numéro APV',
    build_organisation_number: 'Numéro d’organisation (OPR)',
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
    organisation_number: 'Organisation number (OPR)',
    build_date: 'Build date',
    first_registration_date: 'First registration',
    cnit: 'National type code (CNIT)',
    displacement: 'Displacement',
    power_kw: 'Net power',
    energy: 'Energy',
    fiscal_power: 'Fiscal power',
    engine_power: 'Power',
    engine_displacement: 'Displacement',
    fuel_type: 'Fuel',
    distribution_type: 'Timing drive',
    tires_front_dimensions: 'Front tyres',
    tires_rear_dimensions: 'Rear tyres',
    tire_pressure_normal: 'Tyre pressure',
    tire_pressure_loaded: 'Tyre pressure, loaded',
    tire_pressure_spare: 'Spare tyre pressure',
    tires_original_fitment: 'Original tyres',
    body_type: 'Body',
    seats: 'Seats',
    co2_emissions: 'CO₂ emissions',
    emission_standard: 'Emission standard',
    noise_level: 'Noise level',
    gross_vehicle_weight: 'Gross vehicle weight',
    gross_combination_weight: 'Gross combination weight',
    kerb_weight: 'Kerb weight',
    kerb_weight_national: 'Kerb weight (national)',
    front_axle_max_load: 'Front axle max. load',
    rear_axle_max_load: 'Rear axle max. load',
    paint_code: 'Paint code',
    type_variant: 'Type variant',
    type_approval: 'Type approval',
    build_plant: 'Build plant',
    build_dam: 'DAM number',
    clutch_kit_ref: 'Clutch kit ref.',
    dual_mass_flywheel_ref: 'Dual-mass flywheel ref.',
    differential_seal_ref: 'Differential seal ref.',
    crankshaft_rear_seal_ref: 'Rear crankshaft seal ref.',
    water_pump_ref: 'Water pump ref.',
    thermostat_ref: 'Thermostat ref.',
    accessory_belt_ref: 'Accessory belt ref.',
    engine_mount_ref: 'Engine mount ref.',
    tensioner_pulley_ref: 'Tensioner pulley ref.',
    temperature_sensor_ref: 'Temperature sensor ref.',
    build_apv_tail: 'APV number',
    build_organisation_number: 'Organisation number (OPR)',
  },
};

// Catalog fact keys come in families: `<noun>_capacity`, `<noun>_type`, `<noun>_code`. One noun table
// labels the whole family; French needs the noun's article ("d’huile", "de liquide", "du réservoir").
const FACT_NOUN: Record<Locale, Record<string, { of?: string; label: string }>> = {
  fr: {
    oil: { label: 'huile', of: 'd’huile' },
    gearbox_oil: { label: 'huile de boîte', of: 'd’huile de boîte' },
    auto_gearbox_oil: { label: 'huile de boîte automatique', of: 'd’huile de boîte automatique' },
    power_steering_oil: { label: 'huile de direction assistée', of: 'd’huile de direction assistée' },
    coolant: { label: 'liquide de refroidissement', of: 'de liquide de refroidissement' },
    brake_fluid: { label: 'liquide de frein', of: 'de liquide de frein' },
    ac_refrigerant: { label: 'réfrigérant de climatisation', of: 'de réfrigérant de climatisation' },
    ac_oil: { label: 'huile de climatisation', of: 'd’huile de climatisation' },
    fuel_tank: { label: 'réservoir', of: 'du réservoir' },
    engine: { label: 'moteur', of: 'moteur' },
    gearbox: { label: 'boîte de vitesses', of: 'boîte de vitesses' },
    transmission: { label: 'transmission', of: 'de transmission' },
  },
  en: {
    oil: { label: 'oil' },
    gearbox_oil: { label: 'gearbox oil' },
    auto_gearbox_oil: { label: 'auto gearbox oil' },
    power_steering_oil: { label: 'power steering oil' },
    coolant: { label: 'coolant' },
    brake_fluid: { label: 'brake fluid' },
    ac_refrigerant: { label: 'A/C refrigerant' },
    ac_oil: { label: 'A/C oil' },
    fuel_tank: { label: 'fuel tank' },
    engine: { label: 'engine' },
    gearbox: { label: 'gearbox' },
    transmission: { label: 'transmission' },
  },
};

const FACT_FAMILY: Record<Locale, Record<'capacity' | 'type' | 'code', (noun: { label: string; of?: string }) => string>> = {
  fr: {
    capacity: (n) => `Capacité ${n.of ?? `de ${n.label}`}`,
    type: (n) => `Type ${n.of ?? `de ${n.label}`}`,
    // A code names its object directly: "Code moteur", not "Code de moteur".
    code: (n) => `Code ${n.label}`,
  },
  en: {
    capacity: (n) => `${n.label} capacity`,
    type: (n) => `${n.label} type`,
    code: (n) => `${n.label} code`,
  },
};

function capitalise(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1);
}

/** A fluid named by itself ("Huile de boîte"), for a tag that pairs its type with its capacity. */
export function fluidLabel(noun: string, locale: Locale): string | null {
  const known = FACT_NOUN[locale][noun];
  return known ? capitalise(known.label) : null;
}

export function factLabel(key: string, locale: Locale): string {
  const known = FACT_LABEL[locale][key];
  if (known) return known;
  const m = /^(.+)_(capacity|type|code)$/.exec(key);
  const noun = m ? FACT_NOUN[locale][m[1]] : undefined;
  if (m && noun) return capitalise(FACT_FAMILY[locale][m[2] as 'capacity' | 'type' | 'code'](noun));
  return humanize(key);
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
