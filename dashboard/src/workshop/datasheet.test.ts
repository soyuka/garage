import { describe, expect, it } from 'vitest';
import type { VehicleFact } from '../types';
import { distinctValues, factGroups, factSystem, factTier, isIdentifier, packColumns, splitVarying } from './datasheet';

const fact = (key: string, value: string, unit: string | null = null): VehicleFact => ({ key, value, unit, source: 'user', verified: false });

describe('factSystem', () => {
  it.each([
    ['vin', 'identity'],
    ['cnit', 'identity'],
    ['first_registration_date', 'identity'],
    ['kerb_weight', 'approval'],
    ['build_dam', 'approval'],
    ['type_approval', 'approval'],
    ['organisation_number', 'approval'],
    ['engine_code', 'engine'],
    ['engine_mount_rear_ref', 'engine'],
    ['power_kw', 'engine'],
    ['oil_filter_ref', 'engine'],
    ['water_pump_pulley_torque', 'engine'],
    ['gearbox_code', 'transmission'],
    ['clutch_kit_ref', 'transmission'],
    ['dual_mass_flywheel_ref', 'transmission'],
    ['oil_capacity', 'fluids'],
    ['oil_viscosity', 'fluids'],
    ['gearbox_oil_type', 'fluids'],
    ['power_steering_oil_capacity', 'fluids'],
    ['brake_fluid_type', 'fluids'],
    ['ac_refrigerant_capacity', 'fluids'],
    ['fuel_tank_capacity', 'fluids'],
    ['tires_front_dimensions', 'wheels'],
    ['tire_pressure_normal', 'wheels'],
    ['tyre_size', 'wheels'],
    ['brakes_ref', 'brakes'],
    ['brake_disc_front_ref', 'brakes'],
    ['battery_ref', 'electrical'],
    ['airbag_connector_cleaning_ref', 'electrical'],
    ['cabin_filter_note', 'other'],
    ['something_new', 'other'],
  ])('%s → %s', (key, system) => {
    expect(factSystem(key)).toBe(system);
  });
});

describe('factTier', () => {
  it('raises the few figures a mechanic reads first to tier 1', () => {
    expect(factTier('engine_code', 'F4R')).toBe(1);
    expect(factTier('tires_front_dimensions', '195/65 R15 91T')).toBe(1);
    expect(factTier('battery_capacity', '70')).toBe(1);
    expect(factTier('vin', 'VF33CKFWB83254630')).toBe(1);
    expect(factTier('engine_power', '55 kW / 75 ch')).toBe(1);
  });

  it('keeps capacities, fluid specs and the main part references at tier 2', () => {
    expect(factTier('coolant_capacity', '9.3')).toBe(2);
    expect(factTier('brake_fluid_type', 'DOT 4')).toBe(2);
    expect(factTier('oil_filter_ref', 'MANN W 75/3')).toBe(2);
    expect(factTier('gearbox_code', 'PK6-013')).toBe(2);
  });

  it('puts notes, alternatives and unknown keys at tier 3', () => {
    expect(factTier('cabin_filter_ref_alt', 'J1343010')).toBe(3);
    expect(factTier('engine_mount_left_ref_caveat', 'à vérifier')).toBe(3);
    expect(factTier('something_new', 'x')).toBe(3);
  });

  it('demotes a tier-2 key whose value is a sentence to tier 3', () => {
    expect(factTier('clutch_kit_ref', 'LuK 623 3150 33 (RepSet Pro, 230 mm, avec butée hydraulique) — monté par Renault')).toBe(3);
    expect(factTier('clutch_kit_ref', 'LuK 623 3150 33')).toBe(2);
  });

  it('demotes a tier-1 key whose value is prose rather than a figure', () => {
    expect(factTier('engine_code', 'F4R turbo (F4R 896/897), échange standard')).toBe(2);
    expect(factTier('engine_code', 'F4R turbo (F4R 896/897) monté en remplacement du moteur d’origine')).toBe(3);
  });
});

