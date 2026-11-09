import { describe, expect, it } from 'vitest';
import { canonicalService, FACT_LABEL, factLabel, fluidLabel, HEALTH_LABEL, SERVICE_LABEL, SERVICE_TYPES, serviceLabel } from './labels';

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

describe('factLabel — key families from the catalog', () => {
  it('labels capacities, types and codes by suffix, in both languages', () => {
    expect(factLabel('gearbox_oil_capacity', 'fr')).toBe('Capacité d’huile de boîte');
    expect(factLabel('gearbox_oil_capacity', 'en')).toBe('Gearbox oil capacity');
    expect(factLabel('power_steering_oil_type', 'fr')).toBe('Type d’huile de direction assistée');
    expect(factLabel('ac_refrigerant_capacity', 'fr')).toBe('Capacité de réfrigérant de climatisation');
    expect(factLabel('ac_refrigerant_capacity', 'en')).toBe('A/C refrigerant capacity');
    expect(factLabel('engine_code', 'fr')).toBe('Code moteur');
    expect(factLabel('gearbox_code', 'fr')).toBe('Code boîte de vitesses');
    expect(factLabel('auto_gearbox_oil_capacity', 'fr')).toBe('Capacité d’huile de boîte automatique');
    expect(factLabel('coolant_capacity', 'fr')).toBe('Capacité de liquide de refroidissement');
    expect(factLabel('brake_fluid_type', 'fr')).toBe('Type de liquide de frein');
    expect(factLabel('fuel_tank_capacity', 'fr')).toBe('Capacité du réservoir');
    expect(factLabel('ac_oil_type', 'fr')).toBe('Type d’huile de climatisation');
  });

  it('keeps the humanizing fallback for a noun it does not know', () => {
    expect(factLabel('widget_capacity', 'fr')).toBe('Widget capacity');
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
    expect(serviceLabel('clutch', 'fr')).toBe('Embrayage');
    expect(serviceLabel('clutch', 'en')).toBe('Clutch');
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

describe('fluidLabel', () => {
  it('names a fluid by itself, capitalised, in the reader language', () => {
    expect(fluidLabel('gearbox_oil', 'fr')).toBe('Huile de boîte');
    expect(fluidLabel('coolant', 'en')).toBe('Coolant');
    expect(fluidLabel('mystery', 'fr')).toBeNull();
  });
});

describe('factLabel — datasheet keys', () => {
  it('labels the carte-grise and tyre keys in French rather than humanised English', () => {
    expect(factLabel('engine_power', 'fr')).toBe('Puissance');
    expect(factLabel('tires_front_dimensions', 'fr')).toBe('Pneus avant');
    expect(factLabel('gross_vehicle_weight', 'fr')).toBe('PTAC');
    expect(factLabel('clutch_kit_ref', 'fr')).toBe('Référence kit d’embrayage');
    expect(factLabel('tires_front_dimensions', 'en')).toBe('Front tyres');
  });
});

describe('factLabel — build plate keys', () => {
  it('labels the PSA build plate identifiers', () => {
    expect(factLabel('build_apv_tail', 'fr')).toBe('Numéro APV');
    expect(factLabel('build_organisation_number', 'fr')).toBe('Numéro d’organisation (OPR)');
    expect(factLabel('build_apv_tail', 'en')).toBe('APV number');
  });
});
