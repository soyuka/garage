import { describe, it, expect } from 'vitest';
import { groupFactsByRegion, REGION_ORDER } from './factGroups';
import type { VehicleFact } from '../types';

const fact = (key: string, value = '1'): VehicleFact => ({ key, value, source: 'test', verified: false });

describe('groupFactsByRegion — the legend grouped like the body', () => {
  it('groups in REGION_ORDER regardless of input order', () => {
    const facts = [
      fact('rear_brake_pads'), // rearAxle
      fact('brake_pads'), // frontAxle
      fact('unknown_thing'), // unplaced
      fact('engine_mount'), // bay
      fact('wiper_blade'), // windshield
      fact('oil_change_ref'), // hood
    ];
    const groups = groupFactsByRegion(facts);
    expect(groups.map((g) => g.region)).toEqual(['hood', 'windshield', 'bay', 'frontAxle', 'rearAxle', 'unplaced']);
  });

  it('omits empty regions entirely', () => {
    const groups = groupFactsByRegion([fact('brake_pads'), fact('rear_brake_pads')]);
    expect(groups.map((g) => g.region)).toEqual(['frontAxle', 'rearAxle']);
  });

  it('keeps arrival order inside a group (no reordering)', () => {
    const facts = [fact('engine_mount'), fact('oil_change_ref'), fact('coolant_level')];
    const groups = groupFactsByRegion(facts);
    const bay = groups.find((g) => g.region === 'bay')!;
    expect(bay.facts.map((f) => f.key)).toEqual(['engine_mount', 'coolant_level']);
    const hood = groups.find((g) => g.region === 'hood')!;
    expect(hood.facts.map((f) => f.key)).toEqual(['oil_change_ref']);
  });

  it('returns an empty array for no facts', () => {
    expect(groupFactsByRegion([])).toEqual([]);
  });

  it('REGION_ORDER matches the spec: hood, windshield, bay, frontAxle, rearAxle, unplaced', () => {
    expect(REGION_ORDER).toEqual(['hood', 'windshield', 'bay', 'frontAxle', 'rearAxle', 'unplaced']);
  });
});
