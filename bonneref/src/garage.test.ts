import { addLabel, garageHref, maintenanceType, planBody, planPart, PlanError, saveBody, saveVehicle } from './garage';
import type { Category, Vehicle } from './types';

const vehicle: Vehicle = {
  plate: 'XB-301-ZB', make: 'MAZDA', model: 'MAZDA6', modelEn: '6 Hatchback', version: '2.0 MZR147', engineCode: 'LFF7',
  powerHp: 200, firstRegistration: '2007-03-29', energy: 'ESSENCE', vin: 'JMZGG14F671528417', carId: 19107, carType: 'PC',
};
const oil: Category = { id: 100259, name: 'Oil Filter', path: 'Filtre > Filtre à huile', segments: ['Filtre', 'Filtre à huile'], label: 'Filtre à huile', leaf: true };

describe('addLabel', () => {
  it('names the vehicle by make and short model', () => {
    expect(addLabel(vehicle)).toBe('Ajouter au Mazda 6');
    expect(addLabel({ ...vehicle, make: 'PEUGEOT', model: '307 (3A/C)', modelEn: null })).toBe('Ajouter au Peugeot 307');
    expect(addLabel({ ...vehicle, make: null, model: null, modelEn: null })).toBe('Ajouter au véhicule');
  });
});

describe('maintenanceType', () => {
  it('maps the drawn families to the garage service vocabulary, else snake-cases the label', () => {
    expect(maintenanceType(oil, { kind: 'filter', filter: 'oil' })).toBe('oil_filter');
    expect(maintenanceType(oil, { kind: 'filter', filter: 'air' })).toBe('air_filter');
    expect(maintenanceType(oil, { kind: 'brake', axle: 'front', component: 'disc' })).toBe('brakes');
    expect(maintenanceType({ ...oil, label: "Bougie d'allumage" }, { kind: 'none' })).toBe('bougie_d_allumage');
  });
});

describe('planBody', () => {
  it('carries the vehicle identity for dedup and the OE ref plus the chosen equivalent', () => {
    const body = planBody(vehicle, oil, { kind: 'filter', filter: 'oil' }, { brand: 'MAZDA', number: 'LF05-14-302A' }, { brand: 'MANN-FILTER', number: 'W 712/73' });
    expect(body).toEqual({
      make: 'MAZDA',
      model: 'MAZDA6',
      carId: 19107,
      plate: 'XB-301-ZB',
      vin: 'JMZGG14F671528417',
      type: 'oil_filter',
      label: 'Filtre à huile',
      source: 'user',
      addedBy: 'finder',
      parts: [
        { ref: 'LF05-14-302A', brand: 'MAZDA', label: 'Filtre à huile' },
        { ref: 'W 712/73', brand: 'MANN-FILTER', label: 'Filtre à huile' },
      ],
    });
  });

  it('skips a missing OE or an equivalent equal to it', () => {
    const one = planBody(vehicle, oil, { kind: 'none' }, { brand: 'MAZDA', number: 'X1' }, { brand: 'MAZDA', number: 'X1' });
    expect(one.parts).toHaveLength(1);
    expect(planBody(vehicle, oil, { kind: 'none' }, null, { brand: 'MAHLE', number: 'OC 1182' }).parts).toEqual([{ ref: 'OC 1182', brand: 'MAHLE', label: 'Filtre à huile' }]);
  });
});

describe('garageHref', () => {
  it('opens the vehicle sheet of the garage page, in bonneref, by the IRI ULID', () => {
    expect(garageHref('/garage/vehicles/01M4X5D9R4FV02S6Q9XW30PBZK')).toBe('#/garage/01M4X5D9R4FV02S6Q9XW30PBZK');
    expect(garageHref('/garage/vehicles/oops')).toBe('#/garage');
  });
});

