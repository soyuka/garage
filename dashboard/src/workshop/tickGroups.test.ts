import { describe, expect, it } from 'vitest';
import type { Tick } from './kmTrack';
import { groupTicks, nextInGroup } from './tickGroups';

const tick = (id: string, value: number, state: Tick['state'] = 'over'): Tick => ({ id, value, state, types: [id], label: null, date: null, estimated: false });

describe('groupTicks', () => {
  it('folds ticks at the same reading and state into one group, in order', () => {
    const groups = groupTicks([tick('a', 90000), tick('b', 90000), tick('c', 90000), tick('d', 95000)]);
    expect(groups.map((g) => g.ticks.map((t) => t.id))).toEqual([['a', 'b', 'c'], ['d']]);
    expect(groups[0].id).toBe('a');
  });

  it('keeps a done visit apart from an overdue job at the same reading', () => {
    const groups = groupTicks([tick('v', 90000, 'done'), tick('a', 90000), tick('b', 90000)]);
    expect(groups.map((g) => g.ticks.length)).toEqual([1, 2]);
  });
});

describe('nextInGroup', () => {
  const group = groupTicks([tick('a', 1), tick('b', 1), tick('c', 1)])[0];

  it('opens on the first tick, then walks the group and wraps', () => {
    expect(nextInGroup(group, null).id).toBe('a');
    expect(nextInGroup(group, 'a').id).toBe('b');
    expect(nextInGroup(group, 'c').id).toBe('a');
  });

  it('starts over when the selection is outside the group', () => {
    expect(nextInGroup(group, 'zz').id).toBe('a');
  });
});
