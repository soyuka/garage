import { rememberCar, resolveCar, vehicleFromAgent } from './vehicles';

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

describe('resolveCar', () => {
  const index = { carId: 16182, carType: 'PC', make: 'PEUGEOT', model: '307 (3A/C)', engine: '1.4' };

  it('names a bare carId from what the server knows of it', async () => {
    const v = await resolveCar(16182, 'PC', async () => index);
    expect(v).toMatchObject({ plate: null, make: 'PEUGEOT', model: '307 (3A/C)', version: '1.4', carId: 16182, carType: 'PC' });
  });

  it('keeps the car the tab already remembers, without asking the server', async () => {
    rememberCar({ carId: 16182, carType: 'PC', make: 'PEUGEOT', model: '307', engine: '1.6 HDi', plate: null });
    const read = vi.fn(async () => index);
    expect((await resolveCar(16182, 'PC', read)).version).toBe('1.6 HDi');
    expect(read).not.toHaveBeenCalled();
  });

  it('falls back to the bare carId when the server does not know it', async () => {
    sessionStorage.clear();
    const v = await resolveCar(1, 'PC', async () => {
      throw new Error('404');
    });
    expect(v).toMatchObject({ make: null, model: null, carId: 1 });
  });
});
