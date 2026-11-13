import { ApiError } from './api';
import {
  deleteFact,
  deleteGarageVehicle,
  getGarageVehicle,
  listGarageVehicles,
  listPlannedParts,
  mapGarageSheet,
  mapGarageVehicles,
  mapPlannedParts,
  myVehicleOf,
} from './garage-vehicles';

// Trimmed from GET https://localhost/garage/vehicles (2026-10-16).
const COLLECTION = {
  '@context': '/contexts/GarageVehicle',
  '@id': '/garage/vehicles',
  '@type': 'hydra:Collection',
  'hydra:totalItems': 3,
  'hydra:member': [
    { '@id': '/garage/vehicles/01M4X5D9R4FV02S6Q9XW30PBZK', '@type': 'GarageVehicle', id: '01M4X5D9R4FV02S6Q9XW30PBZK', make: 'PEUGEOT', model: '307', year: 2004, engineVariant: '1.4 KFW (TU3JP) 75 ch', vehicleType: 'car', source: 'user', kType: 16182, plate: 'XD-303-ZD', vin: 'VF33CKFWB83471925', factCount: 52, facts: [], addedAt: '2026-10-14T12:15:19+00:00' },
    {
      '@id': '/garage/vehicles/01KVFEQA72XQMWSS3HPBD93ASR', '@type': 'GarageVehicle', id: '01KVFEQA72XQMWSS3HPBD93ASR', make: 'MAZDA', model: 'MAZDA6', year: 2007, engineVariant: 'GG', engineCode: '2.0 MZR147 LFF7', vehicleType: 'car', source: 'user', kType: 19107, plate: 'XB-301-ZB', vin: 'JMZGG14F671528417', usageValue: 250347, usageUnit: 'km', factCount: 62, facts: [],
      nextDue: { type: 'inspection', action: 'inspect', label: 'inspection', rule: 'schedule', overdue: false, dueAt: '2027-03-21', projectedDueAt: null, daysUntilDue: 155, usageRemaining: null, usageUnit: null },
      addedAt: '2026-06-19T08:07:26+00:00',
    },
    {
      '@id': '/garage/vehicles/01KVKMEFM6V36AWETKE7A9QCJ8', '@type': 'GarageVehicle', id: '01KVKMEFM6V36AWETKE7A9QCJ8', make: 'TORO', model: 'Wheel Horse 246-H', year: 1992, engineVariant: 'Briggs & Stratton', vehicleType: 'groundscare', source: 'user', usageValue: 3, usageUnit: 'h', factCount: 12, facts: [],
      nextDue: { type: 'air_filter', action: 'clean', label: 'foam element', rule: 'schedule', overdue: false, dueAt: '2027-06-21', projectedDueAt: null, daysUntilDue: 247, usageRemaining: 22, usageUnit: 'hours' },
      addedAt: '2026-06-20T23:04:26+00:00',
    },
  ],
};

// Trimmed from GET https://localhost/garage/vehicles/01M4X5D9R4FV02S6Q9XW30PBZK (52 facts there).
const ITEM = {
  '@context': '/contexts/GarageVehicle', '@id': '/garage/vehicles/01M4X5D9R4FV02S6Q9XW30PBZK', '@type': 'GarageVehicle', id: '01M4X5D9R4FV02S6Q9XW30PBZK', make: 'PEUGEOT', model: '307', year: 2004, engineVariant: '1.4 KFW (TU3JP) 75 ch', vehicleType: 'car', source: 'user', kType: 16182, plate: 'XD-303-ZD', vin: 'VF33CKFWB83471925', factCount: 52,
  facts: [
    { key: 'oil_capacity', value: '3 | 39 | 3.25', unit: 'liter', source: 'partsapi', verified: true, addedAt: '2026-10-14T12:19:06+00:00', url: null },
    { key: 'brake_fluid_capacity', value: '1.2', unit: 'liter', source: 'partsapi', verified: true, addedAt: '2026-10-14T12:19:06+00:00', url: null },
    { key: 'first_registration_date', value: '2004-09-15', unit: null, source: 'carte grise', verified: false, addedAt: '2026-10-16T14:16:39+00:00', url: null },
    { key: 'gross_vehicle_weight', value: '1659', unit: 'kg', source: 'user', verified: false, addedAt: '2026-10-14T12:26:23+00:00', url: null },
    { key: 'build_plant', value: 'Mulhouse (88)', unit: null, source: 'web', verified: false, addedAt: '2026-10-14T12:26:23+00:00', url: 'https://www.forum-peugeot.com/Forum/threads/que-signifie-le-opr-le-dam-et-le-apv-pr-dun-v%C3%A9hicule.7579/' },
    { key: 'organisation_number', value: '09824', unit: null, source: 'code de fabrication', verified: false, addedAt: '2026-10-16T20:02:56+00:00', url: null },
    { key: 'build_dam', value: '982488', unit: null, source: 'user', verified: false, addedAt: '2026-10-14T12:26:23+00:00', url: null },
  ],
  health: { status: 'unknown', score: null, overdue: 0, dueSoon: 0, tracked: 0, gaps: 0 },
  addedAt: '2026-10-14T12:15:19+00:00',
};

