import { describe, it, expect } from 'vitest';
import { buildRail } from './rail';
import type { ForecastDue, PlannedTask } from '../types';

// The rail model: buildEcheances' merge (reused verbatim) split into the operator's TWO workload buckets —
// "À faire" (anything actually late, task or not) and "Planifié" (everything coming or already handled).
// Gaps ("non suivi") sit apart. No indices: the rail rows ARE the interface (pins were removed).

function due(type: string, over: Partial<ForecastDue> = {}): ForecastDue {
  return {
    type, action: null, component: null, rule: 'schedule', lastPerformedAt: '2024-11-15',
    dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: 365, usage: null, ...over,
  };
}
const task = (type: string, status: string, id = `/t/${type}`): PlannedTask => ({ id, type, parts: [], status });

describe('buildRail — todo/planned/gaps buckets', () => {
  it('splits overdue into todo, the rest into planned', () => {
    const model = buildRail([
      due('coolant'), // green → planned
      due('brake_fluid', { daysUntilDue: 12 }), // amber → planned
      due('oil_change', { overdue: true }), // red → todo
    ]);
    expect(model.todo.map((i) => i.type)).toEqual(['oil_change']);
    expect(model.planned.map((i) => i.type)).toEqual(['brake_fluid', 'coolant']);
    expect(model.gaps).toEqual([]);
  });

  it('an overdue service WITH an open task is still todo (late is late)', () => {
    const t = task('oil_change', 'open');
    const model = buildRail([due('oil_change', { overdue: true })], [], [t]);
    expect(model.todo).toHaveLength(1);
    expect(model.todo[0].kind).toBe('task');
    expect(model.todo[0].task).toBe(t);
  });

  it('a DONE task bound to an overdue due is handled → planned, not todo', () => {
    const t = task('oil_change', 'done');
    const model = buildRail([due('oil_change', { overdue: true })], [], [t]);
    expect(model.todo).toEqual([]);
    expect(model.planned.map((i) => [i.kind, i.done])).toEqual([['task', true]]);
  });

  it('a manually-planned task with no forecast lands in planned', () => {
    const model = buildRail([], [], [task('brake_pads', 'open')]);
    expect(model.planned.map((i) => i.kind)).toEqual(['task']);
    expect(model.todo).toEqual([]);
  });

  it('keeps severity order within each bucket (worst first)', () => {
    const model = buildRail([
      due('coolant'), // green
      due('air_filter', { overdue: true }), // red
      due('brake_fluid', { daysUntilDue: 12 }), // amber
      due('oil_change', { overdue: true }), // red
    ]);
    expect(model.todo.map((i) => i.type)).toEqual(['air_filter', 'oil_change']);
    expect(model.planned.map((i) => i.type)).toEqual(['brake_fluid', 'coolant']);
  });

  it('gaps go to the gaps bucket', () => {
    const model = buildRail([due('oil_change', { overdue: true })], ['air_filter']);
    expect(model.gaps.map((i) => i.type)).toEqual(['air_filter']);
    expect(model.todo).toHaveLength(1);
  });

  it('empty forecast → empty model', () => {
    expect(buildRail([])).toEqual({ todo: [], planned: [], gaps: [] });
  });
});
