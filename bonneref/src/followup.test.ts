import { mapNarrowing } from './api';
import narrowed from '../test-fixtures/narrowed.json';
import { onScreenOf } from './followup';
import type { Narrowing } from './types';
import { selectVariant } from './variants';

const SEL = '01JA5Z3M8Q6W4XK7T2N9R0BCDE';

const twoClusters = (): Narrowing => {
  const base = mapNarrowing(narrowed);
  const one = base.clusters[0]!;
  return {
    ...base,
    clusters: [
      { ...one, id: 'c1', dimensions: [], applicability: [] },
      { ...one, id: 'c2', dimensions: [], representative: { artId: 9, brand: 'TRW', number: 'JAR999' }, oeNumbers: [], applicability: [] },
    ],
  };
};

describe('onScreenOf', () => {
  it('names the category and product group alone when nobody chose a variant', () => {
    const n = twoClusters();
    expect(onScreenOf(100198, 51, selectVariant(n, null))).toEqual({ categoryId: 100198, productGroupId: 51 });
    expect(onScreenOf(100198, null, null)).toEqual({ categoryId: 100198, productGroupId: null });
  });

  it('says which variant was chosen by the answer or by the user', () => {
    const n = twoClusters();
    expect(onScreenOf(100198, 51, selectVariant(n, { id: 'c2', by: 'answer' }))).toEqual({ categoryId: 100198, productGroupId: 51, clusterId: 'c2', chosenBy: 'answer' });
    expect(onScreenOf(100198, 51, selectVariant(n, { id: 'c2', by: 'user' }))).toMatchObject({ clusterId: 'c2', chosenBy: 'user' });
  });

  it('sends the server-stored selection id for an agent choice', () => {
    const n = twoClusters();
    const verdict = { id: SEL, clusterId: 'c1', basis: 'code de fabrication', setAside: null };
    expect(onScreenOf(100198, 51, selectVariant(n, { id: 'c1', by: 'agent', selectionId: SEL }, verdict))).toEqual({
      categoryId: 100198,
      productGroupId: 51,
      clusterId: 'c1',
      chosenBy: 'agent',
      selectionId: SEL,
    });
  });

  it('never claims an agent choice the server did not vouch for', () => {
    const n = twoClusters();
    expect(onScreenOf(100198, 51, selectVariant(n, { id: 'c1', by: 'agent', selectionId: SEL }, null))).toEqual({ categoryId: 100198, productGroupId: 51 });
  });

  it('does not call a lone variant a choice', () => {
    const n = { ...twoClusters(), clusters: [twoClusters().clusters[0]!] };
    expect(onScreenOf(100198, 51, selectVariant(n, null))).toEqual({ categoryId: 100198, productGroupId: 51 });
  });
});
