import { listMyVehicles, mapMyVehicles, pickTarget } from './my-vehicles';

const mazda = { '@id': '/garage/vehicles/01A', make: 'MAZDA', model: 'MAZDA6', engineVariant: '2.0 MZR147', engineCode: 'LFF7', vehicleType: 'car', kType: 19107, plate: 'XB-301-ZB' };

describe('mapMyVehicles', () => {
  it('keeps the cars the catalogue can be scoped to, named like the vehicle chip', () => {
    const picks = mapMyVehicles({
      member: [
        mazda,
        { '@id': '/garage/vehicles/01B', make: 'RENAULT', model: 'CLIO III', engineVariant: null, engineCode: null, vehicleType: 'cv', kType: 17010, plate: null },
        { '@id': '/garage/vehicles/01C', make: 'TORO', model: 'Wheel Horse 246-H', vehicleType: 'groundscare', kType: null, plate: null },
      ],
    });
    expect(picks).toEqual([
      { iri: '/garage/vehicles/01A', plate: 'XB-301-ZB', name: 'Mazda 6', carId: 19107, carType: 'PC', make: 'MAZDA', model: 'MAZDA6', engine: '2.0 MZR147 · LFF7' },
      { iri: '/garage/vehicles/01B', plate: null, name: 'Renault Clio', carId: 17010, carType: 'CV', make: 'RENAULT', model: 'CLIO III', engine: null },
    ]);
  });

  it('reads a bare array or hydra:member, and survives junk', () => {
    expect(mapMyVehicles([mazda])).toHaveLength(1);
    expect(mapMyVehicles({ 'hydra:member': [mazda] })).toHaveLength(1);
    expect(mapMyVehicles(null)).toEqual([]);
    expect(mapMyVehicles({ member: [{ make: 'X' }] })).toEqual([]);
  });
});

describe('pickTarget', () => {
  it('opens a car by its plate, else by its kType with the identity kept for the tab', () => {
    const [withPlate, withoutPlate] = mapMyVehicles({ member: [mazda, { ...mazda, '@id': '/garage/vehicles/01B', plate: null }] });
    expect(pickTarget(withPlate!)).toEqual({ ref: 'XB-301-ZB', car: null });
    expect(pickTarget(withoutPlate!)).toEqual({ ref: 'k19107-PC', car: { carId: 19107, carType: 'PC', make: 'MAZDA', model: 'MAZDA6', engine: '2.0 MZR147 · LFF7', plate: null } });
  });
});

describe('listMyVehicles', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('reads the owner garage', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ member: [mazda] }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    await expect(listMyVehicles()).resolves.toHaveLength(1);
    expect(fetch.mock.calls[0]![0]).toBe('/garage/vehicles');
  });

  it('shows nothing when the garage cannot be read', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 401 })));
    await expect(listMyVehicles()).resolves.toEqual([]);
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
    await expect(listMyVehicles()).resolves.toEqual([]);
  });
});

describe('listMyVehicles against a split garage API', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
  });

  it('reads the garage from VITE_GARAGE_API_BASE, not the finder API', async () => {
    vi.stubEnv('VITE_API_BASE', 'https://localhost:8444');
    vi.stubEnv('VITE_GARAGE_API_BASE', 'https://localhost');
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ member: [] }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    await listMyVehicles();
    expect(fetch.mock.calls[0]![0]).toBe('https://localhost/garage/vehicles');
  });
});
