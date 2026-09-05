import { describe, it, expect } from 'vitest';
import { CREDITS, creditUrl } from './Attribution';
import { safeHref } from '../safeHref';

// CC-BY requires shipping a credit per source mesh. These invariants guard the data, not the rendering.

describe('CREDITS — model attribution data', () => {
  it('credits all seven blueprint + part sources', () => {
    expect(CREDITS).toHaveLength(7);
    expect(CREDITS.map((c) => c.title)).toEqual(
      expect.arrayContaining([
        '2008 Renault Espace',
        'Mazda 6 Sedan 2011',
        'Motorcycle',
        'European Delivery Van',
        'Lawn Tractor',
        'Oil Filter',
        'Old Engine',
      ]),
    );
  });
  it('every entry has a non-empty title + author', () => {
    for (const c of CREDITS) {
      expect(c.title.trim().length).toBeGreaterThan(0);
      expect(c.author.trim().length).toBeGreaterThan(0);
    }
  });
  it('every credit links to a safe https sketchfab url', () => {
    for (const c of CREDITS) {
      const url = creditUrl(c);
      expect(safeHref(url), `${c.title} url not safe`).toBe(url);
      expect(url.startsWith('https://sketchfab.com/')).toBe(true);
    }
  });
  it('uses the model page when a full UID is known, a title search otherwise', () => {
    const espace = CREDITS.find((c) => c.title === '2008 Renault Espace')!;
    expect(creditUrl(espace)).toBe('https://sketchfab.com/3d-models/b8e7c65711134fca865f635ccddafcf5');
    const moto = CREDITS.find((c) => c.title === 'Motorcycle')!;
    expect(creditUrl(moto)).toContain('/search?');
  });
  it('links the part-mesh model pages', () => {
    const oilFilter = CREDITS.find((c) => c.title === 'Oil Filter')!;
    expect(creditUrl(oilFilter)).toBe('https://sketchfab.com/3d-models/0d26735d9cc04fd896fd8c20482bd764');
    const oldEngine = CREDITS.find((c) => c.title === 'Old Engine')!;
    expect(creditUrl(oldEngine)).toBe('https://sketchfab.com/3d-models/c9130ed48cee420186e06b83c4c880af');
  });
  it('carries the one non-CC-BY licence explicitly', () => {
    const oilFilter = CREDITS.find((c) => c.title === 'Oil Filter')!;
    expect(oilFilter.licence).toBe('CC-BY-NC');
    for (const c of CREDITS) {
      if (c.title === 'Oil Filter') continue;
      expect(c.licence, c.title).toBeUndefined();
    }
  });
});
