import { describe, it, expect } from 'vitest';
import {
  factRegion,
  factConstellationLayout,
  unplacedAnchor,
  FIRST_OFFSET,
  STEP,
  COLUMN_MAX,
  COLUMN_GAP,
} from './factConstellation';
import { regionAnchors } from './anchors';
import { DIMS } from './CarProxy';
import type { VehicleFact } from '../types';

function fact(key: string, overrides: Partial<VehicleFact> = {}): VehicleFact {
  return { key, value: '1', source: 'user', verified: false, ...overrides };
}

describe('factRegion', () => {
  it('routes a `_ref` key through the service-type map', () => {
    // TYPE_REGION maps oil_filter -> hood, brake_pads -> frontAxle (anchors.ts).
    expect(factRegion('oil_filter_ref')).toBe('hood');
    expect(factRegion('brake_pads_ref')).toBe('frontAxle');
  });

  it('routes the curated prefixes', () => {
    expect(factRegion('oil_capacity')).toBe('bay');
    expect(factRegion('coolant_capacity')).toBe('bay');
    expect(factRegion('engine_mount_torque')).toBe('bay');
    expect(factRegion('battery_type')).toBe('bay');
    expect(factRegion('belt_interval')).toBe('bay');
    expect(factRegion('spark_plug_gap')).toBe('bay');
    expect(factRegion('brake_disc_diameter')).toBe('frontAxle');
    expect(factRegion('tyre_size')).toBe('frontAxle');
    expect(factRegion('wheel_bolt_torque')).toBe('frontAxle');
    expect(factRegion('wiper_blade_size')).toBe('windshield');
    expect(factRegion('windshield_washer_capacity')).toBe('windshield');
  });

  it('routes the widened bay prefixes (engine-bay items previously unplaced)', () => {
    expect(factRegion('gearbox_oil_capacity')).toBe('bay');
    expect(factRegion('gearbox_oil_type')).toBe('bay');
    expect(factRegion('transmission_fluid_type')).toBe('bay');
    expect(factRegion('power_steering_oil_type')).toBe('bay');
    expect(factRegion('ac_refrigerant_type')).toBe('bay');
    expect(factRegion('ac_refrigerant_capacity')).toBe('bay');
    expect(factRegion('fuel_filter_ref')).toBe('bay');
    expect(factRegion('alternator_belt_tension')).toBe('bay');
    expect(factRegion('starter_torque')).toBe('bay');
  });

  it('a rear_ variant overrides the front-axle prefixes and lands on rearAxle', () => {
    expect(factRegion('rear_brake_pads')).toBe('rearAxle');
    expect(factRegion('rear_tyre_pressure')).toBe('rearAxle');
  });

  it('an unrecognised key is unplaced — the station never guesses', () => {
    expect(factRegion('vin')).toBe('unplaced');
    expect(factRegion('registration_number')).toBe('unplaced');
  });
});

describe('unplacedAnchor', () => {
  it('stands above the roof centre, derived from DIMS.bodyH + DIMS.cabinH', () => {
    const d = DIMS.hatchback;
    expect(unplacedAnchor('hatchback')).toEqual([0, d.bodyH + d.cabinH + 0.5, 0]);
  });

  it('has a defined anchor for moto (no DIMS entry) via its own highest region anchor', () => {
    const [x, y, z] = unplacedAnchor('moto');
    expect(x).toBe(0);
    expect(z).toBe(0);
    expect(y).toBeCloseTo(regionAnchors('moto').windshield[1] + 0.5);
  });
});

describe('factConstellationLayout', () => {
  it('preserves input order and stacks facts sharing a region in a column', () => {
    const facts = [fact('oil_capacity'), fact('coolant_capacity'), fact('oil_filter_ref')];
    const nodes = factConstellationLayout(facts, 'berline');
    expect(nodes.map((n) => n.key)).toEqual(['oil_capacity', 'coolant_capacity', 'oil_filter_ref']);
    // oil_capacity + coolant_capacity -> bay; oil_filter_ref -> hood (TYPE_REGION).
    const bay = nodes.filter((n) => n.region === 'bay');
    expect(bay).toHaveLength(2);
    const anchor = regionAnchors('berline').bay;
    expect(bay[0].position).toEqual([anchor[0], anchor[1] + FIRST_OFFSET, anchor[2]]);
    expect(bay[1].position).toEqual([anchor[0], anchor[1] + FIRST_OFFSET + STEP, anchor[2]]);
    expect(bay[0].first).toBe(true);
    expect(bay[1].first).toBe(false);
  });

  it('carries verified through untouched', () => {
    const nodes = factConstellationLayout([fact('oil_capacity', { verified: true })], 'berline');
    expect(nodes[0].verified).toBe(true);
  });

  it('places an unplaced fact on the sixth anchor, above the roof', () => {
    const [node] = factConstellationLayout([fact('vin')], 'berline');
    expect(node.region).toBe('unplaced');
    expect(node.anchor).toEqual(unplacedAnchor('berline'));
  });

  it('a region at exactly COLUMN_MAX stays a single column, no lateral offset', () => {
    expect(COLUMN_MAX).toBe(8);
    const facts = Array.from({ length: 8 }, (_, i) => fact(`oil_capacity_${i}`));
    const nodes = factConstellationLayout(facts, 'berline');
    const anchor = regionAnchors('berline').bay;
    for (const node of nodes) {
      expect(node.position[2]).toBe(anchor[2]);
      expect(node.foot).toEqual([anchor[0], anchor[1], anchor[2]]);
    }
    expect(nodes[0].first).toBe(true);
    expect(nodes.filter((n) => n.first)).toHaveLength(1);
    // last node of the single column: row 7, no lateral offset.
    expect(nodes[7].position).toEqual([anchor[0], anchor[1] + FIRST_OFFSET + 7 * STEP, anchor[2]]);
  });

  it('a region past COLUMN_MAX spills into a second column, centred on the anchor', () => {
    const facts = Array.from({ length: 9 }, (_, i) => fact(`oil_capacity_${i}`));
    const nodes = factConstellationLayout(facts, 'berline');
    const anchor = regionAnchors('berline').bay;

    const col0 = nodes.slice(0, 8);
    const col9 = nodes[8];
    for (const node of col0) expect(node.position[2]).toBeCloseTo(anchor[2] - COLUMN_GAP / 2);
    expect(col9.position[2]).toBeCloseTo(anchor[2] + COLUMN_GAP / 2);

    const firsts = nodes.filter((n) => n.first);
    expect(firsts).toHaveLength(2);
    expect(firsts[0].foot).toEqual([anchor[0], anchor[1], anchor[2] - COLUMN_GAP / 2]);
    expect(firsts[1].foot).toEqual([anchor[0], anchor[1], anchor[2] + COLUMN_GAP / 2]);
    expect(firsts[0].foot).not.toEqual(firsts[1].foot);

    // ninth fact starts row 0 of the second column, same height as the first column's first node.
    expect(col9.position[1]).toBe(anchor[1] + FIRST_OFFSET);
  });
});
