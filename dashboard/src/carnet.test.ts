import { describe, it, expect } from 'vitest';
import { rollupCarnet } from './carnet';
import type { MaintenanceRecord } from './types';

// The "Carnet · dépenses" chip rolls the client-held maintenance records into a small projection:
// the last 3 visits (newest first) + the total spent across ALL records + the record count. Pure.
// Cost is the server-derived EFFECTIVE cost (bill when entered, else a parts-sum estimate) — the
// rollup prefers `effectiveCostCents` and falls back to `costCents` for records the API hasn't
// re-serialized yet.

const rec = (over: Partial<MaintenanceRecord>): MaintenanceRecord => ({
  id: '/garage/maintenance-records/x',
  items: [],
  performedAt: '2025-01-01',
  source: 'user',
  ...over,
});

describe('rollupCarnet — records → { rows, totalCents, count, knownCount, unknownCount }', () => {
  const records = [
    rec({
      id: '/r/1',
      performedAt: '2024-03-01',
      costCents: 8900,
      effectiveCostCents: 8900,
      costSource: 'bill',
      usageValue: 100000,
      usageUnit: 'km',
      items: [{ type: 'oil_change', parts: [] }],
    }),
    rec({
      id: '/r/2',
      performedAt: '2025-06-08',
      costCents: 12000,
      effectiveCostCents: 12000,
      costSource: 'bill',
      items: [{ type: 'brake_pads', parts: [] }, { type: 'brake_discs', parts: [] }],
    }),
    rec({
      id: '/r/3',
      performedAt: '2023-11-20',
      costCents: null,
      effectiveCostCents: 4500,
      costSource: 'estimated',
      items: [{ type: 'tyres', label: 'Pneus avant', parts: [] }],
    }),
    rec({
      id: '/r/4',
      performedAt: '2025-01-15',
      costCents: null,
      effectiveCostCents: null,
      costSource: null,
      items: [{ type: 'inspection', parts: [] }],
    }),
  ];

  it('takes the newest 3 visits, newest first', () => {
    expect(rollupCarnet(records).rows.map((r) => r.id)).toEqual(['/r/2', '/r/4', '/r/1']);
  });

  it('sums the EFFECTIVE cost across ALL records (unknown counts as 0) and counts them all', () => {
    const r = rollupCarnet(records);
    expect(r.totalCents).toBe(8900 + 12000 + 4500);
    expect(r.count).toBe(4);
  });

  it('counts records with a known vs. unknown effective cost', () => {
    const r = rollupCarnet(records);
    expect(r.knownCount).toBe(3);
    expect(r.unknownCount).toBe(1);
  });

  it('projects each visit its date, odometer, effective cost + source, and item labels', () => {
    const row = rollupCarnet(records).rows.find((r) => r.id === '/r/1')!;
    expect(row.performedAt).toBe('2024-03-01');
    expect(row.usageValue).toBe(100000);
    expect(row.usageUnit).toBe('km');
    expect(row.costCents).toBe(8900);
    expect(row.costSource).toBe('bill');
    expect(row.items).toEqual(['Vidange']);
    // a custom item label wins over the derived service label
    expect(rollupCarnet([rec({ items: [{ type: 'tyres', label: 'Pneus avant', parts: [] }] })]).rows[0].items).toEqual(['Pneus avant']);
  });

  it('row cost prefers effectiveCostCents and marks it estimated when the bill is unknown', () => {
    const row = rollupCarnet(records, 10).rows.find((r) => r.id === '/r/3')!;
    expect(row.costCents).toBe(4500);
    expect(row.costSource).toBe('estimated');
  });

  it('falls back to costCents when effectiveCostCents is absent (API not yet re-serialized)', () => {
    const legacy = [rec({ id: '/r/5', costCents: 3000 })];
    const r = rollupCarnet(legacy);
    expect(r.rows[0].costCents).toBe(3000);
    expect(r.totalCents).toBe(3000);
    expect(r.knownCount).toBe(1);
    expect(r.unknownCount).toBe(0);
  });

  it('flags the total as an estimate when any contributing record is parts-derived (not just billed)', () => {
    expect(rollupCarnet(records).hasEstimate).toBe(true);
    const allBilled = records.filter((r) => r.costSource !== 'estimated');
    expect(rollupCarnet(allBilled).hasEstimate).toBe(false);
  });

  it('is empty/zero for no records', () => {
    expect(rollupCarnet([])).toEqual({ rows: [], totalCents: 0, count: 0, knownCount: 0, unknownCount: 0, hasEstimate: false });
  });
});