// Trimmed from GET /garage/vehicles/01KVFEQA72XQMWSS3HPBD93ASR/planned-tasks and …/01KW7CFJP6Y0ARF5287B7Y9CAY/planned-tasks.
const TASKS = {
  '@context': '/contexts/PlannedTask',
  '@type': 'hydra:Collection',
  'hydra:member': [
    { '@id': '/garage/planned-tasks/01KWS2RHWWVEQN18FN0EC6GGEW', '@type': 'PlannedTask', id: '01KWS2RHWWVEQN18FN0EC6GGEW', type: 'fuel_filter', target: { value: 258896, unit: 'km' }, parts: [], status: 'open', vehicle: '/garage/vehicles/01KVFEQA72XQMWSS3HPBD93ASR', createdAt: '2026-07-05T12:06:30+00:00' },
    {
      '@id': '/garage/planned-tasks/01M2B1B91FYH1FR13PQR4XZFGX', '@type': 'PlannedTask', id: '01M2B1B91FYH1FR13PQR4XZFGX', type: 'temperature_sensor', label: 'Capteur température liquide de refroidissement',
      parts: [{ id: '01M2B1B91FYH1FR13PQR4XZFGY', ref: 'TS 10287', label: 'Capteur température liquide de refroidissement', brand: 'Delphi' }],
      status: 'done', vehicle: '/garage/vehicles/01KVFEQA72XQMWSS3HPBD93ASR', createdAt: '2026-09-12T14:46:35+00:00', completedAt: '2026-09-14T11:22:31+00:00',
    },
    {
      '@id': '/garage/planned-tasks/01M4D8R97311BQ0S9NSPR7PC4N', '@type': 'PlannedTask', id: '01M4D8R97311BQ0S9NSPR7PC4N', type: 'clutch', label: 'embrayage',
      parts: [
        { id: '01M4X5CK4YKZ69NZKHCWVPWWMS', ref: '623 3150 33', label: "Kit d'embrayage", brand: 'LuK', qty: 1, priceCents: 18107, supplier: 'trodo.fr' },
        { id: '01M4XTSCEC64NT3V1KDT34CEZC', ref: 'SAE 75W-80', label: 'Huile boîte PK6 — 2,4 ± 0,15 L (MR 361 21A-2), en stock', qty: 3 },
      ],
      status: 'open', vehicle: '/garage/vehicles/01KW7CFJP6Y0ARF5287B7Y9CAY', createdAt: '2026-10-08T08:05:54+00:00',
    },
  ],
};

describe('mapGarageVehicles car type', () => {
  const row = (over: Record<string, unknown>) => mapGarageVehicles({ member: [{ '@id': '/garage/vehicles/01A', kType: 4242, ...over }] })[0]!.carType;

  it('uses the payload carType, a motorbike included', () => {
    expect(row({ vehicleType: 'moto', carType: 'MC' })).toBe('MC');
  });

  it('falls back on the vehicle type for older payloads', () => {
    expect(row({ vehicleType: 'moto' })).toBe('MC');
    expect(row({ vehicleType: 'cv' })).toBe('CV');
    expect(row({ vehicleType: 'car' })).toBe('PC');
  });
});

