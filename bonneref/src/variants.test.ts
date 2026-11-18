import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { mapNarrowing } from './api';
import { NNBSP } from './format';
import axial from '../test-fixtures/narrowed-307-axial.json';
import mazda from '../test-fixtures/narrowed.json';
import { applicabilityLines, choiceSelectionId, hiddenVariantIds, makerOe, orderedRows, othersLabel, otherDimensions, pickChoice, questionOpen, refineHint, selectVariant, storedVerdict, variantMark, variantNotes, variantRows, type Selection } from './variants';
import type { Cluster, Narrowing, Variant } from './types';
import { VariantTable } from './ui/VariantTable';

const n307 = () => mapNarrowing(axial);
const SEL = '01JA5Z3M8Q6W4XK7T2N9R0BCDE';

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
    const stored = { id: SEL, clusterId: 'c1', basis: 'code de fabrication', setAside: null };
    expect(selectVariant(n307(), { id: 'c1', by: 'agent', selectionId: SEL }, stored)).toMatchObject({ status: 'agent', index: 0, verdict: stored, cluster: { id: 'c1' } });
  });

  it('falls back to an open first part when the chosen id is unknown', () => {
    expect(selectVariant(n307(), { id: 'c9', by: 'user' })).toMatchObject({ status: 'open', index: 0, cluster: { id: 'c1' } });
  });

  it('has no part without a narrowing', () => {
    expect(selectVariant(null, null)).toEqual({ cluster: null, index: -1, status: 'open' });
  });
});

describe('agent verdict', () => {
  const retained = { id: 'c1', by: 'agent', selectionId: SEL } as const;
  const verdict = { id: SEL, clusterId: 'c1', basis: 'code de fabrication (OPR 09824 ≤ 10310)', setAside: 'OPR ≥ 10311' };
  const kept = { id: 'c2', by: 'user', retained: { id: 'c1', selectionId: SEL } } as const;

  it('keeps the agent’s verdict apart from the part shown', () => {
    expect(selectVariant(n307(), retained, verdict)).toMatchObject({ status: 'agent', index: 0, verdict });
    expect(selectVariant(n307(), kept, verdict)).toMatchObject({ status: 'user', index: 1, verdict });
    expect(selectVariant(n307(), { id: 'c2', by: 'user' }, verdict).verdict).toBeNull();
  });

  it('shows the neutral state while the server has not vouched for the selection, or when it says otherwise', () => {
    expect(selectVariant(n307(), retained, null)).toMatchObject({ status: 'open', index: 0, verdict: null });
    expect(selectVariant(n307(), retained, { ...verdict, id: '01JA5Z3M8Q6W4XK7T2N9R0BCDF' })).toMatchObject({ status: 'open', verdict: null });
    expect(selectVariant(n307(), { id: 'c2', by: 'agent', selectionId: SEL }, verdict)).toMatchObject({ status: 'open', index: 0, verdict: null });
    expect(selectVariant(n307(), kept, null)).toMatchObject({ status: 'user', index: 1, verdict: null });
  });

  it('drops a verdict naming a part that is not in the result', () => {
    expect(selectVariant(n307(), { id: 'c9', by: 'agent', selectionId: SEL }, { ...verdict, clusterId: 'c9' })).toMatchObject({ status: 'open', verdict: null });
    expect(selectVariant(n307(), { ...kept, retained: { id: 'c9', selectionId: SEL } }, { ...verdict, clusterId: 'c9' }).verdict).toBeNull();
  });

  it('marks the retained row and sets the others aside, whatever row is shown', () => {
    const s = selectVariant(n307(), retained, verdict);
    expect([variantMark(s, 'c1'), variantMark(s, 'c2')]).toEqual(['retained', 'aside']);
    const picked = selectVariant(n307(), kept, verdict);
    expect([variantMark(picked, 'c1'), variantMark(picked, 'c2')]).toEqual(['retained', 'aside']);
    const open = selectVariant(n307(), null);
    expect([variantMark(open, 'c1'), variantMark(open, 'c2')]).toEqual([null, null]);
  });

  it('a pick keeps the verdict, and picking the retained row goes back to it', () => {
    const s = selectVariant(n307(), retained, verdict);
    expect(pickChoice(s, 'c2')).toEqual(kept);
    expect(pickChoice(selectVariant(n307(), kept, verdict), 'c1')).toEqual(retained);
    expect(pickChoice(selectVariant(n307(), null), 'c2')).toEqual({ id: 'c2', by: 'user' });
  });

  it('asks the cote question only while nothing settled the part', () => {
    expect(questionOpen(selectVariant(n307(), null))).toBe(true);
    expect(questionOpen(selectVariant(n307(), { id: 'c2', by: 'user' }))).toBe(true);
    expect(questionOpen(selectVariant(n307(), { id: 'c2', by: 'answer' }))).toBe(true);
    expect(questionOpen(selectVariant(n307(), retained, verdict))).toBe(false);
    expect(questionOpen(selectVariant(n307(), kept, verdict))).toBe(false);
  });

  it('names the selection id a route depends on', () => {
    expect([choiceSelectionId(retained), choiceSelectionId(kept), choiceSelectionId({ id: 'c2', by: 'user' }), choiceSelectionId(null)]).toEqual([SEL, SEL, null, null]);
  });

  it('trusts a stored selection only for the car, category and product group on screen', () => {
    const stored = { ...verdict, carId: 16182, carType: 'PC' as const, categoryId: 100198, productGroupId: 51 };
    const here = { carId: 16182, categoryId: 100198, productGroupId: 51 };
    expect(storedVerdict(stored, here)).toEqual(verdict);
    expect(storedVerdict(stored, { ...here, carId: 19107 })).toBeNull();
    expect(storedVerdict(stored, { ...here, categoryId: 100259 })).toBeNull();
    expect(storedVerdict(stored, { ...here, productGroupId: 52 })).toBeNull();
    expect(storedVerdict(stored, { ...here, productGroupId: null })).toEqual(verdict);
  });
});

