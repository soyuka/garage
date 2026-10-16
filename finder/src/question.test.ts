import { askChoices, buildQuestion } from './question';
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
    differsOn: [{ key: 'hauteur', label: 'Hauteur', unit: 'mm', values: [{ clusterId: 'c1', value: '93', min: null, max: null }, { clusterId: 'c2', value: '85', min: null, max: null }] }],
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
        differsOn: [{ key: 'diametre', label: 'Diamètre', unit: 'mm', values: [{ clusterId: 'c1', value: '300', min: null, max: null }, { clusterId: 'c2', value: '278', min: null, max: null }] }],
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
        differsOn: [{ key: 'epaisseur', label: 'Épaisseur', unit: 'mm', values: [{ clusterId: 'c1', value: '22', min: null, max: null }, { clusterId: 'c2', value: '25', min: null, max: null }, { clusterId: 'c3', value: '28', min: null, max: null }] }],
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
        differsOn: [{ key: 'hauteur', label: 'Hauteur', unit: 'mm', values: [{ clusterId: 'c1', value: '93', min: null, max: null }, { clusterId: 'c2', value: '85', min: null, max: null }, { clusterId: 'c3', value: '85', min: null, max: null }] }],
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

describe('askChoices', () => {
  const options = [
    { label: 'Hatchback 2.0 141 ch · 2002–2007', value: '19107' },
    { label: 'Hatchback 2.0 DI 136 ch · 2002–2007', value: '19108' },
  ];

  it('appends the plate option after the agent options, as a distinct kind', () => {
    const choices = askChoices(options);
    expect(choices.slice(0, 2)).toEqual([
      { kind: 'agent', label: options[0]!.label, value: '19107' },
      { kind: 'agent', label: options[1]!.label, value: '19108' },
    ]);
    expect(choices.at(-1)).toEqual({ kind: 'plate', label: 'Donner la plaque' });
  });

  it('keeps the plate option outside the six the agent may offer', () => {
    const six = Array.from({ length: 6 }, (_, i) => ({ label: `v${i}`, value: String(i) }));
    const choices = askChoices(six);
    expect(choices).toHaveLength(7);
    expect(choices.filter((c) => c.kind === 'plate')).toHaveLength(1);
  });

  it('offers the plate even when the agent gave no option', () => {
    expect(askChoices([])).toEqual([{ kind: 'plate', label: 'Donner la plaque' }]);
  });
});

describe('buildQuestion on the 307 axial joints', () => {
  it('prints each answer as the range its part spans, never a picked value', async () => {
    const axial = (await import('../test-fixtures/narrowed-307-axial.json')).default;
    const q = buildQuestion(mapNarrowing(axial), 'Rotule de direction intérieure');
    expect(q?.answers.map((a) => a.label)).toEqual([`322–338${NNBSP}mm`, `358${NNBSP}mm`]);
    expect(q?.text).toContain(`322–338 ou 358${NNBSP}mm`);
  });
});
