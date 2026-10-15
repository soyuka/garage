import { buildQuestion } from './question';
import { mapNarrowing } from './api';
import narrowed from '../test-fixtures/narrowed.json';

const NNBSP = ' ';

describe('buildQuestion', () => {
  const merged = mapNarrowing(narrowed);
  const c1 = merged.clusters[0]!;
  const n = {
    ...merged,
    clusters: [
      c1,
      { ...c1, id: 'c2', articleCount: 5, representative: { artId: 6926893, brand: 'BOSCH', number: 'F 026 407 085' } },
    ],
    differsOn: [{ key: 'hauteur', label: 'Hauteur', unit: 'mm', values: [{ clusterId: 'c1', value: '93' }, { clusterId: 'c2', value: '85' }] }],
  };

  it('asks the one discriminating dimension, values ascending, unit factored', () => {
    const q = buildQuestion(n, 'Filtre à huile');
    expect(q?.text).toBe(`Hauteur du filtre : 85 ou 93${NNBSP}mm ?`);
    expect(q?.answers).toEqual([
      { label: `85${NNBSP}mm`, clusterId: 'c2' },
      { label: `93${NNBSP}mm`, clusterId: 'c1' },
    ]);
    expect(q?.hint).toBe('Mesurez de la base au sommet, joint compris.');
  });

  it('prefixes diameters with Ø and agrees the article with the noun', () => {
    const q = buildQuestion(
      {
        ...n,
        differsOn: [{ key: 'diametre', label: 'Diamètre', unit: 'mm', values: [{ clusterId: 'c1', value: '300' }, { clusterId: 'c2', value: '278' }] }],
      },
      'Plaquette de frein',
    );
    expect(q?.text).toBe(`Diamètre de la plaquette : Ø${NNBSP}278 ou Ø${NNBSP}300${NNBSP}mm ?`);
    expect(q?.answers[0]?.label).toBe(`Ø${NNBSP}278${NNBSP}mm`);
  });

  it('elides before a vowel and lists three values', () => {
    const q = buildQuestion(
      {
        ...n,
        clusters: [...n.clusters, { ...n.clusters[1]!, id: 'c3' }],
        differsOn: [{ key: 'epaisseur', label: 'Épaisseur', unit: 'mm', values: [{ clusterId: 'c1', value: '22' }, { clusterId: 'c2', value: '25' }, { clusterId: 'c3', value: '28' }] }],
      },
      'Étrier de frein',
    );
    expect(q?.text).toBe(`Épaisseur de l’étrier : 22, 25 ou 28${NNBSP}mm ?`);
  });

  it('merges clusters sharing a value onto the one with the most articles', () => {
    const q = buildQuestion(
      {
        ...n,
        clusters: [...n.clusters, { ...n.clusters[1]!, id: 'c3', articleCount: 50 }],
        differsOn: [{ key: 'hauteur', label: 'Hauteur', unit: 'mm', values: [{ clusterId: 'c1', value: '93' }, { clusterId: 'c2', value: '85' }, { clusterId: 'c3', value: '85' }] }],
      },
      'Filtre à huile',
    );
    expect(q?.answers).toEqual([
      { label: `85${NNBSP}mm`, clusterId: 'c3' },
      { label: `93${NNBSP}mm`, clusterId: 'c1' },
    ]);
  });

  it('falls back to the representatives when no dimension tells the parts apart', () => {
    const q = buildQuestion({ ...n, differsOn: [] }, 'Filtre à huile');
    expect(q?.text).toBe('2 références possibles, sans cote distinctive. Laquelle est montée ?');
    expect(q?.hint).toBe('Comparez avec la réf. gravée sur la pièce démontée.');
    expect(q?.answers.map((a) => a.label)).toEqual(['MANN-FILTER W 712/73', 'BOSCH F 026 407 085']);
  });

  it('asks nothing when a single part remains', () => {
    expect(buildQuestion({ ...n, clusters: n.clusters.slice(0, 1), differsOn: [] }, 'Filtre à huile')).toBeNull();
  });
});