describe('factGroups', () => {
  it('orders systems, drops empty ones and sorts each box by tier, keeping input order inside a tier', () => {
    const groups = factGroups([
      fact('cabin_filter_note', 'BF monté'),
      fact('coolant_capacity', '9.3', 'liter'),
      fact('engine_code', 'F4R'),
      fact('oil_type', 'ACEA A3/B3'),
      fact('brake_fluid_type', 'DOT 4'),
      fact('vin', 'VF1'),
    ]);
    expect(groups.map((g) => g.system)).toEqual(['identity', 'engine', 'fluids', 'other']);
    expect(groups.find((g) => g.system === 'fluids')?.entries.map((e) => [e.key, e.tier])).toEqual([
      ['coolant_capacity', 2],
      ['oil_type', 2],
      ['brake_fluid_type', 2],
    ]);
  });

  it('folds oil viscosity and capacity into one engine-oil tile', () => {
    const viscosity = fact('oil_viscosity', '5W-30');
    const capacity = fact('oil_capacity', '5.9', 'L');
    const fluids = factGroups([capacity, fact('oil_type', 'ACEA A3/B3'), viscosity]).find((g) => g.system === 'fluids');
    expect(fluids?.entries[0]).toEqual({ key: 'engine_oil', tier: 1, facts: [viscosity, capacity] });
    expect(fluids?.entries.map((e) => e.key)).toEqual(['engine_oil', 'oil_type']);
  });

  it('keeps a lone oil capacity as its own tier-2 fact', () => {
    const fluids = factGroups([fact('oil_capacity', '4.3', 'L')]).find((g) => g.system === 'fluids');
    expect(fluids?.entries).toEqual([{ key: 'oil_capacity', tier: 2, facts: [fact('oil_capacity', '4.3', 'L')] }]);
  });

  it('keeps one of each duplicated fact: the stated power, the carte-grise displacement, the readable fuel, one OPR', () => {
    const groups = factGroups([
      fact('power_kw', '55', 'kW'),
      fact('engine_power', '55 kW / 75 ch'),
      fact('engine_displacement', '1360 cm3'),
      fact('displacement', '1360', 'cm³'),
      fact('energy', 'ES'),
      fact('fuel_type', 'essence'),
      fact('build_organisation_number', '09824'),
      fact('organisation_number', '09824'),
    ]);
    const keys = groups.flatMap((g) => g.entries.map((e) => e.key));
    expect(keys.sort()).toEqual(['displacement', 'engine_power', 'fuel_type', 'organisation_number']);
  });

  it('keeps a lone duplicate when its preferred twin is absent', () => {
    expect(factGroups([fact('power_kw', '55', 'kW')]).flatMap((g) => g.entries.map((e) => [e.key, e.tier]))).toEqual([['power_kw', 1]]);
  });

  it("pairs a fluid's type and capacity into one tag, type first", () => {
    const type = fact('gearbox_oil_type', 'SAE 75W-80');
    const capacity = fact('gearbox_oil_capacity', '2.4', 'L');
    const fluids = factGroups([capacity, fact('coolant_capacity', '9.3', 'liter'), type]).find((g) => g.system === 'fluids');
    expect(fluids?.entries).toEqual([
      { key: 'gearbox_oil_fluid', tier: 2, facts: [type, capacity] },
      { key: 'coolant_capacity', tier: 2, facts: [fact('coolant_capacity', '9.3', 'liter')] },
    ]);
  });

  it('lifts the engine oil spec and capacity to tier 1 when no viscosity is known', () => {
    const type = fact('oil_type', 'ACEA A3/B3');
    const capacity = fact('oil_capacity', '5.4', 'liter');
    expect(factGroups([capacity, type]).find((g) => g.system === 'fluids')?.entries).toEqual([{ key: 'engine_oil', tier: 1, facts: [type, capacity] }]);
  });
});

describe('distinctValues', () => {
  it('lists the differing alternatives of a fact, once each', () => {
    expect(distinctValues('3 | 39 | 3.25')).toEqual(['3', '39', '3.25']);
    expect(distinctValues('DOT 4|DOT 4')).toEqual(['DOT 4']);
    expect(distinctValues('5.4')).toEqual(['5.4']);
  });
});

describe('isIdentifier', () => {
  it('marks numbers that name rather than count, so they are never thousand-grouped', () => {
    for (const key of ['vin', 'cnit', 'build_dam', 'organisation_number', 'build_organisation_number', 'build_apv_tail', 'engine_code', 'oil_filter_ref', 'paint_code', 'type_variant']) {
      expect(isIdentifier(key)).toBe(true);
    }
    for (const key of ['oil_capacity', 'kerb_weight', 'seats', 'noise_level']) {
      expect(isIdentifier(key)).toBe(false);
    }
  });
});

describe('packColumns', () => {
  it('places the tallest boxes first, each into the shortest column, then keeps reading order inside a column', () => {
    expect(packColumns([486, 277, 441, 295, 352], 3)).toEqual([[0], [1, 2], [3, 4]]);
  });

  it('breaks ties toward the leftmost column and keeps empty columns when boxes run out', () => {
    expect(packColumns([10, 10], 3)).toEqual([[0], [1], []]);
    expect(packColumns([10, 10, 10], 1)).toEqual([[0, 1, 2]]);
  });
});

describe('splitVarying', () => {
  it("keeps a tile's single values in place and folds every varying one behind the toggle, in every fluid tile alike", () => {
    const spec = fact('oil_type', 'ACEA A3/B3');
    const volume = fact('oil_capacity', '3 | 39 | 3.25', 'liter');
    expect(splitVarying([spec, volume])).toEqual({ fixed: [spec], varying: [volume] });
    const type = fact('gearbox_oil_type', 'ESSO 75W80 | MB 235.11');
    const cap = fact('gearbox_oil_capacity', '1.9 | 10.5', 'liter');
    expect(splitVarying([type, cap])).toEqual({ fixed: [], varying: [type, cap] });
    expect(splitVarying([fact('brake_fluid_type', 'DOT 4')])).toEqual({ fixed: [fact('brake_fluid_type', 'DOT 4')], varying: [] });
  });
});
