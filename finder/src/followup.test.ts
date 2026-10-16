import { mapNarrowing } from './api';
import narrowed from '../test-fixtures/narrowed.json';
import { resultSummary } from './followup';
import type { Narrowing } from './types';

const twoClusters = (): Narrowing => {
  const base = mapNarrowing(narrowed);
  const one = base.clusters[0]!;
  return {
    ...base,
    categoryId: 100198,
    clusters: [
      { ...one, id: 'k1', dimensions: [], applicability: [{ name: 'N° d’organisation jusqu’à', values: ['10500'] }] },
      { ...one, id: 'k2', dimensions: [], representative: { artId: 9, brand: 'TRW', number: 'JAR999' }, oeNumbers: [], applicability: [] },
    ],
  };
};

describe('resultSummary', () => {
  it('names the category, its id and every cluster with its reference and per-article applicability', () => {
    const text = resultSummary('Rotule axiale', twoClusters(), null);
    expect(text).toContain('Rotule axiale (categoryId 100198)');
    expect(text).toContain('2 pièces distinctes');
    expect(text).toContain('[k1]');
    expect(text).toContain('[k2] TRW JAR999');
    expect(text).toContain('N° d’organisation jusqu’à 10500');
    expect(text).toContain('par article, pas par véhicule');
  });

  it('says which cluster the agent retained, and on what basis', () => {
    expect(resultSummary('Rotule axiale', twoClusters(), { id: 'k1', by: 'agent', basis: 'code de fabrication (OPR 09824 ≤ 10310)' })).toContain(
      'retenue d’après code de fabrication (OPR 09824 ≤ 10310), indication (applicabilité par article) : [k1]',
    );
  });

  it('says which cluster was chosen, and how', () => {
    expect(resultSummary('Rotule axiale', twoClusters(), { id: 'k2', by: 'answer' })).toContain('choisie par la réponse à la question : [k2]');
    expect(resultSummary('Rotule axiale', twoClusters(), { id: 'k2', by: 'user' })).toContain('choisie par l’utilisateur, non confirmée par le catalogue : [k2]');
  });

  it('prints only catalogue cotes, never an estimate', () => {
    const text = resultSummary('Filtre à huile', mapNarrowing(narrowed), null);
    expect(text).not.toContain('≈');
    expect(text).toMatch(/Ø/);
  });

  it('stays under the server cap', () => {
    const n = twoClusters();
    const many = { ...n, clusters: Array.from({ length: 40 }, (_, i) => ({ ...n.clusters[0]!, id: `k${i}` })) };
    expect(resultSummary('Rotule axiale', many, null).length).toBeLessThanOrEqual(1900);
  });
});
