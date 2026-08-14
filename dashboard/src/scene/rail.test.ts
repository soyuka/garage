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

  // Work the operator DECIDED to do is not a prediction: it gets its own bucket, above the forecast,
  // so a dateless task can no longer sink under items the schedule merely guesses at.
  it('an open task with no forecast is ongoing work, not a prediction', () => {
    const model = buildRail([], [], [task('brake_pads', 'open')]);
    expect(model.ongoing.map((i) => i.kind)).toEqual(['task']);
    expect(model.planned).toEqual([]);
    expect(model.todo).toEqual([]);
  });

  it('an open task bound to a not-yet-due forecast is ongoing too', () => {
    const model = buildRail([due('fuel_filter')], [], [task('fuel_filter', 'open')]);
    expect(model.ongoing.map((i) => i.type)).toEqual(['fuel_filter']);
    expect(model.planned).toEqual([]);
  });

  it('a late task stays in todo — late outranks ongoing', () => {
    const model = buildRail([due('oil_change', { overdue: true })], [], [task('oil_change', 'open')]);
    expect(model.todo.map((i) => i.type)).toEqual(['oil_change']);
    expect(model.ongoing).toEqual([]);
  });

  it('a done task is handled, never ongoing', () => {
    const model = buildRail([due('oil_change')], [], [task('oil_change', 'done')]);
    expect(model.ongoing).toEqual([]);
    expect(model.planned.map((i) => i.done)).toEqual([true]);
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
    expect(buildRail([])).toEqual({ todo: [], ongoing: [], planned: [], gaps: [] });
  });
});
