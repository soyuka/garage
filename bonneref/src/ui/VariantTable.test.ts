import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import type { Cluster, Narrowing } from '../types';
import type { Selection } from '../variants';
import { VariantTable } from './VariantTable';

const cluster = (id: string, number: string, applicability: Cluster['applicability'], conditions: Cluster['applicability']): Cluster => ({
  id,
  dimensions: [],
  oeNumbers: [],
  representative: { brand: 'SACHS', number, artId: 1 },
  variants: [{ brand: 'SACHS', number, artId: 1, tier: 1, applicability: conditions.length > 0 ? [{ conditions, pairedWith: [] }] : [] }],
  articleCount: 3,
  applicability,
});

const withNote = cluster('c1', '314 872', [{ name: 'Pour OPR', values: ['1234'] }], [{ name: 'Pour option', values: ['Sport'] }]);
const plain = cluster('c2', '315 000', [], []);

const narrowing = { clusters: [withNote, plain], differsOn: [], applicabilityScope: 'vehicle' } as unknown as Narrowing;
const selection: Selection = { cluster: withNote, index: 0, status: 'open', verdict: null };

const render = (n: Narrowing = narrowing) => renderToStaticMarkup(createElement(VariantTable, { narrowing: n, make: null, selection, onPick: () => {} }));

describe('VariantTable applicability disclosure', () => {
  it('drops the applicability grid column header', () => {
    expect(render()).not.toContain('Applicabilité (ce véhicule)');
  });

  it('renders a collapsed toggle counting breakpoints and notes', () => {
    const html = render();
    expect(html).toContain('Applicabilité · 2');
    expect(html).toContain('aria-expanded="false"');
  });

  it('renders the panel hidden, labelled by scope, wired to the toggle', () => {
    const html = render();
    expect(html).toMatch(/<div[^>]*class="vt-app"[^>]*hidden/);
    expect(html).toContain('Ce véhicule');
    expect(html).toMatch(/aria-controls="([^"]+)"[\s\S]*?id="\1"/);
  });

  it('labels the panel per article outside vehicle scope', () => {
    const html = render({ ...narrowing, applicabilityScope: 'article' });
    expect(html).toContain('Par article');
  });

  it('renders no toggle for a part without applicability', () => {
    const html = renderToStaticMarkup(createElement(VariantTable, { narrowing: { ...narrowing, clusters: [plain, plain] } as Narrowing, make: null, selection: { ...selection, cluster: plain }, onPick: () => {} }));
    expect(html).not.toContain('Applicabilité ·');
    expect(html).not.toContain('vt-app');
  });
});
