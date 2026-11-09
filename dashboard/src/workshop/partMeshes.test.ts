import { describe, expect, it } from 'vitest';
import { meshesForService, meshForPart, PART_FILES, partUrl } from './partMeshes';

describe('part meshes', () => {
  it('shows a clutch job as disc, pressure plate and flywheel', () => {
    expect(meshesForService('clutch')).toEqual(['clutch-disc', 'clutch-pressure-plate', 'flywheel']);
  });

  it('maps the common service types onto their part', () => {
    expect(meshesForService('timing_belt')).toEqual(['timing-belt']);
    expect(meshesForService('oil_change')).toEqual(['oil-filter']);
    expect(meshesForService('air_filter')).toEqual(['air-filter']);
    expect(meshesForService('brake_pads')).toEqual(['front-brake-pad']);
    expect(meshesForService('technical_inspection')).toEqual([]);
  });

  it('reads a part row by its own words', () => {
    expect(meshForPart({ ref: '826317', label: 'Kit embrayage' })).toBe('clutch-disc');
    expect(meshForPart({ ref: '415 0180 10', label: 'Volant bi-masse' })).toBe('flywheel');
    expect(meshForPart({ ref: 'W 712/75', brand: 'MANN', label: 'Filtre à huile' })).toBe('oil-filter');
  });

  it('maps the labels seen on the real fleet', () => {
    expect(meshForPart({ ref: '623 3150 33', brand: 'LuK', label: "Kit d'embrayage" })).toBe('clutch-disc');
    expect(meshForPart({ ref: '415 0463 10', brand: 'LuK', label: 'Volant moteur' })).toBe('flywheel');
    expect(meshForPart({ ref: '12017115B', brand: 'CORTECO', label: "Bague d'étanchéité, différentiel" })).toBe('shaft-seal');
    expect(meshForPart({ ref: '19036867B', brand: 'CORTECO', label: "Bague d'étanchéité, boîte de vitesse manuelle" })).toBe('shaft-seal');
    expect(meshForPart({ ref: 'SAE 75W-80', label: 'Huile boîte PK6 — 2,4 ± 0,15 L (MR 361 21A-2), en stock' })).toBe('jerrycan-5l');
    expect(meshForPart({ ref: 'x', label: 'foam element' })).toBe('air-filter');
    expect(meshForPart({ ref: 'x', label: 'rotule de direction intérieure' })).toBe('axial-joint');
    expect(meshForPart({ ref: 'DOT 4', label: 'Liquide de frein — purge embrayage (MR 361 21A-12), en stock' })).toBe('jerrycan-5l');
  });

  it('shows no mesh rather than a wrong one', () => {
    expect(meshForPart({ ref: 'x', label: 'front tires' })).toBeNull();
    expect(meshForPart({ ref: 'X1' })).toBeNull();
    expect(meshForPart({ ref: 'ABC123', label: 'Visserie diverse' })).toBeNull();
  });

  it('reads the specific phrase before the generic word', () => {
    expect(meshForPart({ ref: 'x', label: 'Plaquettes de frein arrière' })).toBe('rear-brake-pad');
    expect(meshForPart({ ref: 'x', label: 'Courroie de distribution' })).toBe('timing-belt');
    expect(meshForPart({ ref: 'x', label: 'Joint de culasse' })).toBe('gasket-head');
    expect(meshForPart({ ref: 'x', label: 'Ampoule H7' })).toBe('bulb-h7');
    expect(meshForPart({ ref: 'x', label: 'Rotule de direction extérieure' })).toBe('outer-tie-rod-end');
  });

  it('only names files that ship', () => {
    for (const file of [...meshesForService('clutch'), 'oil-filter', 'timing-belt', 'air-filter', 'front-brake-pad']) expect(PART_FILES).toContain(file);
    expect(partUrl('clutch-disc')).toBe('/parts/clutch-disc.glb');
  });

  describe('with the visit item type', () => {
    it('does not read the billing note in parentheses', () => {
      const part = { ref: 'x', brand: 'Hella', label: "Sonde température liquide de refroidissement (facturée « température d'huile »)" };
      expect(meshForPart(part, 'temperature_sensor')).toBe('coolant-temperature-sensor');
      expect(meshForPart(part)).toBe('coolant-temperature-sensor');
    });

    it('falls back on the item type for a part with brand and ref only', () => {
      expect(meshForPart({ ref: 'J1323055', brand: 'HERTH+BUSS JAKOPARTS'}, 'air_filter')).toBe('air-filter');
      expect(meshForPart({ ref: 'x', brand: 'Y' }, 'technical_inspection')).toBeNull();
    });

    it('reads an SAE viscosity as a can of oil', () => {
      expect(meshForPart({ ref: 'x', brand: 'MANNOL', label: 'Energy 5W-30 5L' }, 'oil_change')).toBe('jerrycan-5l');
    });

    it('draws no mesh for types that have none', () => {
      expect(meshForPart({ ref: 'x', brand: 'MANN' }, 'cabin_filter')).toBeNull();
      expect(meshForPart({ ref: 'x', brand: 'GATES' }, 'accessory_belt')).toBeNull();
    });

    it('lets the label win over the type', () => {
      expect(meshForPart({ ref: 'x', label: 'Filtre à huile' }, 'oil_change')).toBe('oil-filter');
    });
  });
});