describe('mapGarageVehicles', () => {
  it('lists every garage vehicle, a lawn tractor without plate or kType included', () => {
    const rows = mapGarageVehicles(COLLECTION);
    expect(rows.map((r) => r.id)).toEqual(['01M4X5D9R4FV02S6Q9XW30PBZK', '01KVFEQA72XQMWSS3HPBD93ASR', '01KVKMEFM6V36AWETKE7A9QCJ8']);
    expect(rows[0]).toEqual({
      id: '01M4X5D9R4FV02S6Q9XW30PBZK',
      iri: '/garage/vehicles/01M4X5D9R4FV02S6Q9XW30PBZK',
      plate: 'XD-303-ZD',
      vin: 'VF33CKFWB83471925',
      make: 'PEUGEOT',
      model: '307',
      name: 'Peugeot 307',
      year: 2004,
      engineVariant: '1.4 KFW (TU3JP) 75 ch',
      engineCode: null,
      vehicleType: 'car',
      carId: 16182,
      carType: 'PC',
      usage: null,
      addedAt: '2026-10-14T12:15:19+00:00',
    });
    expect(rows[2]).toMatchObject({ plate: null, carId: null, name: 'Toro Wheel Horse 246-H', vehicleType: 'groundscare', usage: { value: 3, unit: 'h' } });
  });

  it('names a car without repeating its make in the model', () => {
    expect(mapGarageVehicles(COLLECTION)[1]).toMatchObject({ name: 'Mazda 6', engineCode: '2.0 MZR147 LFF7', usage: { value: 250347, unit: 'km' } });
  });

  it('never carries the maintenance forecast', () => {
    for (const row of mapGarageVehicles(COLLECTION)) expect(JSON.stringify(row)).not.toMatch(/nextDue|health|dueAt/);
  });

  it('reads a bare array and drops rows without an id', () => {
    expect(mapGarageVehicles([{ make: 'X' }, COLLECTION['hydra:member'][0]])).toHaveLength(1);
    expect(mapGarageVehicles(null)).toEqual([]);
  });
});

describe('mapGarageSheet', () => {
  it('carries the identity and every fact with a French label, a printable value and its source', () => {
    const sheet = mapGarageSheet(ITEM);
    expect(sheet.vehicle.plate).toBe('XD-303-ZD');
    expect(JSON.stringify(sheet)).not.toMatch(/health/);
    expect(sheet.facts.map((f) => [f.key, f.label, f.value, f.source])).toEqual([
      ['oil_capacity', 'Capacité d’huile', '3 · 39 · 3,25\u202fL', 'TecDoc'],
      ['brake_fluid_capacity', 'Capacité liquide de frein', '1,2\u202fL', 'TecDoc'],
      ['first_registration_date', 'Première immatriculation', '15 septembre 2004', 'Carte grise'],
      ['gross_vehicle_weight', 'PTAC', '1\u202f659\u202fkg', 'Vous'],
      ['build_plant', 'Usine de fabrication', 'Mulhouse (88)', 'Web'],
      ['organisation_number', 'Numéro d’organisation (OPR)', '09824', 'Code de fabrication'],
      ['build_dam', 'Build dam', '982488', 'Vous'],
    ]);
    expect(sheet.facts[4]!.url).toBe('https://www.forum-peugeot.com/Forum/threads/que-signifie-le-opr-le-dam-et-le-apv-pr-dun-v%C3%A9hicule.7579/');
    expect(sheet.facts[0]!.url).toBeNull();
  });

  it('refuses a payload that is not a garage vehicle', () => {
    expect(() => mapGarageSheet({ make: 'X' })).toThrow(ApiError);
  });
});

describe('mapPlannedParts', () => {
  it('keeps the open tasks with their part references and brands, labelled in French', () => {
    expect(mapPlannedParts(TASKS)).toEqual([
      { id: '01KWS2RHWWVEQN18FN0EC6GGEW', label: 'Filtre à carburant', parts: [] },
      {
        id: '01M4D8R97311BQ0S9NSPR7PC4N',
        label: 'Embrayage',
        parts: [
          { ref: '623 3150 33', brand: 'LuK', label: "Kit d'embrayage", findRoute: null },
          { ref: 'SAE 75W-80', brand: null, label: 'Huile boîte PK6 — 2,4 ± 0,15 L (MR 361 21A-2), en stock', findRoute: null },
        ],
      },
    ]);
  });
});

