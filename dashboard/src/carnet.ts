import { serviceLabel } from './labels';
import type { MaintenanceRecord } from './types';

// "Carnet · dépenses" (§H) — the records→rollup for the carnet projection: the last N visits (newest
// first), the total spent across ALL records, and the record count. Pure; the records are already
// client-held (the bundle carries them), so the chip needs no fetch. The projection component formats.

export interface CarnetRow {
  id: string;
  performedAt: string; // YYYY-MM-DD
  usageValue: number | null;
  usageUnit: string | null;
  costCents: number | null;
  items: string[]; // service item labels (a custom label wins over the derived service label)
}

export interface CarnetRollup {
  rows: CarnetRow[];
  totalCents: number;
  count: number;
}

export function rollupCarnet(records: MaintenanceRecord[], limit = 3): CarnetRollup {
  const sorted = [...records].sort((a, b) => (a.performedAt < b.performedAt ? 1 : a.performedAt > b.performedAt ? -1 : 0));
  const rows: CarnetRow[] = sorted.slice(0, limit).map((r) => ({
    id: r.id,
    performedAt: r.performedAt,
    usageValue: r.usageValue ?? null,
    usageUnit: r.usageUnit ?? null,
    costCents: r.costCents ?? null,
    items: r.items.map((it) => it.label?.trim() || serviceLabel(it.type)),
  }));
  const totalCents = records.reduce((sum, r) => sum + (r.costCents ?? 0), 0);
  return { rows, totalCents, count: records.length };
}
