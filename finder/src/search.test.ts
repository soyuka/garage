import { foldText, searchCategories } from './search';
import { mapCategories } from './api';
import categories from './fixtures/categories.json';

const cats = mapCategories(categories);

describe('searchCategories', () => {
  it('folds accents, case and apostrophes', () => {
    expect(foldText('Étrier d’Huile')).toBe("etrier d'huile");
  });

  it('finds the oil filter typed without accents, label matches first', () => {
    const hits = searchCategories(cats, 'filtre a huile');
    expect(hits[0]?.label).toBe('Filtre à huile');
    expect(hits.every((c) => c.leaf)).toBe(true);
  });

  it('matches the English name too', () => {
    expect(searchCategories(cats, 'oil filter').map((c) => c.id)).toContain(100259);
  });

  it('requires every word, in any order', () => {
    const hits = searchCategories(cats, 'frein disque');
    expect(hits.map((c) => c.label)).toContain('Disque de frein');
    expect(hits.some((c) => c.label === 'Liquide de frein')).toBe(false);
  });

  it('returns nothing for blank or unknown queries', () => {
    expect(searchCategories(cats, '  ')).toEqual([]);
    expect(searchCategories(cats, 'zzzz')).toEqual([]);
  });

  it('caps the list', () => {
    expect(searchCategories(cats, 'e', 5)).toHaveLength(5);
  });
});
