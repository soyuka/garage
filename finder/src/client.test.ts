import * as client from './client';
import { narrowedPath, newTrace, traced, vehicleDescriptor } from './client';
import type { Vehicle } from './types';

describe('client module', () => {
  it('serves the live API only, no recorded mode', () => {
    expect(Object.keys(client)).not.toContain('isDemo');
    expect(Object.keys(client)).toContain('liveApi');
  });
});

describe('narrowedPath', () => {
  it('names the product group only when the route carries one', () => {
    expect(narrowedPath(16182, 'PC', 100198, 'PEUGEOT', 51)).toBe('/cars/16182/part-categories/100198/narrowed?carType=PC&make=PEUGEOT&productGroupId=51');
    expect(narrowedPath(19107, 'PC', 100259, null, null)).toBe('/cars/19107/part-categories/100259/narrowed?carType=PC');
  });
});

describe('traced', () => {
  it('journals the rule and the typed text, and the plate the page knows', () => {
    const h = new Headers(traced({ rule: 'category', input: 'filtre à huile', searchId: 'abc-1', plate: 'XB-301-ZB' }).headers);
    expect(h.get('X-Finder-Rule')).toBe('category');
    expect(h.get('X-Finder-Search')).toBe('abc-1');
    expect(h.get('X-Finder-Input')).toBe(encodeURIComponent('filtre à huile'));
    expect(h.get('X-Finder-Plate')).toBe('XB-301-ZB');
  });

  it('sends no plate header without a plate, and nothing without a trace', () => {
    expect(new Headers(traced({ rule: 'category', input: 'x', searchId: 's' }).headers).has('X-Finder-Plate')).toBe(false);
    expect(new Headers(traced().headers).has('X-Finder-Rule')).toBe(false);
  });
});

describe('journaling is opt-in', () => {
  it('sends neither rule nor input without a trace (restore, hash load, retry, background fetch)', () => {
    const h = new Headers(traced(undefined).headers);
    for (const name of ['X-Finder-Rule', 'X-Finder-Input', 'X-Finder-Search', 'X-Finder-Vehicle']) expect(h.has(name)).toBe(false);
  });

  it('gives every submit its own search id', () => {
    expect(newTrace('plate', 'XC-302-ZC').searchId).not.toBe(newTrace('plate', 'XC-302-ZC').searchId);
  });

  it('sends the vehicle as its chip shows it', () => {
    const v: Vehicle = { plate: null, make: 'MAZDA', model: '6', modelEn: null, version: '2.0', engineCode: 'LF', powerHp: null, firstRegistration: null, energy: null, vin: null, carId: 19107, carType: 'PC' };
    const h = new Headers(traced({ ...newTrace('category', 'filtre'), vehicle: v }).headers);
    expect(JSON.parse(decodeURIComponent(h.get('X-Finder-Vehicle')!))).toEqual({ plate: null, carId: 19107, carType: 'PC', make: 'MAZDA', model: '6', engine: '2.0 · LF' });
    expect(vehicleDescriptor(v).engine).toBe('2.0 · LF');
  });
});
