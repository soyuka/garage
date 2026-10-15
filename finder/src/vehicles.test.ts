import { vehicleFromAgent } from './vehicles';

describe('vehicleFromAgent', () => {
  it('turns the setVehicle frame into the page vehicle', () => {
    expect(vehicleFromAgent({ carId: 19107, carType: 'PC', make: 'MAZDA', model: '6 (GG)', engine: '2.0 MZR', plate: null })).toEqual({
      plate: null,
      make: 'MAZDA',
      model: '6 (GG)',
      modelEn: null,
      version: '2.0 MZR',
      engineCode: null,
      powerHp: null,
      firstRegistration: null,
      energy: null,
      vin: null,
      carId: 19107,
      carType: 'PC',
    });
  });
});
