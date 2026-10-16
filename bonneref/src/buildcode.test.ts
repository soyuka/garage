import { buildCodeFor, buildCodeLabel, garageMatch, rememberBuildCode, vehicleKey } from './buildcode';
import type { MyVehicle } from './my-vehicles';
import type { Vehicle } from './types';

const car = (over: Partial<Vehicle> = {}): Vehicle => ({
  plate: 'XD-303-ZD', make: 'PEUGEOT', model: '307', modelEn: null, version: null, engineCode: null, powerHp: null, firstRegistration: null, energy: null, vin: null, carId: 16182, carType: 'PC', ...over,
});
const code = { organisationNumber: '09824', date: '2003-10-02' };

beforeEach(() => sessionStorage.clear());

describe('buildCodeLabel', () => {
  it('prints the OPR and the month it decodes to', () => {
    expect(buildCodeLabel(code)).toBe('OPR 09824 · oct. 2003');
    expect(buildCodeLabel({ organisationNumber: '10311', date: '2005-02-01' })).toBe('OPR 10311 · févr. 2005');
  });
});

describe('build code on the vehicle', () => {
  it('keys a car by its plate, else by its catalogue ref', () => {
    expect(vehicleKey(car())).toBe('XD-303-ZD');
    expect(vehicleKey(car({ plate: null }))).toBe('k16182-PC');
    expect(vehicleKey(car({ plate: null, carId: null }))).toBeNull();
  });

  it('keeps the code for the session, for that car only', () => {
    rememberBuildCode(car(), code);
    expect(buildCodeFor(car())).toEqual(code);
    expect(buildCodeFor(car({ plate: 'XB-301-ZB' }))).toBeNull();
    rememberBuildCode(car({ plate: null }), code);
    expect(buildCodeFor(car({ plate: null }))).toEqual(code);
  });

  it('ignores a malformed stored entry', () => {
    sessionStorage.setItem('bonneref.buildCodes', JSON.stringify({ 'XD-303-ZD': { organisationNumber: 'x' } }));
    expect(buildCodeFor(car())).toBeNull();
  });
});

describe('garageMatch', () => {
  const mine = (over: Partial<MyVehicle>): MyVehicle => ({ iri: '/garage/vehicles/1', plate: null, name: null, carId: null, carType: 'PC', make: null, model: null, engine: null, ...over });

  it('finds the saved car by plate, else by kType when neither has a plate', () => {
    expect(garageMatch([mine({ iri: '/a', plate: 'XB-301-ZB' }), mine({ iri: '/b', plate: 'XD-303-ZD', carId: 1 })], car())).toBe('/b');
    expect(garageMatch([mine({ iri: '/c', carId: 16182 })], car({ plate: null }))).toBe('/c');
    expect(garageMatch([mine({ iri: '/d', carId: 16182, plate: 'XX-000-XX' })], car())).toBeNull();
    expect(garageMatch([], car())).toBeNull();
  });
});
