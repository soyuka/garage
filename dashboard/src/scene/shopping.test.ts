import { describe, it, expect } from 'vitest';
import { buildShoppingList, partShopLinks } from './shopping';
import { safeHref } from '../safeHref';
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

// Shop-search links, restored from 44cb4fb ("part shop-search links on open tasks") — lost in 5284f8f
// when the Atelier replaced the old dashboard. A reference the operator cannot go and BUY is a reference
// they have to retype into a search box.
describe('partShopLinks', () => {
  it('builds a Mister-Auto and an Auto-Doc search from ref + brand + label', () => {
    const links = partShopLinks(part('W 712/75', { brand: 'MANN', label: 'Filtre à huile' }));
    expect(links.map((l) => l.name)).toEqual(['Mister-Auto', 'Auto-Doc']);
    expect(links[0].url).toBe('https://www.mister-auto.com/rechercher/?q=W%20712%2F75%20MANN%20Filtre%20%C3%A0%20huile');
  });

  it("uses '+' for spaces on Auto-Doc, whose search rejects %20 — the rest of the encoding is identical", () => {
    const [, autodoc] = partShopLinks(part('W 712/75', { brand: 'MANN' }));
    expect(autodoc.url).toBe('https://www.auto-doc.fr/search?keyword=W+712%2FMANN'.replace('%2FMANN', '%2F75+MANN'));
  });

  it('works from a bare reference', () => {
    expect(partShopLinks(part('TEG1101'))[0].url).toContain('q=TEG1101');
  });

  it('offers nothing when there is nothing searchable', () => {
    expect(partShopLinks({ ref: '' })).toEqual([]);
  });

  it('every URL survives safeHref — these are rendered as anchors', () => {
    for (const l of partShopLinks(part('X', { brand: 'Y' }))) expect(safeHref(l.url)).toBe(l.url);
  });
});
