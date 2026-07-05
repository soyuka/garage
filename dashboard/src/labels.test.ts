import { describe, expect, it } from 'vitest';
import { canonicalService, factLabel, serviceLabel } from './labels';

// factLabel (#9 facts section): French labels for known fact keys, with an honest humanizing
// fallback so an unmapped key still reads (never a raw snake_case key on screen).

describe('factLabel', () => {
  it('maps a known spec key', () => {
    expect(factLabel('oil_type')).toBe('Type d’huile');
  });
  it('maps a {type}_ref part-derived key', () => {
    expect(factLabel('oil_filter_ref')).toBe('Référence filtre à huile');
  });
  it('humanizes an unmapped key (underscores → spaces, capitalized)', () => {
    expect(factLabel('custom_thing')).toBe('Custom thing');
  });
});

describe('serviceLabel (regression — unchanged by factLabel addition)', () => {
  it('still maps a known service type', () => {
    expect(serviceLabel('oil_change')).toBe('Vidange');
  });
});

// canonicalService folds backend spelling variants onto one key, declared once and shared with the
// scene's region lookups — so serviceLabel + regionForType can never disagree on what an alias means.
describe('canonicalService + alias-folded labels', () => {
  it('folds each known spelling variant onto its canonical key', () => {
    expect(canonicalService('technical_inspection')).toBe('inspection');
    expect(canonicalService('spark_plugs')).toBe('spark_plug');
    expect(canonicalService('tires')).toBe('tyres');
  });
  it('is identity for a canonical or unknown type', () => {
    expect(canonicalService('oil_change')).toBe('oil_change');
    expect(canonicalService('custom_thing')).toBe('custom_thing');
  });
  it('labels a variant the same as its canonical key', () => {
    expect(serviceLabel('technical_inspection')).toBe('Contrôle technique');
    expect(serviceLabel('spark_plugs')).toBe('Bougies');
    expect(serviceLabel('tires')).toBe('Pneus');
  });
});
