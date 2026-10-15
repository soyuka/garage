import { addLabel, atelierHref, maintenanceType, planBody, planPart, PlanError } from './garage';
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
    expect(maintenanceType(oil, { kind: 'brake', axle: 'front' })).toBe('brakes');
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

describe('atelierHref', () => {
  it('deep-links the Atelier hash route by the vehicle IRI segment', () => {
    expect(atelierHref('/garage/vehicles/01KW9RF0ZAFP', 'https://localhost')).toBe('https://localhost/#/v/01KW9RF0ZAFP');
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
});
