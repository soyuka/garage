import { describe, it, expect } from 'vitest';
import { buildShoppingList } from './shopping';
import type { PlannedPart, PlannedTask, VehicleBundle } from '../types';

// The shopping list — every reference chosen across the OPEN checklist, grouped by the job it belongs to.
//
// It exists because « prochaines pièces à commander ? » spans tasks and the parts manifest carries ONE
// `taskId`. Asked on the live Mazda, the agent showed a single freshly-fetched BOLK ref while the SEVEN
// references already sitting on three open tasks never appeared.

const part = (ref: string, over: Partial<PlannedPart> = {}): PlannedPart => ({ id: `/p/${ref}`, ref, ...over });

const task = (over: Partial<PlannedTask>): PlannedTask =>
  ({ id: `/t/${over.type}`, type: 'oil_change', parts: [], status: 'open', ...over });

const bundle = (tasks: PlannedTask[]): VehicleBundle =>
  ({ vehicle: { plannedTasks: tasks }, forecast: { due: [], gaps: [] }, records: [] }) as unknown as VehicleBundle;

describe('buildShoppingList', () => {
  it('groups every open task that carries references', () => {
    const list = buildShoppingList(
      bundle([
        task({ type: 'coolant_temperature_sensor', parts: [part('L35G-18-840')] }),
        task({ type: 'engine_mount', id: '/t/mount-a', parts: [part('TEG1101'), part('GJ6A-39-040A')] }),
      ]),
    );
    expect(list.groups.map((g) => g.parts.length)).toEqual([1, 2]);
    expect(list.partCount).toBe(3);
    expect(list.taskCount).toBe(2);
  });

  it('skips a task with no references — nothing to order is not a line', () => {
    const list = buildShoppingList(bundle([task({ type: 'fuel_filter', parts: [] })]));
    expect(list.groups).toEqual([]);
    expect(list.partCount).toBe(0);
  });

  it('ignores done and dismissed tasks — the list is what is still to buy', () => {
    const list = buildShoppingList(
      bundle([
        task({ type: 'oil_change', status: 'done', parts: [part('W712')] }),
        task({ type: 'air_filter', status: 'dismissed', parts: [part('C25114')] }),
        task({ type: 'brake_pads', parts: [part('GDB1330')] }),
      ]),
    );
    expect(list.groups).toHaveLength(1);
    expect(list.groups[0].parts[0].ref).toBe('GDB1330');
  });

  it('totals unit price × quantity, and a missing price counts as zero not NaN', () => {
    const list = buildShoppingList(
      bundle([
        task({ type: 'brake_pads', parts: [part('A', { priceCents: 1250, qty: 2 }), part('B')] }),
      ]),
    );
    expect(list.totalCents).toBe(2500);
  });

  it('reports how many references carry no price, so a total is never passed off as complete', () => {
    // The live Mazda's seven references have no price at all. A "0,00 €" total with no caveat would read
    // as free rather than as unknown.
    const list = buildShoppingList(
      bundle([task({ type: 'brake_pads', parts: [part('A', { priceCents: 1250 }), part('B'), part('C')] })]),
    );
    expect(list.totalCents).toBe(1250);
    expect(list.unpricedCount).toBe(2);
  });

  it('names each group by its task title, so two engine mounts stay tellable apart', () => {
    const list = buildShoppingList(
      bundle([
        task({ type: 'engine_mount', id: '/t/a', label: 'support moteur arrière', parts: [part('TEG1101')] }),
        task({ type: 'engine_mount', id: '/t/b', label: 'support moteur gauche', parts: [part('TEG1128')] }),
      ]),
    );
    expect(list.groups.map((g) => g.title)).toEqual(['support moteur arrière', 'support moteur gauche']);
  });

  it('an empty checklist produces an empty list, not a group of nothing', () => {
    expect(buildShoppingList(bundle([]))).toEqual({ groups: [], partCount: 0, taskCount: 0, totalCents: 0, unpricedCount: 0 });
  });
});
