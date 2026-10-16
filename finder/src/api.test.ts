import { mapPhotoIntake } from './api';
import { ApiError, classifyStatus, mapCategories, mapNarrowing, mapRegistration, mapVehicle, mapVin } from './api';
import narrowed from '../test-fixtures/narrowed.json';
import vehicle from '../test-fixtures/vehicle.json';
import vin from '../test-fixtures/vin.json';

describe('mapVehicle', () => {
  it('reads the JSON-LD vehicle and formats the plate', () => {
    const v = mapVehicle(vehicle);
    expect(v.plate).toBe('XB-301-ZB');
    expect(v.carId).toBe(19107);
    expect(v.carType).toBe('PC');
    expect(v.make).toBe('MAZDA');
    expect(v.engineCode).toBe('LFF7');
  });

  it('treats omitted fields as null', () => {
    const v = mapVehicle({ plate: 'AB123CD' });
    expect(v.carId).toBeNull();
    expect(v.make).toBeNull();
    expect(v.carType).toBe('PC');
  });
});

describe('mapVin', () => {
  it('keeps the decode and the year candidates, modelYear null when omitted', () => {
    const d = mapVin(vin);
    expect(d.valid).toBe(true);
    expect(d.manufacturer).toBe('Mazda');
    expect(d.modelYear).toBeNull();
    expect(d.modelYearCandidates).toEqual([2007]);
  });
});

describe('mapCategories', () => {
  it('reads hydra:member and splits the French path', () => {
    const cats = mapCategories({
      'hydra:member': [{ id: 100259, name: 'Oil Filter', path: 'Filtre > Filtre à huile', leaf: true }],
    });
    expect(cats).toEqual([
      { id: 100259, name: 'Oil Filter', path: 'Filtre > Filtre à huile', segments: ['Filtre', 'Filtre à huile'], label: 'Filtre à huile', leaf: true },
    ]);
  });

  it('accepts the unprefixed member key too', () => {
    expect(mapCategories({ member: [{ id: 1, name: 'X', path: 'A > B', leaf: false }] })[0]?.label).toBe('B');
  });
});

describe('mapNarrowing', () => {
  const n = mapNarrowing(narrowed);

  it('maps the product group narrowed and the groups of a mixed category', () => {
    const mixed = mapNarrowing({
      groupRequired: true,
      productGroup: null,
      productGroups: [
        { id: 914, name: 'Rotule de barre de connexion', articleCount: 514 },
        { id: 51, name: 'Rotule de direction intérieure, barre de connexion', articleCount: 288 },
        { id: 'x', name: 'broken' },
      ],
    });
    expect(mixed.groupRequired).toBe(true);
    expect(mixed.productGroup).toBeNull();
    expect(mixed.productGroups).toEqual([
      { id: 914, name: 'Rotule de barre de connexion', articleCount: 514 },
      { id: 51, name: 'Rotule de direction intérieure, barre de connexion', articleCount: 288 },
    ]);
    expect(mapNarrowing({ productGroup: { id: 51, name: 'Rotule de direction intérieure, barre de connexion' } }).productGroup).toEqual({ id: 51, name: 'Rotule de direction intérieure, barre de connexion' });
    expect(n.groupRequired).toBe(false);
  });

  it('keeps the headline counts', () => {
    expect(n.totalArticles).toBe(220);
    expect(n.totalBrands).toBe(126);
    expect(n.unclassifiedCount).toBe(100);
    expect(n.narrowed).toBe(true);
    expect(n.degradedReason).toBeNull();
    expect(n.clusters).toHaveLength(1);
    expect(n.clusters[0]!.articleCount).toBe(120);
  });

  it('turns the fingerprint into dimensions with a clean label, a number and a unit', () => {
    const c1 = n.clusters[0]!;
    const height = c1.dimensions.find((d) => d.key === 'hauteur');
    expect(height).toEqual({ key: 'hauteur', label: 'Hauteur', value: '93', number: 93, unit: 'mm', min: 85, max: 94, info: false });
  });

  it('flags supplier-dependent figures as info and keeps single values as a degenerate range', () => {
    const c1 = n.clusters[0]!;
    expect(c1.dimensions.find((d) => d.key === 'pression_d_ouverture_clapet_de_derivation')?.info).toBe(true);
    expect(c1.dimensions.find((d) => d.key === 'diametre_3')?.info).toBe(true);
    expect(c1.dimensions.find((d) => d.key === 'thread')).toMatchObject({ min: null, max: null, info: false });
    expect(mapNarrowing({ clusters: [{ id: 'c1', representative: { brand: 'A', number: '1' }, fingerprint: { hauteur: { label: 'Hauteur [mm]', value: '93' } } }] }).clusters[0]!.dimensions[0]).toMatchObject({ min: 93, max: 93 });
  });

  it('recovers units from labels the catalogue truncated', () => {
    const c1 = n.clusters[0]!;
    expect(c1.dimensions.find((d) => d.key === 'diametre_interieur_de_la_bague_d_etancheite')).toMatchObject({
      label: 'Diamètre intérieur de la bague d’étanchéité',
      unit: 'mm',
    });
    expect(c1.dimensions.find((d) => d.key === 'pression_d_ouverture_clapet_de_derivation')).toMatchObject({
      label: 'Pression d’ouverture clapet de dérivation',
      unit: 'bar',
    });
    expect(c1.dimensions.find((d) => d.key === 'thread')).toMatchObject({ value: '3/4-16', number: null, unit: null });
  });

  it('keeps variants, OE numbers and the divergence', () => {
    const c1 = n.clusters[0]!;
    expect(c1.oeNumbers).toContainEqual({ brand: 'MAZDA', number: 'LF10-14-302' });
    expect(c1.variants[0]).toEqual({ artId: 9674372, brand: 'MANN-FILTER', number: 'W 712/73', tier: 1 });
    expect(c1.representative).toEqual({ artId: 9674372, brand: 'MANN-FILTER', number: 'W 712/73' });
    expect(n.differsOn).toEqual([]);
    expect(
      mapNarrowing({ differsOn: [{ key: 'hauteur', label: 'Hauteur [mm]', values: [{ clusterId: 'c1', value: '93', min: 93, max: 93, unit: 'mm' }, { clusterId: 'c2', value: '85' }] }] }).differsOn[0],
    ).toEqual({
      key: 'hauteur',
      label: 'Hauteur',
      unit: 'mm',
      values: [
        { clusterId: 'c1', value: '93' },
        { clusterId: 'c2', value: '85' },
      ],
    });
  });

  it('keeps per-cluster applicability verbatim, empty when absent', () => {
    const n = mapNarrowing({
      clusters: [
        {
          id: 'k1',
          fingerprint: {},
          oeNumbers: [],
          representative: { artId: 1, brand: 'A', number: '1' },
          variants: [],
          articleCount: 1,
          applicability: [
            { name: 'Numéro de châssis (VIN) à partir de', values: ['8A123456', ' 8A200000 '] },
            { name: '', values: ['x'] },
            { name: 'Année à partir de', values: 'junk' },
          ],
        },
      ],
    });
    expect(n.clusters[0]!.applicability).toEqual([{ name: 'Numéro de châssis (VIN) à partir de', values: ['8A123456', '8A200000'] }]);
    expect(mapNarrowing(narrowed).clusters.every((c) => Array.isArray(c.applicability))).toBe(true);
  });

  it('survives a degraded payload with omitted fields', () => {
    const d = mapNarrowing({ carId: 1, categoryId: 2, clusters: [], totalArticles: 9, totalBrands: 3, unclassifiedCount: 9, differsOn: [], narrowed: false, degradedReason: 'criteria unavailable from the parts catalog (quota or outage)', upstreamCalls: 1, fallbackBrands: [{ brand: 'BOSCH', tier: 1, articles: [{ id: 5, number: 'F 1' }] }] });
    expect(d.narrowed).toBe(false);
    expect(d.fallbackBrands).toEqual([{ brand: 'BOSCH', tier: 1, articles: [{ id: 5, number: 'F 1' }] }]);
  });
});

