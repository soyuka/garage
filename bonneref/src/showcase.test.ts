import { SHOWCASE } from './showcase';

describe('home showcase example', () => {
  it('reads the cached Mazda oil filter answer: 220 references down to one part', () => {
    expect(SHOWCASE.label).toBe('Filtre à huile');
    expect(SHOWCASE.total).toBe(220);
    expect(SHOWCASE.oe).toEqual({ brand: 'MAZDA', number: expect.stringMatching(/^LF/) });
    expect(SHOWCASE.equivalents[0]).toMatchObject({ brand: 'MANN-FILTER', number: 'W 712/73' });
    expect(SHOWCASE.family.kind).toBe('filter');
  });

  it('prints only catalogue cotes', () => {
    expect(SHOWCASE.cotes).toMatch(/^Ø/);
    expect(SHOWCASE.dimensions.length).toBeGreaterThan(0);
  });

  it('names the vehicle without a plate or a VIN', () => {
    expect(SHOWCASE.vehicle).toBe('Mazda 6 2.0 MZR147');
    const text = JSON.stringify(SHOWCASE);
    expect(text).not.toMatch(/FJ-?109-?XH|JMZGG/);
  });
});
