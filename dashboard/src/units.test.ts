import { describe, expect, it } from 'vitest';
import { cadenceUnit, maintUnitFor, MAINT_USAGE_UNITS } from './units';

// The one place the garage-`h` ↔ maintenance-`hours` divergence is mapped, both directions.

describe('maintUnitFor (garage → maintenance)', () => {
  it('maps the garage hours spelling h → the maintenance hours', () => {
    expect(maintUnitFor('h')).toBe('hours');
  });
  it('keeps mi', () => {
    expect(maintUnitFor('mi')).toBe('mi');
  });
  it('km / null / unknown → km', () => {
    expect(maintUnitFor('km')).toBe('km');
    expect(maintUnitFor(null)).toBe('km');
    expect(maintUnitFor(undefined)).toBe('km');
  });
});

describe('cadenceUnit (maintenance → short cadence spelling)', () => {
  it('shortens hours → h', () => {
    expect(cadenceUnit('hours')).toBe('h');
  });
  it('leaves km / mi untouched', () => {
    expect(cadenceUnit('km')).toBe('km');
    expect(cadenceUnit('mi')).toBe('mi');
  });
});

describe('MAINT_USAGE_UNITS', () => {
  it('is the maintenance spelling (hours, not h)', () => {
    expect([...MAINT_USAGE_UNITS]).toEqual(['km', 'mi', 'hours']);
  });
});
