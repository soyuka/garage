import { describe, expect, it } from 'vitest';
import { factLabel, serviceLabel } from './labels';

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
