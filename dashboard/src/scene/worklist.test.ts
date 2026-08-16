import { describe, it, expect } from 'vitest';
import { buildRail } from './rail';
import { buildWorklist } from './worklist';
import type { ForecastDue, PlannedTask } from '../types';

function due(type: string, over: Partial<ForecastDue> = {}): ForecastDue {
  return {
    type, action: null, component: null, rule: 'schedule', lastPerformedAt: '2024-11-15',
    dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: 365, usage: null, ...over,
  };
}
const task = (type: string, status: string, id = `/t/${type}`, label?: string): PlannedTask =>
  ({ id, type, parts: [], status, ...(label ? { label } : {}) });

describe('buildWorklist — the spotlighted answer to « qu\'est-ce que j\'ai à faire ? »', () => {
  it('with no keys, projects the ACTIONABLE workload only — late + decided, never the forecast', () => {
    const model = buildRail(
      [due('oil_change', { overdue: true }), due('coolant')], 'fr',
      ['inspection'],
      [task('fuel_filter', 'open')]);
    const list = buildWorklist(model, []);
    expect(list.items.map((i) => i.type)).toEqual(['oil_change', 'fuel_filter']);
    expect(list.missing).toEqual([]);
  });

  it('with keys, projects those services wherever they sit — including a merely-upcoming one', () => {
    const model = buildRail([due('oil_change', { overdue: true }), due('coolant')], 'fr', [], []);
    const list = buildWorklist(model, ['coolant']);
    expect(list.items.map((i) => i.type)).toEqual(['coolant']);
  });

  it('keeps SIBLING tasks of one type apart — two engine mounts are two jobs', () => {
    const model = buildRail([], 'fr', [], [
      task('engine_mount', 'open', '/t/rear', 'support moteur arrière'),
      task('engine_mount', 'open', '/t/left', 'support moteur gauche'),
    ]);
    const list = buildWorklist(model, ['engine_mount']);
    expect(list.items.map((i) => i.label)).toEqual(['support moteur arrière', 'support moteur gauche']);
  });

  it('reports a spotlighted key the vehicle has no échéance for, rather than showing an empty panel', () => {
    const model = buildRail([due('oil_change')], 'fr', [], []);
    const list = buildWorklist(model, ['timing_belt', 'oil_change']);
    expect(list.items.map((i) => i.type)).toEqual(['oil_change']);
    expect(list.missing).toEqual(['timing_belt']);
  });

  it('surfaces an untracked service as a gap instead of dropping it', () => {
    const model = buildRail([], 'fr', ['inspection'], []);
    const list = buildWorklist(model, ['inspection']);
    expect(list.items).toHaveLength(1);
    expect(list.items[0].kind).toBe('gap');
    expect(list.missing).toEqual([]);
  });

  it('orders worst-first across buckets: late before decided before forecast', () => {
    const model = buildRail(
      [due('oil_change', { overdue: true }), due('coolant')], 'fr',
      [],
      [task('fuel_filter', 'open')]);
    const list = buildWorklist(model, ['coolant', 'fuel_filter', 'oil_change']);
    expect(list.items.map((i) => i.type)).toEqual(['oil_change', 'fuel_filter', 'coolant']);
  });

  it('a done task is not work to do — excluded from the keyless projection', () => {
    const model = buildRail([due('oil_change', { overdue: true })], 'fr', [], [task('oil_change', 'done')]);
    expect(buildWorklist(model, []).items).toEqual([]);
  });
});
