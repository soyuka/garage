import { describe, it, expect } from 'vitest';
import { rollupCarnet } from './carnet';
import type { MaintenanceRecord } from './types';

// The "Carnet · dépenses" chip rolls the client-held maintenance records into a small projection:
// the last 3 visits (newest first) + the total spent across ALL records + the record count. Pure.

const rec = (over: Partial<MaintenanceRecord>): MaintenanceRecord => ({
  id: '/garage/maintenance-records/x',
  items: [],
  performedAt: '2025-01-01',
  source: 'user',
  ...over,
});

describe('rollupCarnet — records → { rows, totalCents, count }', () => {
  const records = [
    rec({ id: '/r/1', performedAt: '2024-03-01', costCents: 8900, usageValue: 100000, usageUnit: 'km', items: [{ type: 'oil_change', parts: [] }] }),
    rec({ id: '/r/2', performedAt: '2025-06-08', costCents: 12000, items: [{ type: 'brake_pads', parts: [] }, { type: 'brake_discs', parts: [] }] }),
    rec({ id: '/r/3', performedAt: '2023-11-20', costCents: 4500, items: [{ type: 'tyres', label: 'Pneus avant', parts: [] }] }),
    rec({ id: '/r/4', performedAt: '2025-01-15', costCents: null, items: [{ type: 'inspection', parts: [] }] }),
  ];

  it('takes the newest 3 visits, newest first', () => {
    expect(rollupCarnet(records).rows.map((r) => r.id)).toEqual(['/r/2', '/r/4', '/r/1']);
  });

  it('sums the cost across ALL records (missing cost counts as 0) and counts them all', () => {
    const r = rollupCarnet(records);
    expect(r.totalCents).toBe(8900 + 12000 + 4500);
    expect(r.count).toBe(4);
  });

  it('projects each visit its date, odometer, cost, and item labels (label over service label)', () => {
    const row = rollupCarnet(records).rows.find((r) => r.id === '/r/1')!;
    expect(row.performedAt).toBe('2024-03-01');
    expect(row.usageValue).toBe(100000);
    expect(row.usageUnit).toBe('km');
    expect(row.costCents).toBe(8900);
    expect(row.items).toEqual(['Vidange']);
    // a custom item label wins over the derived service label
    expect(rollupCarnet([rec({ items: [{ type: 'tyres', label: 'Pneus avant', parts: [] }] })]).rows[0].items).toEqual(['Pneus avant']);
  });

  it('is empty/zero for no records', () => {
    expect(rollupCarnet([])).toEqual({ rows: [], totalCents: 0, count: 0 });
  });
});
