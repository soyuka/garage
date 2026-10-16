import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { mapNarrowing } from './api';
import { NNBSP } from './format';
import axial from '../test-fixtures/narrowed-307-axial.json';
import mazda from '../test-fixtures/narrowed.json';
import { applicabilityLines, makerOe, otherDimensions, selectVariant, variantRows } from './variants';
import { VariantTable } from './ui/VariantTable';

const n307 = () => mapNarrowing(axial);

describe('selectVariant', () => {
  it('shows the only part as settled', () => {
    const s = selectVariant(mapNarrowing(mazda), null);
    expect(s.status).toBe('single');
    expect(s.cluster?.id).toBe('c1');
  });

  it('shows the first part, still open, when nothing is chosen', () => {
    const s = selectVariant(n307(), null);
    expect(s).toMatchObject({ status: 'open', index: 0 });
    expect(s.cluster?.id).toBe('c1');
  });

  it('drives the sheet with the chosen part and says who chose it', () => {
    expect(selectVariant(n307(), { id: 'c2', by: 'user' })).toMatchObject({ status: 'user', index: 1, cluster: { id: 'c2' } });
    expect(selectVariant(n307(), { id: 'c2', by: 'answer' })).toMatchObject({ status: 'answer', index: 1, cluster: { id: 'c2' } });
  });

  it('falls back to an open first part when the chosen id is unknown', () => {
    expect(selectVariant(n307(), { id: 'c9', by: 'user' })).toMatchObject({ status: 'open', index: 0, cluster: { id: 'c1' } });
  });

  it('has no part without a narrowing', () => {
    expect(selectVariant(null, null)).toEqual({ cluster: null, index: -1, status: 'open' });
  });
});

describe('variantRows', () => {
  it('lists every part with its letter, OE, representative and the cote that tells them apart', () => {
    const rows = variantRows(n307(), 'PEUGEOT');
    expect(rows.map((r) => r.letter)).toEqual(['A', 'B']);
    expect(rows[0]).toMatchObject({ id: 'c1', oe: { brand: 'PEUGEOT', number: '3812 E0' }, representative: { brand: 'BOSCH', number: '0 986 141 079' }, articleCount: 90 });
    expect(rows[1]).toMatchObject({ id: 'c2', oe: { brand: 'PEUGEOT', number: '3812 E5' }, representative: { brand: 'BOSCH', number: '0 986 141 133' }, articleCount: 11 });
    expect(rows[0]!.differs).toEqual([{ key: 'longeur_rotule', label: 'Longeur rotule', text: `322–338${NNBSP}mm` }]);
    expect(rows[1]!.differs).toEqual([{ key: 'longeur_rotule', label: 'Longeur rotule', text: `358${NNBSP}mm` }]);
  });

  it('keeps the OPR breakpoints of each part, not the year soup', () => {
    const [a, b] = variantRows(n307(), 'PEUGEOT');
    expect(a!.breakpoints).toEqual([{ label: 'Numéro OPR jusqu’à', values: ['10310', '10311'] }]);
    expect(b!.breakpoints).toEqual([{ label: 'N° OPR (date de fabrication du véhicule)', values: ['10311'] }]);
  });
});

describe('applicabilityLines', () => {
  it('drops the leading "pour", dates years as month/year, sorts and dedups', () => {
    expect(applicabilityLines([{ name: 'Année à partir de', values: ['200506', '200402', '200402'] }, { name: 'pour numéro OPR jusqu’à', values: ['10311', '10310'] }])).toEqual([
      { label: 'Année à partir de', values: ['02/2004', '06/2005'] },
      { label: 'Numéro OPR jusqu’à', values: ['10310', '10311'] },
    ]);
  });
});

describe('otherDimensions', () => {
  it('lists the catalogue cotes the cotes line leaves out, flagging the one that tells the parts apart', () => {
    const n = n307();
    const b = otherDimensions(n.clusters[1]!, n.differsOn);
    expect(b).toEqual([
      { key: 'cote_d_assemblage', label: 'Côté d’assemblage', text: 'Essieu avant', differs: false },
      { key: 'largeur', label: 'Largeur', text: `36${NNBSP}mm`, differs: false },
      { key: 'longeur_rotule', label: 'Longeur rotule', text: `358${NNBSP}mm`, differs: true },
    ]);
  });

  it('skips what the cotes line already prints, and the packaging', () => {
    const n = mapNarrowing(mazda);
    const keys = otherDimensions(n.clusters[0]!, n.differsOn).map((d) => d.key);
    expect(keys).not.toContain('diametre_exterieur');
    expect(keys).not.toContain('hauteur');
    expect(keys).not.toContain('thread');
    expect(keys.some((k) => k.includes('emballage'))).toBe(false);
  });
});

describe('makerOe', () => {
  it('lists the carmaker’s other OE numbers once each, without the headline', () => {
    const n = n307();
    expect(makerOe(n.clusters[0]!, 'PEUGEOT', n.clusters)).toEqual([
      { brand: 'PEUGEOT', number: '16 072 936 80' },
      { brand: 'PEUGEOT', number: '3812' },
    ]);
    expect(makerOe(n.clusters[1]!, 'PEUGEOT', n.clusters)).toEqual([]);
  });
});

describe('VariantTable', () => {
  const render = (chosen: string | null, by: 'user' | 'answer' | null) => {
    const n = n307();
    const selection = selectVariant(n, chosen && by ? { id: chosen, by } : null);
    return renderToStaticMarkup(createElement(VariantTable, { narrowing: n, make: 'PEUGEOT', selection, onPick: () => {} }));
  };

  it('offers each part as a radio, the shown one checked', () => {
    const html = render(null, null);
    expect(html).toContain('role="radiogroup"');
    expect((html.match(/role="radio"/g) ?? []).length).toBe(2);
    expect(html).toMatch(/aria-checked="true"[^>]*><span class="vt-letter m"><span class="sr-only">Variante <\/span>A</);
    expect(html).toContain('3812 E5');
    expect(html).toContain('358');
    expect(html).toContain('10311');
  });

  it('marks a part picked by the user as theirs, never as confirmed', () => {
    const html = render('c2', 'user');
    expect(html).toContain('choisie par vous');
    expect(html).not.toContain('confirmé');
  });
});
