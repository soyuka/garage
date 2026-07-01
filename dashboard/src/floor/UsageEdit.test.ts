import { describe, it, expect } from 'vitest';
import { nextUsageOnUnitChange } from './UsageEdit';

// The unit select re-stamps an existing reading immediately (value+unit always travel together);
// with no reading yet there is nothing to commit.

describe('nextUsageOnUnitChange — re-stamp payload on unit switch', () => {
  it('pairs the held value with the new unit when a reading exists', () => {
    expect(nextUsageOnUnitChange(142000, 'mi')).toEqual({ value: 142000, unit: 'mi' });
  });

  it('is null when there is no reading to re-stamp', () => {
    expect(nextUsageOnUnitChange(null, 'mi')).toBeNull();
  });
});
