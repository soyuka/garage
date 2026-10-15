import { mapNarrowing } from './api';
import narrowed from './fixtures/narrowed.json';
import { narrowingTrace } from './trace';

import { NNBSP } from './format';
const n = mapNarrowing(narrowed);
const c1 = n.clusters[0]!;

describe('narrowingTrace', () => {
  it('reads catalogue → brands → same part → unattached → one part', () => {
    const brands = new Set(c1.variants.map((v) => v.brand)).size;
    expect(narrowingTrace(n, c1, 1)).toEqual([
      { label: 'Références catalogue', value: String(n.totalArticles) },
      { label: 'Marques', value: String(n.totalBrands) },
      { label: 'Même pièce', value: `${c1.articleCount} réf.${NNBSP}·${NNBSP}${brands} marques` },
      { label: 'Non rattachées', value: String(n.unclassifiedCount) },
      { label: '→', value: '1 pièce', result: true },
    ]);
  });

  it('counts the distinct parts while unconfirmed and drops empty rows', () => {
    const rows = narrowingTrace({ ...n, unclassifiedCount: 0 }, null, 3);
    expect(rows.map((r) => r.label)).toEqual(['Références catalogue', 'Marques', '→']);
    expect(rows.at(-1)).toEqual({ label: '→', value: '3 pièces distinctes', result: true });
  });

  it('says nothing is identified when no part remains', () => {
    expect(narrowingTrace(n, null, 0).at(-1)).toEqual({ label: '→', value: 'aucune pièce identifiée', result: true });
  });
});
