import { copyText, cotesLine, degradedMessage, pickOe, rankEquivalents, sourceLabel } from './result';
import { mapNarrowing } from './api';
import narrowed from './fixtures/narrowed.json';

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
  it('copies the OE reference and the top equivalent', () => {
    expect(copyText({ brand: 'MAZDA', number: 'LF10-14-302' }, { artId: 1, brand: 'MANN-FILTER', number: 'W 712/73', tier: 1 })).toBe('MAZDA LF10-14-302 · MANN-FILTER W 712/73');
    expect(copyText(null, { artId: 1, brand: 'MANN-FILTER', number: 'W 712/73', tier: 1 })).toBe('MANN-FILTER W 712/73');
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
    expect(degradedMessage('criteria unavailable from the parts catalog (quota or outage)')).toMatch(/cotes/);
    expect(degradedMessage('cross-references unavailable from the parts catalog (quota or outage): OE numbers and the remaining articles could not be classified')).toMatch(/OE/);
    expect(degradedMessage('no physical criteria available to tell the parts apart')).toMatch(/aucune cote/i);
    expect(degradedMessage('a; b')).toMatch(/catalogue/);
    expect(degradedMessage('images unavailable from the parts catalog (quota or outage)')).toMatch(/partiellement/);
  });
});

describe('sourceLabel', () => {
  it('never names TecDoc for synthetic demo parts', () => {
    expect(sourceLabel({ demo: true, synthetic: true })).toBe('Démo (données fictives)');
  });

  it('names TecDoc for live data and for recorded catalogue fixtures', () => {
    expect(sourceLabel({ demo: false, synthetic: false })).toBe('TecDoc');
    expect(sourceLabel({ demo: true, synthetic: false })).toBe('TecDoc');
  });
});
