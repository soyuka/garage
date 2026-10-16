import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import type { WebPart } from '../types';
import { WebTitleBlock } from './Agent';
import { WebActions } from './ResultActions';

const part: WebPart = {
  kind: 'web',
  key: 'w1',
  label: 'Durite de turbo',
  reference: '1434.C5',
  brand: 'PEUGEOT',
  sourceUrl: 'https://www.example.org/durite',
  sourceTitle: 'Durite turbo 307',
  rationale: 'Référence citée par le constructeur.',
};

describe('web find actions', () => {
  it('leaves no action row in the web title block, only a copy icon on the reference', () => {
    const html = renderToStaticMarkup(createElement(WebTitleBlock, { vehicle: null, part }));
    expect(html).not.toContain('class="act"');
    expect(html).not.toContain('Copier la réf.');
    expect(html).not.toContain('Acheter');
    expect(html).toContain('aria-label="Copier 1434.C5"');
  });

  it('puts Acheter in the dock action row, acting on the web reference', () => {
    const html = renderToStaticMarkup(createElement(WebActions, { part }));
    expect(html).toContain('class="act"');
    expect(html).toContain('aria-label="Actions sur la pièce"');
    expect(html).toContain('Acheter');
    expect(html).not.toContain('Copier la réf.');
  });
});
