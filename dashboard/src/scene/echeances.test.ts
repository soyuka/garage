import { describe, it, expect } from 'vitest';
import { buildEcheances } from './echeances';
import type { ForecastDue, PlannedTask } from '../types';

// The échéances as a FLAT, severity-sorted list (no region grouping): each due/task/gap is its own item,
// worst-first (red → amber → green → done/gap), rendered as a vertical list in the task dock (EcheanceList).

function due(type: string, over: Partial<ForecastDue> = {}): ForecastDue {
  return {
    type, action: null, component: null, rule: 'schedule', lastPerformedAt: '2024-11-15',
    dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: 365, usage: null, ...over,
  };
}
const task = (type: string, status: string, id = `/t/${type}`): PlannedTask => ({ id, type, parts: [], status });

describe('buildEcheances — flat, severity-sorted (red → amber → green → gap)', () => {
  it('sorts worst-first regardless of input order', () => {
    const items = buildEcheances([
      due('coolant'), // green (365d)
      due('brake_fluid', { daysUntilDue: 12 }), // amber
      due('oil_change', { overdue: true }), // red
    ], 'fr');
    expect(items.map((i) => i.signal)).toEqual(['red', 'amber', 'green']);
    expect(items[0].type).toBe('oil_change');
  });

  it('emits one item per service — no region grouping', () => {
    const items = buildEcheances([due('oil_change'), due('coolant')], 'fr'); // both hood, but stay separate
    expect(items).toHaveLength(2);
    expect(items.map((i) => i.type)).toEqual(['oil_change', 'coolant']);
  });

  it('binds a due to its open task → a checkable item', () => {
    const [i] = buildEcheances([due('oil_change', { overdue: true })], 'fr', [], [task('oil_change', 'open')]);
    expect(i.kind).toBe('task');
    expect(i.taskId).toBe('/t/oil_change');
    expect(i.done).toBe(false);
  });

  it('an unplanned due is a prediction item carrying its due', () => {
    const [i] = buildEcheances([due('oil_change', { overdue: true })], 'fr');
    expect(i.kind).toBe('due');
    expect(i.due?.type).toBe('oil_change');
  });

  it('a gap sorts last and has no checkbox', () => {
    const items = buildEcheances([due('oil_change', { overdue: true })], 'fr', ['air_filter']);
    expect(items[items.length - 1].kind).toBe('gap');
    expect(items[items.length - 1].taskId).toBeUndefined();
  });

  // Two open tasks of the SAME type are two distinct jobs (the Mazda's rear AND left engine mounts).
  // Keying the merge by type collapsed them into one and the second never rendered anywhere.
  it('emits EVERY open task, including several of the same type', () => {
    const items = buildEcheances([], 'fr', [], [
      { id: '/t/mount-rear', type: 'engine_mount', label: 'support moteur arrière', parts: [], status: 'open' },
      { id: '/t/mount-left', type: 'engine_mount', label: 'support moteur gauche', parts: [], status: 'open' },
    ]);
    expect(items.map((i) => i.taskId)).toEqual(['/t/mount-rear', '/t/mount-left']);
  });

  it('shows a task label when it carries one, so same-type jobs are distinguishable', () => {
    const [i] = buildEcheances([], 'fr', [], [
      { id: '/t/mount-rear', type: 'engine_mount', label: 'support moteur arrière', parts: [], status: 'open' },
    ]);
    expect(i.label).toBe('support moteur arrière');
  });

  it('emits a second same-type task even when a due already bound the first', () => {
    const items = buildEcheances([due('engine_mount')], 'fr', [], [
      { id: '/t/mount-rear', type: 'engine_mount', parts: [], status: 'open' },
      { id: '/t/mount-left', type: 'engine_mount', label: 'gauche', parts: [], status: 'open' },
    ]);
    expect(items.map((i) => i.taskId)).toEqual(['/t/mount-rear', '/t/mount-left']);
    expect(items[0].due?.type).toBe('engine_mount');
  });

  // Two cadences of ONE type are legitimate schedule rules, not a forecast fault: the backend keys the
  // schedule by type+action+component (`ServiceInterval::key()`), so `spark_plug:inspect` and
  // `spark_plug:replace` both forecast. Collapsing them on `type` alone collided the React key and
  // printed the same label twice.
  describe('two cadences of one service type', () => {
    const inspect = due('spark_plug', { action: 'inspect' });
    const replace = due('spark_plug', { action: 'replace' });

    it('gives each its own identity', () => {
      const items = buildEcheances([inspect, replace], 'fr');
      expect(items).toHaveLength(2);
      expect(new Set(items.map((i) => i.id)).size).toBe(2);
    });

    it('distinguishes them on screen — the action, and the component when it carries one', () => {
      const [a, b] = buildEcheances([inspect, replace], 'fr');
      expect(a.label).not.toBe(b.label);
      expect(a.label).toContain('Bougies');

      const [foam, paper] = buildEcheances([
        due('air_filter', { action: 'clean', component: 'élément mousse' }),
        due('air_filter', { action: 'replace', component: 'cartouche papier' }),
      ], 'fr');
      expect(foam.label).toContain('élément mousse');
      expect(paper.label).toContain('cartouche papier');
    });

    it('lets only ONE cadence claim a same-type open task — a task has no action to bind on', () => {
      const items = buildEcheances([inspect, replace], 'fr', [], [task('spark_plug', 'open')]);
      expect(new Set(items.map((i) => i.id)).size).toBe(items.length);
      expect(items.filter((i) => i.kind === 'task')).toHaveLength(1);
      expect(items.filter((i) => i.taskId === '/t/spark_plug')).toHaveLength(1);
    });
  });

  it('a done task sinks below open work', () => {
    const items = buildEcheances([due('oil_change', { overdue: true })], 'fr', [], [task('oil_change', 'done'), task('brake_pads', 'open')]);
    const done = items.find((i) => i.done);
    const open = items.find((i) => i.type === 'brake_pads');
    expect(items.indexOf(open!)).toBeLessThan(items.indexOf(done!));
  });
});
