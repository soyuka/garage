import { copyText, cotesLine, degradedMessage, foldEquivalents, pickOe, rankEquivalents, relaunchText } from './result';
import { mapNarrowing } from './api';
import axialFixture from '../test-fixtures/narrowed-307-axial.json';
import narrowed from '../test-fixtures/narrowed.json';

const NNBSP = ' ';
const n = mapNarrowing(narrowed);
const c1 = n.clusters[0]!;

describe('pickOe', () => {
  it('prefers an OE number of the vehicle make', () => {
    expect(pickOe({ ...c1, oeNumbers: [{ brand: 'FORD', number: '1S7G' }, { brand: 'MAZDA', number: 'LF10-14-302' }] }, 'Mazda')).toEqual({ brand: 'MAZDA', number: 'LF10-14-302' });
  });

  it('falls back to the first OE number, then to nothing', () => {
    expect(pickOe({ ...c1, oeNumbers: [{ brand: 'FORD', number: '1S7G' }] }, 'MAZDA')).toEqual({ brand: 'FORD', number: '1S7G' });
    expect(pickOe({ ...c1, oeNumbers: [] }, 'MAZDA')).toBeNull();
  });

  it('numbers every part of a result in the scheme all of them have for the make', () => {
    const axial = mapNarrowing(axialFixture);
    const [a, b] = axial.clusters;
    expect(pickOe(a!, 'PEUGEOT', axial.clusters)).toEqual({ brand: 'PEUGEOT', number: '3812 E0' });
    expect(pickOe(b!, 'PEUGEOT', axial.clusters)).toEqual({ brand: 'PEUGEOT', number: '3812 E5' });
  });

  it('does the same without a make, across every carmaker', () => {
    const axial = mapNarrowing(axialFixture);
    const [a, b] = axial.clusters.map((c) => pickOe(c, null, axial.clusters)?.number.replace(/[. ]/g, ''));
    expect(a).toMatch(/^3812E\d$/);
    expect(b).toMatch(/^3812E\d$/);
  });

  it('keeps the plain choice when the parts share no numbering scheme', () => {
    const a = { ...c1, id: 'a', oeNumbers: [{ brand: 'PEUGEOT', number: '16 072 936 80' }] };
    const b = { ...c1, id: 'b', oeNumbers: [{ brand: 'PEUGEOT', number: '3812 E5' }] };
    expect(pickOe(a, 'PEUGEOT', [a, b])).toEqual({ brand: 'PEUGEOT', number: '16 072 936 80' });
  });

  it('ignores the make filter when the make is unknown', () => {
    const a = { ...c1, id: 'a', oeNumbers: [{ brand: 'FORD', number: '1S7G' }] };
    expect(pickOe(a, null, [a])).toEqual({ brand: 'FORD', number: '1S7G' });
  });
});

describe('foldEquivalents', () => {
  const eq = { shown: [1, 2, 3].map((i) => ({ artId: i, brand: `B${i}`, number: `N${i}`, tier: 1 })), otherBrands: 4 };

  it('keeps the first references and counts every brand it hides', () => {
    const f = foldEquivalents(eq, 1);
    expect(f.visible.map((v) => v.artId)).toEqual([1]);
    expect(f.hidden).toBe(6);
  });

  it('hides nothing when everything fits', () => {
    expect(foldEquivalents({ shown: eq.shown.slice(0, 1), otherBrands: 0 }, 2)).toEqual({ visible: eq.shown.slice(0, 1), hidden: 0 });
  });
});

describe('rankEquivalents', () => {
  it('shows one reference per brand, best tier first, and counts the other brands', () => {
    const r = rankEquivalents({
      ...c1,
      variants: [
        { artId: 1, brand: 'NONAME', number: 'N1', tier: 3 },
        { artId: 2, brand: 'MAHLE', number: 'OC 1', tier: 1 },
        { artId: 3, brand: 'MAHLE', number: 'OC 2', tier: 1 },
        { artId: 4, brand: 'PURFLUX', number: 'LS1', tier: 2 },
      ],
    }, 2);
    expect(r.shown.map((v) => v.number)).toEqual(['OC 1', 'LS1']);
    expect(r.otherBrands).toBe(1);
  });
});

describe('copyText', () => {
  it('copies the OE number alone: no make, no equivalent, no part name', () => {
    expect(copyText({ brand: 'MAZDA', number: 'LF10-14-302' }, { brand: 'MANN-FILTER', number: 'W 712/73' })).toBe('LF10-14-302');
  });

  it('copies the reference number alone when the carmaker number is unknown', () => {
    expect(copyText(null, { brand: 'MANN-FILTER', number: 'W 712/73' })).toBe('W 712/73');
    expect(copyText(null, null)).toBe('');
  });
});

describe('cotesLine', () => {
  it('summarises the filter dimensions in drafting notation, ranges included', () => {
    expect(cotesLine(c1.dimensions)).toBe(`Ø${NNBSP}75–78 · H${NNBSP}85–94 · 3/4-16 · joint Ø${NNBSP}62/71–72${NNBSP}mm`);
  });

  it('ignores info-only figures', () => {
    const noisy = [{ key: 'diametre_1', label: 'Diamètre 1', value: '12', number: 12, unit: 'mm', min: 12, max: 12, info: true }, ...c1.dimensions];
    expect(cotesLine(noisy)).toBe(cotesLine(c1.dimensions));
  });

  it('is null when nothing is measurable', () => {
    expect(cotesLine([])).toBeNull();
  });
});

describe('degradedMessage', () => {
  it('says in plain French what the narrowing missed', () => {
    expect(degradedMessage('criteria unavailable from the parts catalog (quota or outage)')).toMatch(/^Cotes non chargées/);
    expect(degradedMessage('cross-references unavailable from the parts catalog (quota or outage): OE numbers and the remaining articles could not be classified')).toMatch(/OE/);
    expect(degradedMessage('no physical criteria available to tell the parts apart')).toMatch(/aucune cote/i);
    expect(degradedMessage('a; b')).toMatch(/catalogue/);
    expect(degradedMessage('images unavailable from the parts catalog (quota or outage)')).toMatch(/Réponse partielle/);
  });
});

describe('relaunchText', () => {
  const mixed = mapNarrowing({ groupRequired: true, totalArticles: 804, productGroups: [{ id: 914, name: 'Rotule de barre de connexion', articleCount: 514 }, { id: 51, name: 'Rotule de direction intérieure, barre de connexion', articleCount: 288 }] });

  it('hands a restored mixed category back to the agent with the original request', () => {
    expect(relaunchText(mixed, 'rotule axiale', 'Rotules')).toBe('rotule axiale');
  });

  it('falls back to the category name when the request is unknown (a shared link)', () => {
    expect(relaunchText(mixed, null, 'Rotules')).toBe('Rotules');
    expect(relaunchText(mixed, '  ', 'Rotules')).toBe('Rotules');
  });

  it('never relaunches a result that narrowed', () => {
    expect(relaunchText(n, 'filtre à huile', 'Filtre à huile')).toBeNull();
  });
});
