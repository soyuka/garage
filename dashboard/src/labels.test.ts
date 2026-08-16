import { describe, expect, it } from 'vitest';
import { canonicalService, FACT_LABEL, factLabel, HEALTH_LABEL, SERVICE_LABEL, SERVICE_TYPES, serviceLabel } from './labels';

// factLabel (#9 facts section): labels for known fact keys, with an honest humanizing fallback so an
// unmapped key still reads (never a raw snake_case key on screen).
//
// Every French expectation here is unchanged from before the locale argument — the French vocabulary
// is the one this garage already speaks and none of it was re-worded.

describe('factLabel', () => {
  it('maps a known spec key', () => {
    expect(factLabel('oil_type', 'fr')).toBe('Type d’huile');
    expect(factLabel('oil_type', 'en')).toBe('Oil type');
  });
  it('maps a {type}_ref part-derived key', () => {
    expect(factLabel('oil_filter_ref', 'fr')).toBe('Référence filtre à huile');
    expect(factLabel('oil_filter_ref', 'en')).toBe('Oil filter ref.');
  });
  it('humanizes an unmapped key (underscores → spaces, capitalized) in either language', () => {
    // The fallback is language-neutral by nature: it is the operator's OWN key, echoed back.
    expect(factLabel('custom_thing', 'fr')).toBe('Custom thing');
    expect(factLabel('custom_thing', 'en')).toBe('Custom thing');
  });
});

describe('serviceLabel', () => {
  it('maps a known service type', () => {
    expect(serviceLabel('oil_change', 'fr')).toBe('Vidange');
    expect(serviceLabel('oil_change', 'en')).toBe('Oil change');
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
  // Types the garage actually holds beyond the service catalogue — they were falling through to the
  // humanized-key fallback ("Engine mount") and lit no body region on the car.
  it('labels the mechanical repair types', () => {
    expect(serviceLabel('engine_mount', 'fr')).toBe('Support moteur');
    expect(serviceLabel('coolant_temperature_sensor', 'fr')).toBe('Sonde de température');
    expect(serviceLabel('water_pump', 'fr')).toBe('Pompe à eau');
    expect(serviceLabel('ignition_coil', 'fr')).toBe("Bobine d'allumage");
    expect(serviceLabel('engine_mount', 'en')).toBe('Engine mount');
    expect(serviceLabel('water_pump', 'en')).toBe('Water pump');
  });

  it('labels a variant the same as its canonical key', () => {
    expect(serviceLabel('technical_inspection', 'fr')).toBe('Contrôle technique');
    expect(serviceLabel('spark_plugs', 'fr')).toBe('Bougies');
    expect(serviceLabel('tires', 'fr')).toBe('Pneus');
    expect(serviceLabel('technical_inspection', 'en')).toBe('Roadworthiness test');
    expect(serviceLabel('tires', 'en')).toBe('Tyres');
  });
});

describe('HEALTH_LABEL', () => {
  it('reads in both languages', () => {
    expect(HEALTH_LABEL.fr.green).toBe('Sain');
    expect(HEALTH_LABEL.fr.red).toBe('En retard');
    expect(HEALTH_LABEL.en.green).toBe('Healthy');
    expect(HEALTH_LABEL.en.red).toBe('Overdue');
  });
});

// Both tables are keyed by the BACKEND's snake_case vocabulary. A key present in one language and
// absent from the other silently falls through to the humanized raw key — which reads as a bug on
// screen and nowhere else. Pin the key sets rather than the words.
describe('the two catalogs cover the same keys', () => {
  it('the service tables agree on their key set', () => {
    expect(Object.keys(SERVICE_LABEL.en).sort()).toEqual(Object.keys(SERVICE_LABEL.fr).sort());
  });

  it('the fact tables agree on their key set', () => {
    expect(Object.keys(FACT_LABEL.en).sort()).toEqual(Object.keys(FACT_LABEL.fr).sort());
  });

  it('every type the checklist offers has a label in both languages', () => {
    for (const type of SERVICE_TYPES) {
      expect(SERVICE_LABEL.fr[canonicalService(type)]).toBeDefined();
      expect(SERVICE_LABEL.en[canonicalService(type)]).toBeDefined();
    }
  });
});
