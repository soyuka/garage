import { mapNarrowing } from './api';
import narrowed from '../test-fixtures/narrowed.json';
import { resultSummary } from './followup';
import type { Narrowing } from './types';
import { selectVariant } from './variants';

const SEL = '01JA5Z3M8Q6W4XK7T2N9R0BCDE';

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

  describe('vehicle scope', () => {
    const lowered = (): Narrowing => {
      const n = twoClusters();
      const [k1, k2] = n.clusters;
      return {
        ...n,
        applicabilityScope: 'vehicle',
        clusters: [
          {
            ...k1!,
            applicability: [{ name: 'Année jusqu’à', values: ['200602'] }],
            variants: [{ artId: 1, brand: 'LuK', number: '415 0463 10', tier: 1, applicability: [{ conditions: [], pairedWith: ['623 3150 ##'] }] }],
          },
          k2!,
        ],
      };
    };

    it('says the applicability holds on this car, and lists the notes per variant', () => {
      const text = resultSummary('Volant moteur', lowered(), null);
      expect(text).toContain('applicabilité sur ce véhicule : Année jusqu’à 200602');
      expect(text).toContain('par variante : LuK 415 0463 10 : avec 623 3150 ##');
      expect(text).not.toContain('par article, pas par véhicule');
    });

    it('words the agent’s basis as this car’s applicability', () => {
      const n = lowered();
      const verdict = { id: SEL, clusterId: 'k1', basis: 'code de fabrication', setAside: null };
      expect(resultSummary('Volant moteur', n, selectVariant(n, { id: 'k1', by: 'agent', selectionId: SEL }, verdict))).toContain('indication (applicabilité de ce véhicule) : [k1]');
    });
  });

  it('names the product group id of the result, so a follow-up never re-narrows without it', () => {
    const n = { ...twoClusters(), productGroup: { id: 51, name: 'Rotule de direction intérieure, barre de connexion' } };
    expect(resultSummary('Rotule de direction intérieure, barre de connexion', n, null)).toContain('(categoryId 100198, productGroupId 51)');
  });

  it('says which cluster the agent retained, and on what basis', () => {
    const n = twoClusters();
    const verdict = { id: SEL, clusterId: 'k1', basis: 'code de fabrication (OPR 09824 ≤ 10310)', setAside: null };
    expect(resultSummary('Rotule axiale', n, selectVariant(n, { id: 'k1', by: 'agent', selectionId: SEL }, verdict))).toContain(
      'retenue d’après code de fabrication (OPR 09824 ≤ 10310), indication (applicabilité par article) : [k1]',
    );
  });

  it('never feeds the agent a verdict the server did not vouch for', () => {
    const n = twoClusters();
    const text = resultSummary('Rotule axiale', n, selectVariant(n, { id: 'k1', by: 'agent', selectionId: SEL }, null));
    expect(text).not.toContain('retenue');
    expect(text).not.toContain(': [k1].');
  });

  it('says which cluster was chosen, and how', () => {
    const n = twoClusters();
    expect(resultSummary('Rotule axiale', n, selectVariant(n, { id: 'k2', by: 'answer' }))).toContain('choisie par la réponse à la question : [k2]');
    expect(resultSummary('Rotule axiale', n, selectVariant(n, { id: 'k2', by: 'user' }))).toContain('choisie par l’utilisateur, non confirmée par le catalogue : [k2]');
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