describe('planPart', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('POSTs the plan and returns the task and the vehicle IRI', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ '@id': '/garage/planned-tasks/01T', vehicle: '/garage/vehicles/01V' }), { status: 201 }));
    vi.stubGlobal('fetch', fetch);
    const body = planBody(vehicle, oil, { kind: 'filter', filter: 'oil' }, { brand: 'MAZDA', number: 'LF05-14-302A' }, null);
    await expect(planPart(body)).resolves.toEqual({ task: '/garage/planned-tasks/01T', vehicle: '/garage/vehicles/01V' });
    const [url, init] = fetch.mock.calls[0]!;
    expect(url).toBe('/garage/planned-tasks');
    expect(init.method).toBe('POST');
    expect(new Headers(init.headers).get('Content-Type')).toBe('application/ld+json');
    expect(JSON.parse(init.body)).toEqual(body);
  });

  it.each([
    [401, 'auth'],
    [409, 'ambiguous'],
    [422, 'invalid'],
    [500, 'failed'],
  ] as const)('maps HTTP %i to %s', async (status, kind) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status })));
    const err = await planPart(planBody(vehicle, oil, { kind: 'none' }, null, null)).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(PlanError);
    expect((err as PlanError).kind).toBe(kind);
  });

  it('maps a network failure', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
    await expect(planPart(planBody(vehicle, oil, { kind: 'none' }, null, null))).rejects.toMatchObject({ kind: 'network' });
  });

  it('hands back the candidates of an ambiguous match, told apart by plate', async () => {
    const conflict = { status: 'ambiguous_match', candidates: [{ '@id': '/garage/vehicles/01A', make: 'PEUGEOT', model: '307', plate: 'AB-123-CD' }, { '@id': '/garage/vehicles/01B', make: 'PEUGEOT', model: '307', plate: null }, { make: 'x' }] };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify(conflict), { status: 409 })));
    const err = (await planPart(planBody(vehicle, oil, { kind: 'none' }, null, null)).catch((e: unknown) => e)) as PlanError;
    expect(err.candidates).toEqual([
      { iri: '/garage/vehicles/01A', plate: 'AB-123-CD', name: 'Peugeot 307' },
      { iri: '/garage/vehicles/01B', plate: null, name: 'Peugeot 307' },
    ]);
  });

  it('re-posts against the vehicle the user picked', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ '@id': '/garage/planned-tasks/01T', vehicle: '/garage/vehicles/01A' }), { status: 201 }));
    vi.stubGlobal('fetch', fetch);
    await planPart({ ...planBody(vehicle, oil, { kind: 'none' }, null, null), vehicle: '/garage/vehicles/01A' });
    expect(JSON.parse(fetch.mock.calls[0]![1].body).vehicle).toBe('/garage/vehicles/01A');
  });
});

describe('saveVehicle', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('saves the car with its identity for dedup and says whether it was new', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ '@id': '/garage/vehicles/01V' }), { status: 201 }));
    vi.stubGlobal('fetch', fetch);
    await expect(saveVehicle(saveBody(vehicle))).resolves.toEqual({ vehicle: '/garage/vehicles/01V', created: true });
    const [url, init] = fetch.mock.calls[0]!;
    expect(url).toBe('/garage/vehicles/save');
    expect(JSON.parse(init.body)).toEqual({ make: 'MAZDA', model: 'MAZDA6', carId: 19107, plate: 'XB-301-ZB', vin: 'JMZGG14F671528417' });
  });

  it('reports an existing match as not created', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ '@id': '/garage/vehicles/01V' }), { status: 200 })));
    await expect(saveVehicle(saveBody(vehicle))).resolves.toEqual({ vehicle: '/garage/vehicles/01V', created: false });
  });

  it('surfaces an ambiguous match with its candidates', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ candidates: [{ '@id': '/garage/vehicles/01A', make: 'MAZDA', model: '6', plate: 'XB-301-ZB' }] }), { status: 409 })));
    await expect(saveVehicle(saveBody(vehicle))).rejects.toMatchObject({ kind: 'ambiguous', candidates: [{ iri: '/garage/vehicles/01A' }] });
  });
});
