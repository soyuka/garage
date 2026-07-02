import { describe, it, expect } from 'vitest';
import { layoutSlots } from './ringEcheances';
import type { Echeance } from './echeances';

function ech(over: Partial<Echeance> = {}): Echeance {
  return { id: 'oil_change', kind: 'due', type: 'oil_change', label: 'Vidange', horizon: null, signal: 'green', region: 'hood', ...over };
}

describe('layoutSlots — spread around both flanks, skipping top/bottom dead zones', () => {
  const inFlank = (a: number) => (a >= 212 && a <= 328) || (a >= 32 && a <= 148);

  it('empty input → empty output', () => {
    expect(layoutSlots([])).toEqual([]);
  });

  it('preserves item count and order', () => {
    const items = [ech({ id: 'a' }), ech({ id: 'b' }), ech({ id: 'c' })];
    const slots = layoutSlots(items);
    expect(slots).toHaveLength(3);
    expect(slots.map((s) => s.echeance.id)).toEqual(['a', 'b', 'c']);
  });

  it('every angle stays on a flank (never the top-centre or bottom-centre dead zones)', () => {
    const items = Array.from({ length: 8 }, (_, i) => ech({ id: `e${i}` }));
    for (const s of layoutSlots(items)) expect(inFlank(s.angleDeg)).toBe(true);
  });

  it('a single item lands on the left flank midpoint', () => {
    const [slot] = layoutSlots([ech()]);
    expect(slot.angleDeg).toBe(270);
  });

  it('worst-first items fill the left flank, the rest the right', () => {
    const slots = layoutSlots([ech({ id: 'a' }), ech({ id: 'b' })]);
    expect(slots[0].angleDeg).toBeGreaterThanOrEqual(212);
    expect(slots[0].angleDeg).toBeLessThanOrEqual(328);
    expect(slots[1].angleDeg).toBeGreaterThanOrEqual(32);
    expect(slots[1].angleDeg).toBeLessThanOrEqual(148);
  });
});