describe('mapRegistration', () => {
  it('lists the carte grise fields in order, never section C, and normalises the plate', () => {
    const r = mapRegistration({ A: 'xb301zb', B: '29/03/2007', 'C.1': 'DUPONT', 'D.1': 'MAZDA', 'D.2': null, 'D.3': 'MAZDA6', E: 'JMZGG14F671528417', K: null, 'P.1': '1999', 'P.2': '108', 'P.3': 'ES', 'P.6': '9', legible: true, warnings: [] });
    expect(r.plate).toBe('XB-301-ZB');
    expect(r.vin).toBe('JMZGG14F671528417');
    expect(r.fields.map((f) => f.code)).toEqual(['A', 'B', 'D.1', 'D.3', 'E', 'P.1', 'P.2', 'P.3', 'P.6']);
    expect(r.fields.find((f) => f.code === 'D.1')?.label).toBe('Marque');
    expect(r.legible).toBe(true);
  });
});

describe('classifyStatus', () => {
  it('names the failure from the HTTP status', () => {
    expect(classifyStatus(404)).toBe('notFound');
    expect(classifyStatus(401)).toBe('auth');
    expect(classifyStatus(429)).toBe('quota');
    expect(classifyStatus(502)).toBe('upstream');
    expect(classifyStatus(422)).toBe('invalid');
    expect(new ApiError('quota').message).toMatch(/quota/i);
  });
});

describe('mapPhotoIntake', () => {
  it('reads a carte grise through the registration mapper', () => {
    const r = mapPhotoIntake({ kind: 'carte_grise', registration: { A: 'XB-301-ZB', 'D.1': 'MAZDA', legible: true, warnings: [] }, part: null });
    expect(r).toMatchObject({ kind: 'carte_grise', registration: { plate: 'XB-301-ZB', legible: true } });
  });

  it('reads a part with its printed references', () => {
    expect(mapPhotoIntake({ kind: 'part', registration: null, part: { familyGuess: 'filtre à huile', printedReferences: ['W 712/73', 3], brandGuess: null } })).toEqual({
      kind: 'part',
      part: { familyGuess: 'filtre à huile', printedReferences: ['W 712/73'], brandGuess: null },
    });
  });

  it('reads a build label with its printed codes verbatim', () => {
    expect(mapPhotoIntake({ kind: 'build_label', buildLabel: { opr: ' 10345 ', printedCodes: ['DAM 10345', '', 3, 'EWP'] } })).toEqual({
      kind: 'build_label',
      buildLabel: { opr: '10345', printedCodes: ['DAM 10345', 'EWP'] },
    });
    expect(mapPhotoIntake({ kind: 'build_label', buildLabel: null })).toEqual({ kind: 'other' });
  });

  it('falls back to other on anything unexpected', () => {
    expect(mapPhotoIntake({ kind: 'carte_grise' })).toEqual({ kind: 'other' });
    expect(mapPhotoIntake(null)).toEqual({ kind: 'other' });
  });
});
