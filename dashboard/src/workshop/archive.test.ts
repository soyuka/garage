import { describe, expect, it } from 'vitest';
import type { VehicleSummary } from '../types';
import { isArchived, splitArchived } from './archive';

const car = (id: string, archivedAt?: string | null): VehicleSummary => ({
  '@id': id,
  make: 'Renault',
  model: id,
  vehicleType: 'car',
  source: 'user',
  factCount: 0,
  addedAt: '2026-01-01T00:00:00Z',
  archivedAt,
});

describe('isArchived', () => {
  it('is true only for a vehicle carrying an archive date', () => {
    expect(isArchived(car('a', '2026-05-01T00:00:00+00:00'))).toBe(true);
    expect(isArchived(car('b', null))).toBe(false);
    expect(isArchived(car('c'))).toBe(false);
  });
});

describe('splitArchived', () => {
  it('separates active from archived cars, keeping each side in input order', () => {
    const vs = [car('a'), car('b', '2026-05-01T00:00:00+00:00'), car('c', null), car('d', '2026-06-01T00:00:00+00:00')];
    const { active, archived } = splitArchived(vs);
    expect(active.map((v) => v['@id'])).toEqual(['a', 'c']);
    expect(archived.map((v) => v['@id'])).toEqual(['b', 'd']);
  });

  it('returns two empty lists for an empty parc', () => {
    expect(splitArchived([])).toEqual({ active: [], archived: [] });
  });
});
