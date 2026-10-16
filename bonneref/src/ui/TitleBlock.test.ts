import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { mapNarrowing } from '../api';
import axial from '../../test-fixtures/narrowed-307-axial.json';
import mazda from '../../test-fixtures/narrowed.json';
import type { Category, Narrowing, Vehicle } from '../types';
import { selectVariant } from '../variants';
import { TitleBlock } from './TitleBlock';

const vehicle: Vehicle = { plate: 'XD-303-ZD', make: 'PEUGEOT', model: '307', modelEn: null, version: null, engineCode: null, powerHp: null, firstRegistration: null, energy: null, vin: null, carId: 16182, carType: 'PC' };
const category: Category = { id: 100198, name: 'Rotules', path: 'Direction > Rotules', segments: ['Direction', 'Rotules'], label: 'Rotule de direction intérieure', leaf: true };
const SEL = '01JA5Z3M8Q6W4XK7T2N9R0BCDE';
const FOOTNOTE = 'Applicabilité du catalogue, à vérifier sur la pièce démontée.';

const render = (narrowing: Narrowing, selection = selectVariant(narrowing, null), rationale?: string) =>
  renderToStaticMarkup(createElement(TitleBlock, { vehicle, category, narrowing, selection, onPick: () => {}, rationale }));

describe('TitleBlock', () => {
  it('has no « Pourquoi cette pièce » section and no internal counts', () => {
    const n = mapNarrowing(axial);
    const retained = selectVariant(n, { id: 'c1', by: 'agent', selectionId: SEL }, { id: SEL, clusterId: 'c1', basis: 'code de fabrication (OPR 09824 ≤ 10310)', setAside: 'OPR ≥ 10311' });
    for (const html of [render(n), render(n, retained), render(mapNarrowing(mazda), undefined, 'Le filtre se change à chaque vidange.')]) {
      expect(html).not.toContain('Pourquoi cette pièce');
      expect(html).not.toContain('Lire la suite');
      expect(html).not.toMatch(/réf\. de \d+ marques?/);
      expect(html).not.toContain('non rattachées');
    }
  });

  it('prints no catalogue footnote under the variant table of a multi-part result', () => {
    const n = mapNarrowing(axial);
    const retained = selectVariant(n, { id: 'c1', by: 'agent', selectionId: SEL }, { id: SEL, clusterId: 'c1', basis: 'code de fabrication (OPR 09824 ≤ 10310)', setAside: null });
    for (const html of [render(n), render(n, retained, 'Rotule axiale.')]) {
      expect(html).not.toContain(FOOTNOTE);
      expect(html).not.toContain('Rotule axiale.');
    }
    expect(render(n, retained)).toContain('d’après code de fabrication (OPR 09824 ≤ 10310)');
  });

  it('turns the agent’s rationale into the footnote of a single-part result, and prints nothing without one', () => {
    const n = mapNarrowing(mazda);
    const withWhy = render(n, undefined, 'Le filtre se change à chaque vidange.');
    expect(withWhy).toContain('<p class="tb-note">Le filtre se change à chaque vidange.</p>');
    expect(withWhy).not.toContain(FOOTNOTE);
    const plain = render(n);
    expect(plain).not.toContain('tb-note');
    expect(plain).not.toContain(FOOTNOTE);
  });

  it('still says when the catalogue answer is partial', () => {
    const n = { ...mapNarrowing(mazda), narrowed: false, degradedReason: 'criteria unavailable' };
    expect(render(n)).toContain('Cotes non chargées : affinage incomplet.');
  });

  it('labels the reference without a variant letter', () => {
    const n = mapNarrowing(axial);
    const retained = selectVariant(n, { id: 'c1', by: 'agent', selectionId: SEL }, { id: SEL, clusterId: 'c1', basis: 'code', setAside: null });
    for (const html of [render(n), render(n, retained), render(n, selectVariant(n, { id: 'c2', by: 'user' }))]) {
      expect(html).toContain('Réf. OE · PEUGEOT');
      expect(html).not.toMatch(/variante [A-Z]/i);
    }
  });
});
