import type { Iri, MaintenanceRecord } from '../types';
import { normalizeUnit } from './kmTrack';

export interface CostBar {
  id: Iri;
  date: string;
  record: MaintenanceRecord;
  /** The visit bill, when one was logged. */
  totalCents: number | null;
  /** Priced parts only (unit price × quantity). */
  partsCents: number;
  /** Bill minus priced parts, drawn only when every part is priced. */
  restCents: number;
  /** Bill minus priced parts when some parts carry no price: not attributable to labour or parts. */
  unsplitCents: number;
  /** The split is incomplete: no bill, unpriced parts, or parts priced above the bill. */
  partial: boolean;
}

export interface CostSummary {
  spentCents: number;
  /** Priced parts over the bills of fully split visits that logged parts at all. */
  partsShare: number | null;
  perThousandCents: number | null;
  from: string | null;
  to: string | null;
  visits: number;
}

const PER_DISTANCE = 1000;

/** One bar per visit that carries any figure, oldest first; never a split the data does not support. */
export function costSeries(records: MaintenanceRecord[]): CostBar[] {
  const bars: CostBar[] = [];
  for (const record of records) {
    const parts = record.items.flatMap((i) => i.parts);
    const priced = parts.filter((p) => p.priceCents != null);
    const partsCents = priced.reduce((sum, p) => sum + (p.priceCents ?? 0) * (p.qty ?? 1), 0);
    const totalCents = record.costCents ?? null;
    if (totalCents === null && priced.length === 0) continue;
    const allPriced = priced.length === parts.length;
    const remainder = totalCents === null ? 0 : totalCents - partsCents;
    const over = remainder < 0;
    bars.push({
      id: record.id,
      date: record.performedAt,
      record,
      totalCents,
      partsCents,
      restCents: totalCents !== null && allPriced && !over ? remainder : 0,
      unsplitCents: totalCents !== null && !allPriced && !over ? remainder : 0,
      partial: totalCents === null || !allPriced || over,
    });
  }
  return bars.sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0));
}

export interface VisitSlot {
  id: Iri;
  date: string;
  record: MaintenanceRecord;
  bar: CostBar | null;
}

/** Every visit, oldest first, whether or not it carries a figure. */
export function visitSlots(records: MaintenanceRecord[]): VisitSlot[] {
  const bars = new Map(costSeries(records).map((b) => [b.id, b]));
  return records
    .map((record) => ({ id: record.id, date: record.performedAt, record, bar: bars.get(record.id) ?? null }))
    .sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0));
}

export interface YearCost {
  year: string;
  spentCents: number;
  pricedVisits: number;
  visits: number;
}

export function costByYear(records: MaintenanceRecord[]): YearCost[] {
  const years = new Map<string, YearCost>();
  for (const slot of visitSlots(records)) {
    const year = slot.date.slice(0, 4);
    const entry = years.get(year) ?? { year, spentCents: 0, pricedVisits: 0, visits: 0 };
    entry.visits += 1;
    if (slot.bar) {
      entry.pricedVisits += 1;
      entry.spentCents += barCents(slot.bar);
    }
    years.set(year, entry);
  }
  return [...years.values()];
}

export function barCents(bar: CostBar): number {
  return Math.max(bar.totalCents ?? 0, bar.partsCents);
}

export function costSummary(bars: CostBar[]): CostSummary {
  const spentCents = bars.reduce((sum, b) => sum + barCents(b), 0);
  const split = bars.filter((b) => !b.partial && b.totalCents !== null && b.record.items.some((i) => i.parts.length > 0));
  const billed = split.reduce((sum, b) => sum + (b.totalCents ?? 0), 0);
  const read = bars.filter((b) => b.record.usageValue != null && normalizeUnit(b.record.usageUnit) === 'km');
  const first = read[0];
  const last = read[read.length - 1];
  const span = first && last ? (last.record.usageValue as number) - (first.record.usageValue as number) : 0;
  // The first reading's bill paid for the km before it; the span is priced by what was spent after it, up to the last reading.
  const spanCents = first && last ? bars.filter((b) => b.date > first.date && b.date <= last.date).reduce((sum, b) => sum + barCents(b), 0) : 0;
  return {
    spentCents,
    partsShare: billed > 0 ? split.reduce((sum, b) => sum + b.partsCents, 0) / billed : null,
    perThousandCents: span >= PER_DISTANCE ? Math.round((spanCents / span) * PER_DISTANCE) : null,
    from: bars[0]?.date ?? null,
    to: bars[bars.length - 1]?.date ?? null,
    visits: bars.length,
  };
}

/** Which bars get a date/total label: newest first, each at least `gap` from the last one labelled. */
export function labelledBars(xs: number[], gap: number): boolean[] {
  const out = xs.map(() => false);
  let last = Number.POSITIVE_INFINITY;
  for (let i = xs.length - 1; i >= 0; i--) {
    if (last - xs[i] >= gap) {
      out[i] = true;
      last = xs[i];
    }
  }
  return out;
}
