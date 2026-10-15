import { HOME, STARTERS } from './home';
import { routeText } from './route-input';

describe('home showcase copy', () => {
  it('keeps the headline to one mobile line', () => {
    expect(HOME.headline.length).toBeLessThanOrEqual(40);
  });

  it('shows no plate anywhere', () => {
    const strings = [HOME.headline, HOME.lede, HOME.placeholder, ...STARTERS.map((s) => s.label)];
    for (const s of strings) expect(routeText(s).kind).not.toBe('plate');
    for (const s of strings) expect(s).not.toMatch(/\b[A-Z]{2}-\d{3}-[A-Z]{2}\b/);
  });

  it('starts every text chip as a free search in one click, without a vehicle', () => {
    const texts = STARTERS.filter((s) => s.kind === 'text');
    expect(texts.length).toBeGreaterThan(0);
    for (const s of texts) expect(routeText(s.label)).toEqual({ kind: 'text', text: s.label });
  });

  it('offers one photo chip, for the carte grise', () => {
    expect(STARTERS.filter((s) => s.kind === 'photo').map((s) => s.label)).toEqual(['Photo de la carte grise']);
  });
});
