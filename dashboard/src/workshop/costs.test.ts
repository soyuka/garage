import { describe, expect, it } from 'vitest';
import type { MaintenanceRecord, PlannedPart } from '../types';
import { costByYear, costSeries, costSummary, labelledBars, visitSlots } from './costs';

function visit(id: string, performedAt: string, costCents: number | null, parts: PlannedPart[], usageValue: number | null = null): MaintenanceRecord {
  return { id: `/r/${id}`, items: [{ type: 'oil_change', label: null, parts }], performedAt, usageValue, usageUnit: 'km', costCents, source: 'user' };
}

const part = (ref: string, priceCents?: number, qty?: number): PlannedPart => ({ ref, priceCents, qty });

describe('costSeries', () => {
  it('splits a billed visit whose parts are all priced into parts and the rest', () => {
    const [bar] = costSeries([visit('a', '2025-03-01', 30000, [part('F1', 4000), part('O1', 2500, 2)])]);
    expect(bar).toMatchObject({ id: '/r/a', date: '2025-03-01', totalCents: 30000, partsCents: 9000, restCents: 21000, unsplitCents: 0, partial: false });
  });

  it('shows a billed visit without parts as all rest', () => {
    expect(costSeries([visit('a', '2025-03-01', 12000, [])])[0]).toMatchObject({ partsCents: 0, restCents: 12000, unsplitCents: 0, partial: false });
  });

  it('never invents a split when some parts carry no price: the remainder is left unsplit and the bar partial', () => {
    expect(costSeries([visit('a', '2025-03-01', 30000, [part('F1', 4000), part('X')])])[0]).toMatchObject({
      totalCents: 30000,
      partsCents: 4000,
      restCents: 0,
      unsplitCents: 26000,
      partial: true,
    });
  });

  it('shows only the priced parts when the visit total is missing, marked partial', () => {
    expect(costSeries([visit('a', '2025-03-01', null, [part('F1', 4000)])])[0]).toMatchObject({ totalCents: null, partsCents: 4000, restCents: 0, unsplitCents: 0, partial: true });
  });

  it('marks a bill smaller than its priced parts partial instead of drawing a negative rest', () => {
    expect(costSeries([visit('a', '2025-03-01', 3000, [part('F1', 4000)])])[0]).toMatchObject({ partsCents: 4000, restCents: 0, unsplitCents: 0, partial: true });
  });

  it('leaves out visits with nothing priced at all and sorts the rest by date', () => {
    const bars = costSeries([visit('b', '2025-06-01', 5000, []), visit('none', '2025-01-01', null, [part('X')]), visit('a', '2024-02-01', 7000, [])]);
    expect(bars.map((b) => b.id)).toEqual(['/r/a', '/r/b']);
  });
});

describe('costSummary', () => {
  it('totals what was spent, the parts share of billed visits and the cost per 1 000 km over the logged span', () => {
    const bars = costSeries([
      visit('a', '2023-01-10', 20000, [part('F', 5000)], 100000),
      visit('b', '2025-01-10', 10000, [part('G', 5000)], 120000),
    ]);
    expect(costSummary(bars)).toEqual({ spentCents: 30000, partsShare: 10000 / 30000, perThousandCents: 500, from: '2023-01-10', to: '2025-01-10', visits: 2 });
  });

  it("prices the span between the first and last reading: the first visit's bill paid for the km before it", () => {
    const bars = costSeries([
      visit('first', '2022-01-10', 90000, [], 100000),
      visit('mid', '2023-01-10', 6000, [], 105000),
      visit('unread', '2023-06-10', 4000, []),
      visit('last', '2024-01-10', 4000, [], 110000),
    ]);
    expect(costSummary(bars).perThousandCents).toBe(1400);
  });

  it('counts a partial visit at what is known and leaves it out of the parts share', () => {
    const bars = costSeries([visit('a', '2023-01-10', 20000, [part('F', 5000)]), visit('b', '2024-01-10', null, [part('G', 3000)])]);
    expect(costSummary(bars)).toMatchObject({ spentCents: 23000, partsShare: 0.25, perThousandCents: null });
  });

  it('leaves visits with no part logged out of the parts share: an unitemised bill is not a 0 % parts visit', () => {
    const bars = costSeries([visit('a', '2023-01-10', 20000, [part('F', 5000)]), visit('belt', '2024-01-10', 64000, [])]);
    expect(costSummary(bars).partsShare).toBe(0.25);
    expect(costSummary(costSeries([visit('belt', '2024-01-10', 64000, [])])).partsShare).toBeNull();
  });

  it('has no share and no rate when nothing supports them', () => {
    expect(costSummary([])).toEqual({ spentCents: 0, partsShare: null, perThousandCents: null, from: null, to: null, visits: 0 });
  });
});

describe('labelledBars', () => {
  it('labels every bar that has room, the newest first, so labels never collide or drift off their bar', () => {
    expect(labelledBars([10, 20, 100, 105, 200], 40)).toEqual([false, true, false, true, true]);
    expect(labelledBars([0, 50, 100], 40)).toEqual([true, true, true]);
    expect(labelledBars([], 40)).toEqual([]);
  });
});

describe('visitSlots', () => {
  it('lists every visit oldest first, with its bar when it carries a figure', () => {
    const slots = visitSlots([visit('b', '2025-06-01', 5000, []), visit('none', '2025-01-01', null, [part('X')]), visit('a', '2024-02-01', 7000, [])]);
    expect(slots.map((s) => [s.id, s.bar?.id ?? null])).toEqual([
      ['/r/a', '/r/a'],
      ['/r/none', null],
      ['/r/b', '/r/b'],
    ]);
    expect(slots[1]).toMatchObject({ date: '2025-01-01', record: { id: '/r/none' } });
  });
});

describe('costByYear', () => {
  it('sums priced visits per year, ascending, counting the unpriced ones too', () => {
    const years = costByYear([
      visit('a', '2026-03-01', 10000, []),
      visit('b', '2024-01-01', null, [part('X')]),
      visit('c', '2026-05-01', null, [part('F', 2500, 2)]),
      visit('d', '2026-06-01', null, []),
      visit('e', '2025-02-01', 4000, []),
      visit('f', '2024-09-01', null, []),
    ]);
    expect(years).toEqual([
      { year: '2024', spentCents: 0, pricedVisits: 0, visits: 2 },
      { year: '2025', spentCents: 4000, pricedVisits: 1, visits: 1 },
      { year: '2026', spentCents: 15000, pricedVisits: 2, visits: 3 },
    ]);
  });
});
