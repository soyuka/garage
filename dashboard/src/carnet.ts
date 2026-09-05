import type { Locale } from './i18n/locale';
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
  costCents: number | null; // effective cost: server's effectiveCostCents, else costCents
  costSource: 'bill' | 'estimated' | null;
  items: string[]; // service item labels (a custom label wins over the derived service label)
}

export interface CarnetRollup {
  rows: CarnetRow[];
  totalCents: number;
  count: number; // total records
  knownCount: number; // records with a known effective cost
  unknownCount: number; // records with no known cost at all
  hasEstimate: boolean; // totalCents includes at least one parts-derived (not billed) cost
}

// The server derives effectiveCostCents (bill when entered, else a parts-sum estimate — see
// MaintenanceRecord::getEffectiveCostCents); costCents is the fallback for a record the API hasn't
// re-serialized with the new field yet.
function effectiveCostCents(r: MaintenanceRecord): number | null {
  return r.effectiveCostCents ?? r.costCents ?? null;
}

// A record predating costSource carried costCents as a hand-entered bill, never an estimate — so a
// known cost with no explicit source defaults to 'bill'.
function costSourceOf(r: MaintenanceRecord): 'bill' | 'estimated' | null {
  return r.costSource ?? (effectiveCostCents(r) != null ? 'bill' : null);
}

/**
 * The agent's CUT of the register, shared with the ring body (`SceneView`'s carnet-focus station) so
 * the legend and the ring always agree — truth first. A visit is kept when it carries any requested
 * type. Empty keys ⇒ the whole register.
 */
export function filterCarnetRecords(records: MaintenanceRecord[], keys: string[]): MaintenanceRecord[] {
  if (keys.length === 0) return records;
  const wanted = new Set(keys);
  return records.filter((r) => r.items.some((it) => wanted.has(it.type)));
}

/**
 * The agent's CUT of the register. « quand la dernière vidange ? » asks about ONE service, and every
 * visit is not its answer — the same narrowing `focusView {panel:'tasks', keys}` already does for the
 * worklist. A visit is kept when it carries any requested type. Empty keys ⇒ the whole register.
 */
export function rollupCarnet(records: MaintenanceRecord[], locale: Locale, limit = 3, keys: string[] = []): CarnetRollup {
  records = filterCarnetRecords(records, keys);
  const sorted = [...records].sort((a, b) => (a.performedAt < b.performedAt ? 1 : a.performedAt > b.performedAt ? -1 : 0));
  const rows: CarnetRow[] = sorted.slice(0, limit).map((r) => ({
    id: r.id,
    performedAt: r.performedAt,
    usageValue: r.usageValue ?? null,
    usageUnit: r.usageUnit ?? null,
    costCents: effectiveCostCents(r),
    costSource: costSourceOf(r),
    items: r.items.map((it) => it.label?.trim() || serviceLabel(it.type, locale)),
  }));
  const totalCents = records.reduce((sum, r) => sum + (effectiveCostCents(r) ?? 0), 0);
  const knownCount = records.filter((r) => effectiveCostCents(r) != null).length;
  const hasEstimate = records.some((r) => costSourceOf(r) === 'estimated');
  return { rows, totalCents, count: records.length, knownCount, unknownCount: records.length - knownCount, hasEstimate };
}
