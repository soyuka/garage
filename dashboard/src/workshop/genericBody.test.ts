import { describe, expect, it } from 'vitest';
import { displayName, isGenericBody, parcThumbUrl, vehicleMesh } from './genericBody';

const v = (make: string, model: string, vehicleType = 'car') => ({ make, model, vehicleType });

describe('isGenericBody', () => {
  it('is false for the cars that have their own mesh', () => {
    expect(isGenericBody(v('Renault', 'Espace V'))).toBe(false);
    expect(isGenericBody(v('Peugeot', '206'))).toBe(false);
    expect(isGenericBody(v('Mazda', '6'))).toBe(false);
  });

  it('is true when the body is the per-type stand-in', () => {
    expect(isGenericBody(v('Renault', 'Clio IV'))).toBe(true);
    expect(isGenericBody(v('Peugeot', '308'))).toBe(true);
    expect(isGenericBody(v('Yamaha', 'MT-07', 'motorcycle'))).toBe(true);
  });
});

describe('parcThumbUrl', () => {
  it('uses the low-poly thumb when it is sound', () => {
    expect(parcThumbUrl(v('Renault', 'Espace V'))).toBe('/renault-espace-iv-thumb.glb');
    expect(parcThumbUrl(v('Yamaha', 'MT-07', 'motorcycle'))).toBe('/moto-default-thumb.glb');
  });

  it('draws the Mazda 6 and the generic car from the low-detail thumb', () => {
    expect(parcThumbUrl(v('Renault', 'Clio IV'))).toBe('/mazda6-gh-thumb.glb');
    expect(parcThumbUrl(v('Mazda', '6'))).toBe('/mazda6-gh-thumb.glb');
  });
});

describe('vehicleMesh — real-data spellings', () => {
  it('resolves uppercase and make-prefixed models', () => {
    expect(vehicleMesh(v('MAZDA', 'MAZDA6')).url).toBe('/mazda6-gh-web.glb');
    expect(vehicleMesh(v('RENAULT', 'ESPACE IV')).url).toBe('/renault-espace-iv-web.glb');
    expect(vehicleMesh(v('PEUGEOT', '206')).url).toBe('/peugeot-206-web.glb');
    expect(vehicleMesh(v('Citroën', 'Citroën C3')).url).toBe('/mazda6-gh-web.glb');
  });

  it('does not take an uppercase Mazda 6 for a generic body', () => {
    expect(isGenericBody(v('MAZDA', 'MAZDA6'))).toBe(false);
    expect(isGenericBody(v('PEUGEOT', '307'))).toBe(true);
  });
});

describe('displayName', () => {
  it('shows the make once, in the owner’s own casing', () => {
    expect(displayName({ make: 'MAZDA', model: 'MAZDA6' })).toBe('MAZDA 6');
    expect(displayName({ make: 'RENAULT', model: 'ESPACE IV' })).toBe('RENAULT ESPACE IV');
    expect(displayName({ make: 'Renault', model: 'Renault Clio IV' })).toBe('Renault Clio IV');
    expect(displayName({ make: 'Citroën', model: 'CITROEN C3' })).toBe('Citroën C3');
    expect(displayName({ make: 'Peugeot', model: '' })).toBe('Peugeot');
  });
});