describe('variantRows', () => {
  it('lists every part with its OE, representative and the cote that tells them apart', () => {
    const rows = variantRows(n307(), 'PEUGEOT');
    expect(rows.every((r) => !('letter' in r))).toBe(true);
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

describe('vehicle scope', () => {
  const variant = (brand: string, number: string, applicability: Variant['applicability']): Variant => ({ artId: 1, brand, number, tier: 1, applicability });
  const espace = (): Narrowing => {
    const base = n307();
    const one = base.clusters[0]!;
    const cluster: Cluster = {
      ...one,
      applicability: [{ name: 'Année à partir de', values: ['200506'] }, { name: 'Numéro OPR jusqu’à', values: ['10310'] }],
      variants: [
        variant('LuK', '415 0463 10', [{ conditions: [], pairedWith: ['623 3150 ##'] }]),
        variant('Sachs', '2294 501 196', [{ conditions: [{ name: 'Année jusqu’à', values: ['200602'] }], pairedWith: ['3000 951 220', '3000 951 221'] }]),
        variant('Valeo', 'V1', [
          { conditions: [{ name: 'Année à partir de', values: ['200506'] }, { name: 'Boîte', values: ['manuelle'] }], pairedWith: [] },
          { conditions: [{ name: 'Année à partir de', values: ['200506'] }], pairedWith: [] },
        ]),
        variant('Exedy', 'E1', []),
      ],
    };
    return { ...base, applicabilityScope: 'vehicle', clusters: [cluster] };
  };

  it('lists the cluster’s shared conditions unfiltered, dated per car', () => {
    const [row] = variantRows(espace(), null);
    expect(row!.breakpoints).toEqual([
      { label: 'Année à partir de', values: ['06/2005'] },
      { label: 'Numéro OPR jusqu’à', values: ['10310'] },
    ]);
  });

  it('keeps article-scope breakpoints filtered', () => {
    const n = espace();
    const [row] = variantRows({ ...n, applicabilityScope: 'article' }, null);
    expect(row!.breakpoints).toEqual([{ label: 'Numéro OPR jusqu’à', values: ['10310'] }]);
    expect(row!.variantNotes).toEqual([]);
  });

  it('notes what only some variants hold, minus the shared conditions, with the paired articles', () => {
    const [row] = variantRows(espace(), null);
    expect(row!.variantNotes).toEqual([
      { ref: 'LuK 415 0463 10', text: 'avec 623 3150 ##' },
      { ref: 'Sachs 2294 501 196', text: 'Année jusqu’à 02/2006, avec 3000 951 220 / 3000 951 221' },
    ]);
  });

  it('joins alternatives with "ou" and skips a variant when any alternative adds nothing to the shared conditions', () => {
    const c = espace().clusters[0]!;
    expect(variantNotes({ ...c, variants: [c.variants[2]!] })).toEqual([]);
    expect(variantNotes({ ...c, variants: [variant('V', '1', [{ conditions: [{ name: 'Boîte', values: ['manuelle'] }], pairedWith: [] }])] })).toEqual([{ ref: 'V 1', text: 'Boîte manuelle' }]);
    const both: Cluster = {
      ...c,
      variants: [variant('A', '1', [{ conditions: [{ name: 'Boîte', values: ['manuelle'] }], pairedWith: [] }, { conditions: [], pairedWith: ['X 1'] }])],
    };
    expect(variantNotes(both)).toEqual([{ ref: 'A 1', text: 'Boîte manuelle ou avec X 1' }]);
    expect(variantNotes({ ...c, variants: [c.variants[3]!, variant('B', '2', [{ conditions: [{ name: 'Année à partir de', values: ['200506'] }], pairedWith: [] }])] })).toEqual([]);
  });

  it('renders the vehicle header and the notes in the table', () => {
    const n = espace();
    const html = renderToStaticMarkup(createElement(VariantTable, { narrowing: n, make: null, selection: selectVariant(n, null), onPick: () => {} }));
    expect(html).not.toContain('Applicabilité (ce véhicule)');
    expect(html).toContain('Ce véhicule');
    expect(html).toContain('avec 623 3150 ##');
    const article = renderToStaticMarkup(createElement(VariantTable, { narrowing: { ...n, applicabilityScope: 'article' }, make: null, selection: selectVariant(n, null), onPick: () => {} }));
    expect(article).toContain('Par article');
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
  const render = (chosen: string | null, by: 'user' | 'answer' | 'agent' | null) => {
    const n = n307();
    const selection =
      chosen && by === 'agent'
        ? selectVariant(n, { id: chosen, by, selectionId: SEL }, { id: SEL, clusterId: chosen, basis: 'code de fabrication (OPR 09824 ≤ 10310)', setAside: null })
        : selectVariant(n, chosen && (by === 'user' || by === 'answer') ? { id: chosen, by } : null);
    return renderToStaticMarkup(createElement(VariantTable, { narrowing: n, make: 'PEUGEOT', selection, onPick: () => {} }));
  };

  it('offers each part as a radio, the shown one checked', () => {
    const html = render(null, null);
    expect(html).toContain('role="radiogroup"');
    expect((html.match(/role="radio"/g) ?? []).length).toBe(2);
    expect(html).toMatch(/aria-checked="true"[^>]*><b class="m">3812 E0<\/b><\/button>/);
    expect(html).not.toContain('vt-letter');
    expect(html).not.toMatch(/Variante [A-Z]|Var\./);
    expect(html).toContain('3812 E5');
    expect(html).toContain('358');
    expect(html).toContain('10311');
  });

  it('puts a copy button for each OE beside its row, never inside the radio', () => {
    const html = render(null, null);
    expect(html).toContain('aria-label="Copier 3812 E0"');
    expect(html).toContain('aria-label="Copier 3812 E5"');
    const radios = html.match(/<button[^>]*role="radio"[\s\S]*?<\/button>/g) ?? [];
    expect(radios).toHaveLength(2);
    for (const r of radios) expect(r.match(/<button/g)).toHaveLength(1);
  });

  it('marks a part retained by the agent with its basis, never as the user’s or as confirmed', () => {
    const html = render('c1', 'agent');
    expect(html).toContain('Votre véhicule');
    expect(html).toContain('code de fabrication (OPR 09824 ≤ 10310)');
    expect(html).not.toContain('confirmé');
    expect(html).not.toContain('choisie par vous');
  });

  it('sets the other part aside, still a radio, with what rules it out', () => {
    const n = n307();
    const selection = selectVariant(n, { id: 'c1', by: 'agent', selectionId: SEL }, { id: SEL, clusterId: 'c1', basis: 'code de fabrication (OPR 09824 ≤ 10310)', setAside: 'OPR ≥ 10311' });
    const html = renderToStaticMarkup(createElement(VariantTable, { narrowing: n, make: 'PEUGEOT', selection, onPick: () => {} }));
    expect(html).toMatch(/data-mark="aside"/);
    expect(html).toContain('Ne correspond pas : OPR ≥ 10311');
    expect((html.match(/role="radio"/g) ?? []).length).toBe(2);
    expect(render(null, null)).not.toContain('data-mark');
  });

  it('says the number of parts once, in the table head', () => {
    expect(render(null, null).match(/2 pièces distinctes/g)).toHaveLength(1);
  });

  it('marks a part picked by the user as theirs, never as confirmed', () => {
    const html = render('c2', 'user');
    expect(html).toContain('choisie par vous');
    expect(html).not.toContain('confirmé');
  });

  it('hides the set-aside rows behind a link once the agent retained a part', () => {
    const n = n307();
    const selection = selectVariant(n, { id: 'c1', by: 'agent', selectionId: SEL }, { id: SEL, clusterId: 'c1', basis: 'code de fabrication (OPR 09824 ≤ 10310)', setAside: 'OPR ≥ 10311' });
    const html = renderToStaticMarkup(createElement(VariantTable, { narrowing: n, make: 'PEUGEOT', selection, onPick: () => {} }));
    const [shown, hidden] = html.split('class="vt-more"');
    expect(shown).toContain('3812 E0');
    expect(shown).not.toContain('3812 E5');
    expect(hidden).toContain('3812 E5');
    expect(hidden).toContain('data-open="false"');
    expect(hidden).toContain('inert=""');
    expect(html).toContain('aria-expanded="false"');
    expect(html).toContain('Voir l’autre référence (ne correspond pas)');
  });

  it('shows every row when nothing is settled, with no link', () => {
    const html = render(null, null);
    expect(html).not.toContain('vt-more');
    expect(html).not.toContain('-more"');
  });
});

describe('hiddenVariantIds', () => {
  const n = n307();
  const verdict = { id: SEL, clusterId: 'c1', basis: 'code', setAside: null };
  const rows = variantRows(n, 'PEUGEOT');

  it('hides everything but the retained row while the retained part is the one shown', () => {
    expect(hiddenVariantIds(rows, selectVariant(n, { id: 'c1', by: 'agent', selectionId: SEL }, verdict))).toEqual(['c2']);
  });

  it('hides nothing when nothing is settled or the user shows another row', () => {
    expect(hiddenVariantIds(rows, selectVariant(n, null))).toEqual([]);
    expect(hiddenVariantIds(rows, selectVariant(n, { id: 'c2', by: 'user' }))).toEqual([]);
    expect(hiddenVariantIds(rows, selectVariant(n, { id: 'c2', by: 'user', retained: { id: 'c1', selectionId: SEL } }, verdict))).toEqual([]);
  });
});

describe('othersLabel', () => {
  it('words the link by the number of hidden references and its state', () => {
    expect(othersLabel(1, false)).toBe('Voir l’autre référence (ne correspond pas)');
    expect(othersLabel(3, false)).toBe('Voir les 3 autres références (ne correspondent pas)');
    expect(othersLabel(1, true)).toBe('Masquer l’autre référence');
    expect(othersLabel(3, true)).toBe('Masquer les 3 autres références');
  });
});

describe('refineHint', () => {
  const verdict = { id: SEL, clusterId: 'c1', basis: 'code de fabrication', setAside: null };

  it('counts the parts the variant table lists while none is settled', () => {
    const n = n307();
    expect(refineHint(n, null, selectVariant(n, null))).toBe(`${n.clusters.length} pièces possibles. Précisez ci-dessous pour trancher.`);
  });

  it('is the table’s own count, not a recount', () => {
    const n = n307();
    expect(refineHint(n, null, selectVariant(n, null))).toContain(`${variantRows(n, null).length} pièces`);
  });

  it('is silent for a single part, once retained, confirmed or picked, and with no result', () => {
    const n = n307();
    expect(refineHint(mapNarrowing(mazda), null, selectVariant(mapNarrowing(mazda), null))).toBeNull();
    expect(refineHint(n, null, selectVariant(n, { id: 'c1', by: 'agent', selectionId: SEL }, verdict))).toBeNull();
    expect(refineHint(n, null, selectVariant(n, { id: 'c2', by: 'answer' }))).toBeNull();
    expect(refineHint(n, null, selectVariant(n, { id: 'c2', by: 'user' }))).toBeNull();
    expect(refineHint(null, null, selectVariant(null, null))).toBeNull();
  });
});

describe('orderedRows', () => {
  const rows = ['a', 'b', 'c'].map((id) => ({ id }));
  const sel = (id: string, status: Selection['status']) => ({ cluster: { id } as Cluster, index: 0, status }) as Selection;

  it('puts the part picked from outside the table first, the rest in order', () => {
    expect(orderedRows(rows, sel('c', 'answer')).map((r) => r.id)).toEqual(['c', 'a', 'b']);
    expect(orderedRows(rows, sel('b', 'agent')).map((r) => r.id)).toEqual(['b', 'a', 'c']);
  });

  it('keeps the order when the user picked in the table or nothing is settled', () => {
    expect(orderedRows(rows, sel('c', 'user')).map((r) => r.id)).toEqual(['a', 'b', 'c']);
    expect(orderedRows(rows, sel('c', 'open')).map((r) => r.id)).toEqual(['a', 'b', 'c']);
    expect(orderedRows(rows, { cluster: null, index: -1, status: 'open' })).toBe(rows);
  });
});
