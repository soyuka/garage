import { describe, it, expect } from 'vitest';
import { buildRail } from './rail';
import type { ForecastDue, PlannedTask } from '../types';

// The rail model: buildEcheances' merge/sort (reused verbatim for ordering) stamped with the shared
// pin/row index. A pin on the ring and its rail row carry the SAME index; a gap has no pin (index null).

function due(type: string, over: Partial<ForecastDue> = {}): ForecastDue {
  return {
    type, action: null, component: null, rule: 'schedule', lastPerformedAt: '2024-11-15',
    dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: 365, usage: null, ...over,
  };
}
const task = (type: string, status: string, id = `/t/${type}`): PlannedTask => ({ id, type, parts: [], status });

describe('buildRail — indexed rail model (pins ↔ rows share the index)', () => {
  it('stamps 1-based indices on non-gap items in severity order', () => {
    const items = buildRail([
      due('coolant'), // green
      due('brake_fluid', { daysUntilDue: 12 }), // amber
      due('oil_change', { overdue: true }), // red
    ]);
    expect(items.map((i) => [i.type, i.index])).toEqual([
      ['oil_change', 1],
      ['brake_fluid', 2],
      ['coolant', 3],
    ]);
  });

  it('gives a gap no pin (index null) and sorts it after the pinned items', () => {
    const items = buildRail([due('oil_change', { overdue: true })], ['air_filter']);
    const gap = items.find((i) => i.kind === 'gap')!;
    expect(gap.index).toBeNull();
    expect(items[0].index).toBe(1);
    // pinned indices stay contiguous 1..k regardless of the trailing gap
    expect(items.filter((i) => i.index != null).map((i) => i.index)).toEqual([1]);
  });

  it('attaches the full task to a task-kind item (drives the editable row)', () => {
    const t = task('oil_change', 'open');
    const [i] = buildRail([due('oil_change', { overdue: true })], [], [t]);
    expect(i.kind).toBe('task');
    expect(i.task).toBe(t);
  });

  it('leaves task undefined on a due-kind item', () => {
    const [i] = buildRail([due('oil_change', { overdue: true })]);
    expect(i.kind).toBe('due');
    expect(i.task).toBeUndefined();
  });

  it('empty forecast → empty rail', () => {
    expect(buildRail([])).toEqual([]);
  });
});
