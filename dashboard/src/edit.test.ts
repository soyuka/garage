import { describe, expect, it } from 'vitest';
import { coerceInt, defaultUsageUnit, emptyToNull, VEHICLE_USAGE_UNITS } from './edit';

// Pure coercion for the hero inline-edit (#9): a free-text draft → the typed value the vehicle
// merge-PATCH expects. Mirrors the old app's commitInt/commitUsage strip-non-digits contract.

describe('coerceInt', () => {
  it('parses a plain digit string', () => {
    expect(coerceInt('142000')).toBe(142000);
  });
  it('strips a thin-space grouped reading (what formatReading renders into the input)', () => {
    expect(coerceInt('142 000')).toBe(142000); // U+202F narrow no-break space
  });
  it('strips an ascii-space grouped reading', () => {
    expect(coerceInt('142 000')).toBe(142000);
  });
  it('strips a trailing unit', () => {
    expect(coerceInt('2018 km')).toBe(2018);
  });
  it('empty → null (clears a nullable scalar)', () => {
    expect(coerceInt('')).toBeNull();
  });
  it('blank → null', () => {
    expect(coerceInt('   ')).toBeNull();
  });
  it('non-numeric → null', () => {
    expect(coerceInt('abc')).toBeNull();
  });
});

describe('emptyToNull', () => {
  it('trims and keeps a non-empty value', () => {
    expect(emptyToNull('  1.9 TDI ')).toBe('1.9 TDI');
  });
  it('empty → null (clears an optional text field)', () => {
    expect(emptyToNull('')).toBeNull();
  });
  it('blank → null', () => {
    expect(emptyToNull('   ')).toBeNull();
  });
});

describe('defaultUsageUnit', () => {
  it('groundscare meters engine hours → h (garage-side enum)', () => {
    expect(defaultUsageUnit('groundscare')).toBe('h');
  });
  it('a road vehicle → km', () => {
    expect(defaultUsageUnit('car')).toBe('km');
  });
  it('unknown/absent type → km', () => {
    expect(defaultUsageUnit(null)).toBe('km');
    expect(defaultUsageUnit(undefined)).toBe('km');
  });
});

describe('VEHICLE_USAGE_UNITS', () => {
  it('is the garage-side enum spelling (h, never the maintenance-side hours)', () => {
    // The Vehicle resource binds App\Garage\UsageUnit = km|mi|h; the maintenance DTOs use km|mi|hours.
    // The vehicle merge-PATCH must send the garage spelling or the write is rejected.
    expect([...VEHICLE_USAGE_UNITS]).toEqual(['km', 'mi', 'h']);
  });
});
