import { enterIntent, foldText, searchCategories } from './search';
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

describe('enterIntent', () => {
  const oil = { id: 1, name: 'Filtre à huile', path: 'Filtres > Filtre à huile', segments: ['Filtres', 'Filtre à huile'], label: 'Filtre à huile', leaf: true };
  const air = { id: 2, name: 'Filtre à air', path: 'Filtres > Filtre à air', segments: ['Filtres', 'Filtre à air'], label: 'Filtre à air', leaf: true };

  it('picks the category whose label is exactly the typed text', () => {
    expect(enterIntent('filtre a HUILE', [air, oil], 0, false)).toEqual({ kind: 'pick', category: oil });
  });

  it('picks the highlighted suggestion once the user moved through the list', () => {
    expect(enterIntent('filtre', [oil, air], 1, true)).toEqual({ kind: 'pick', category: air });
  });

  it('asks the agent for free text that names no category exactly', () => {
    expect(enterIntent('mes freins grincent à l’avant', [], 0, false)).toEqual({ kind: 'ask', text: 'mes freins grincent à l’avant' });
    expect(enterIntent('filtre', [oil, air], 0, false)).toEqual({ kind: 'ask', text: 'filtre' });
  });

  it('does nothing on blank input', () => {
    expect(enterIntent('   ', [oil], 0, false)).toEqual({ kind: 'none' });
  });
});
