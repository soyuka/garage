import { describe, expect, it } from 'vitest';
import { formatVehicleHash, parseVehicleHash } from './url';

const iri = (seg: string) => `/garage/vehicles/${seg}`;

describe('parseVehicleHash', () => {
  it('parses a valid #/v/<seg> hash', () => {
    expect(parseVehicleHash('#/v/abc')).toBe('abc');
  });
  it('tolerates a missing leading #', () => {
    expect(parseVehicleHash('/v/abc')).toBe('abc');
  });
  it('returns null for an empty hash', () => {
    expect(parseVehicleHash('')).toBeNull();
  });
  it('returns null for an unrelated hash', () => {
    expect(parseVehicleHash('#/other/abc')).toBeNull();
  });
  it('returns null when the segment is missing', () => {
    expect(parseVehicleHash('#/v/')).toBeNull();
  });
});

describe('formatVehicleHash', () => {
  it('formats an IRI to #/v/<seg>', () => {
    expect(formatVehicleHash(iri('abc'))).toBe('#/v/abc');
  });
  it('roundtrips with parseVehicleHash', () => {
    expect(parseVehicleHash(formatVehicleHash(iri('xyz')))).toBe('xyz');
  });
});
