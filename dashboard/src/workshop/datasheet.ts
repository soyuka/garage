import type { VehicleFact } from '../types';

export type FactSystem = 'identity' | 'approval' | 'engine' | 'transmission' | 'fluids' | 'wheels' | 'brakes' | 'electrical' | 'other';
export type FactTier = 1 | 2 | 3;

export const SYSTEMS: readonly FactSystem[] = ['identity', 'engine', 'transmission', 'fluids', 'wheels', 'brakes', 'electrical', 'approval', 'other'];

export interface DatasheetEntry {
  key: string;
  tier: FactTier;
  facts: VehicleFact[];
}

export interface DatasheetGroup {
  system: FactSystem;
  entries: DatasheetEntry[];
}

// First match wins: fluids come before the organ they fill, so `gearbox_oil_type` is a fluid and `gearbox_code` is not.
const SYSTEM_RULES: Array<[FactSystem, RegExp]> = [
  ['fluids', /^(oil_(capacity|type|viscosity)|(gearbox|auto_gearbox|power_steering|ac)_oil_|coolant|brake_fluid|ac_refrigerant|fuel_tank)/],
  ['identity', /^(vin|cnit|plate|make|model|year|usage|body_type|first_registration|paint_code|energy|fiscal_power)/],
  ['approval', /^(type_variant|type_approval|seats|build_|organisation_number|co2_|emission|noise_|gross_|kerb_weight|front_axle_|rear_axle_)/],
  ['transmission', /^(gearbox|auto_gearbox|clutch|dual_mass_flywheel|differential|transmission|driveshaft)/],
  ['wheels', /^(tires?_|tyres?_|tyre_size|tube_|wheel|rim)/],
  ['brakes', /^(brakes?_|abs|caliper)/],
  ['electrical', /^(battery|alternator|starter|airbag|bulb|fuse|glow_plug)/],
  ['engine', /^(engine|displacement|power_kw|fuel_|distribution|timing_|spark_plug|oil_filter|air_filter|oil_change|accessory_belt|water_pump|thermostat|temperature_sensor|tensioner|crankshaft|camshaft|injector|turbo)/],
];

const TIER_1 = new Set(['engine_code', 'vin', 'plate', 'tires_front_dimensions', 'tyre_size', 'tire_size', 'battery_capacity', 'engine_power', 'power_kw']);
const TIER_3 = /(_alt|_caveat|_note|_mismatch|_position|_drives|_fixing|_check|_oe_ref|_oe_alt|_compatible_refs)$/;
const TIER_2 = /(_capacity|_type|_viscosity|_code|_ref|_dimensions|_pressure_normal)$|^(fuel_type|energy|fiscal_power|first_registration_date|cnit|make|model|year|usage|displacement|engine_displacement|distribution_type|engine_variant)$/;
/** A tier-1 tile carries a figure, not a sentence; a tier-2 tag a reference, not a paragraph. */
const FIGURE_MAX = 28;
const REFERENCE_MAX = 48;

export function factSystem(key: string): FactSystem {
  return SYSTEM_RULES.find(([, rule]) => rule.test(key))?.[0] ?? 'other';
}

export function factTier(key: string, value: string): FactTier {
  if (TIER_3.test(key)) return 3;
  if (TIER_1.has(key) && value.length <= FIGURE_MAX) return 1;
  return (TIER_1.has(key) || TIER_2.test(key)) && value.length <= REFERENCE_MAX ? 2 : 3;
}

/** Facts that state the same thing under two keys: the first one present is kept. */
const DUPLICATES: readonly string[][] = [
  ['engine_power', 'power_kw'],
  ['displacement', 'engine_displacement'],
  ['fuel_type', 'energy'],
  ['organisation_number', 'build_organisation_number'],
];

const IDENTIFIER = /^(vin|cnit|plate|paint_code|type_variant|type_approval|build_dam|build_apv_tail|organisation_number|build_organisation_number)$|(_code|_ref|_refs|_oe_alt|_alt)$/;

/** A number that names (VIN, DAM, OPR, a reference) rather than counts: shown as written, never grouped. */
export function isIdentifier(key: string): boolean {
  return IDENTIFIER.test(key);
}

/** A fact's alternatives (pipe-joined), each once. More than one means it varies with the variant. */
export function distinctValues(value: string): string[] {
  return [...new Set(value.split('|').map((v) => v.trim()).filter(Boolean))];
}

/** The datasheet: facts boxed by system, in a fixed system order, each box led by its tier-1 figures. */
export function factGroups(all: VehicleFact[]): DatasheetGroup[] {
  const present = new Set(all.map((f) => f.key));
  const dropped = new Set(DUPLICATES.flatMap((group) => group.filter((_, i) => i > 0 && group.slice(0, i).some((p) => present.has(p)))));
  const facts = all.filter((f) => !dropped.has(f.key));
  const byKey = new Map(facts.map((f) => [f.key, f]));
  const entries: Array<DatasheetEntry & { system: FactSystem; order: number }> = [];
  const used = new Set<string>();
  const capacity = byKey.get('oil_capacity');
  const spec = byKey.get('oil_viscosity') ?? byKey.get('oil_type');
  if (spec && capacity) {
    used.add(spec.key).add(capacity.key);
    entries.push({ key: 'engine_oil', tier: 1, facts: [spec, capacity], system: 'fluids', order: facts.indexOf(spec) });
  }
  facts.forEach((f, order) => {
    const noun = /^(.+)_type$/.exec(f.key)?.[1];
    const volume = noun ? byKey.get(`${noun}_capacity`) : undefined;
    if (noun && volume && !used.has(f.key) && !used.has(volume.key) && factSystem(f.key) === 'fluids') {
      used.add(f.key).add(volume.key);
      entries.push({ key: `${noun}_fluid`, tier: 2, facts: [f, volume], system: 'fluids', order: Math.min(order, facts.indexOf(volume)) });
    }
  });
  facts.forEach((f, order) => {
    if (used.has(f.key)) return;
    entries.push({ key: f.key, tier: factTier(f.key, f.value), facts: [f], system: factSystem(f.key), order });
  });
  return SYSTEMS.map((system) => ({
    system,
    entries: entries
      .filter((e) => e.system === system)
      .sort((a, b) => a.tier - b.tier || a.order - b.order)
      .map(({ key, tier, facts: fs }) => ({ key, tier, facts: fs })),
  })).filter((g) => g.entries.length > 0);
}

/**
 * Masonry: the tallest boxes first, each into the currently shortest column (leftmost on a tie), then each
 * column back in reading order. Returns box indexes per column.
 */
export function packColumns(heights: number[], columns: number): number[][] {
  const cols: number[][] = Array.from({ length: Math.max(1, columns) }, () => []);
  const sums = cols.map(() => 0);
  const order = heights.map((h, i) => ({ h, i })).sort((a, b) => b.h - a.h || a.i - b.i);
  for (const { h, i } of order) {
    const at = sums.indexOf(Math.min(...sums));
    cols[at].push(i);
    sums[at] += h;
  }
  return cols.map((c) => c.sort((a, b) => a - b));
}

/** A tag's facts split into the ones shown as values and the ones that vary by variant (folded behind a toggle). */
export function splitVarying(facts: VehicleFact[]): { fixed: VehicleFact[]; varying: VehicleFact[] } {
  const varies = (f: VehicleFact) => distinctValues(f.value).length > 1;
  return { fixed: facts.filter((f) => !varies(f)), varying: facts.filter(varies) };
}
