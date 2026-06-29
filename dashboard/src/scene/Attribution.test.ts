import { describe, it, expect } from 'vitest';
import { CREDITS, creditUrl } from './Attribution';
import { safeHref } from '../safeHref';

// CC-BY requires shipping a credit per source mesh. These invariants guard the data, not the rendering.

describe('CREDITS — model attribution data', () => {
  it('credits all five blueprint sources', () => {
    expect(CREDITS).toHaveLength(5);
    expect(CREDITS.map((c) => c.title)).toEqual(
      expect.arrayContaining(['2008 Renault Espace', 'Mazda 6 Sedan 2011', 'Motorcycle', 'European Delivery Van', 'Lawn Tractor']),
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
});