describe('mapPlannedParts findRoute', () => {
  const task = (parts: unknown[]) => ({ 'hydra:member': [{ id: '01T', type: 'clutch', status: 'open', parts }] });
  const route = '/p/XE304ZE/c/100538/g/247/r/c2/01M5QBGXRK7S728KVD7R4VBHY8';

  it('keeps the find route of a part added from a result, null for older parts', () => {
    expect(mapPlannedParts(task([{ ref: 'A1', findRoute: route }, { ref: 'A2' }]))[0]!.parts).toEqual([
      { ref: 'A1', brand: null, label: null, findRoute: route },
      { ref: 'A2', brand: null, label: null, findRoute: null },
    ]);
  });
});

describe('myVehicleOf', () => {
  it('opens a car in the search like a Mes véhicules pick, and nothing for a machine the catalogue cannot scope', () => {
    const [peugeot, , toro] = mapGarageVehicles(COLLECTION);
    expect(myVehicleOf(peugeot!)).toEqual({ iri: '/garage/vehicles/01M4X5D9R4FV02S6Q9XW30PBZK', plate: 'XD-303-ZD', name: 'Peugeot 307', carId: 16182, carType: 'PC', make: 'PEUGEOT', model: '307', engine: '1.4 KFW (TU3JP) 75 ch' });
    expect(myVehicleOf(toro!)).toBeNull();
  });
});

describe('garage requests', () => {
  afterEach(() => vi.unstubAllGlobals());
  const ok = (body: unknown, status = 200) => vi.fn().mockResolvedValue(new Response(body === null ? null : JSON.stringify(body), { status }));

  it('reads the list, one vehicle and its planned parts as JSON-LD', async () => {
    const fetch = ok(COLLECTION);
    vi.stubGlobal('fetch', fetch);
    await expect(listGarageVehicles()).resolves.toHaveLength(3);
    expect(fetch.mock.calls[0]![0]).toBe('/garage/vehicles');
    expect(new Headers(fetch.mock.calls[0]![1].headers).get('Accept')).toBe('application/ld+json');

    vi.stubGlobal('fetch', ok(ITEM));
    await expect(getGarageVehicle('01M4X5D9R4FV02S6Q9XW30PBZK')).resolves.toMatchObject({ vehicle: { id: '01M4X5D9R4FV02S6Q9XW30PBZK' } });

    const tasks = ok(TASKS);
    vi.stubGlobal('fetch', tasks);
    await expect(listPlannedParts('01KVFEQA72XQMWSS3HPBD93ASR')).resolves.toHaveLength(2);
    expect(tasks.mock.calls[0]![0]).toBe('/garage/vehicles/01KVFEQA72XQMWSS3HPBD93ASR/planned-tasks');
  });

  it('deletes a vehicle and one fact by its key', async () => {
    const fetch = ok(null, 204);
    vi.stubGlobal('fetch', fetch);
    await deleteGarageVehicle('01M4X5D9R4FV02S6Q9XW30PBZK');
    await deleteFact('01M4X5D9R4FV02S6Q9XW30PBZK', 'build_dam');
    expect(fetch.mock.calls.map(([url, init]) => [url, init.method])).toEqual([
      ['/garage/vehicles/01M4X5D9R4FV02S6Q9XW30PBZK', 'DELETE'],
      ['/garage/vehicles/01M4X5D9R4FV02S6Q9XW30PBZK/facts/build_dam', 'DELETE'],
    ]);
  });

  it.each([
    [401, 'auth'],
    [404, 'notFound'],
    [500, 'upstream'],
  ] as const)('maps HTTP %i to %s', async (status, kind) => {
    vi.stubGlobal('fetch', ok({}, status));
    await expect(listGarageVehicles()).rejects.toMatchObject({ kind });
    await expect(deleteFact('01M4X5D9R4FV02S6Q9XW30PBZK', 'x')).rejects.toMatchObject({ kind });
  });

  it('maps a network failure', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
    await expect(getGarageVehicle('01M4X5D9R4FV02S6Q9XW30PBZK')).rejects.toBeInstanceOf(ApiError);
    await expect(deleteGarageVehicle('01M4X5D9R4FV02S6Q9XW30PBZK')).rejects.toMatchObject({ kind: 'network' });
  });
});
