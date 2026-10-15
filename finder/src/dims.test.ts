import { baseView, dimMid, dimText, findDim, threadSize } from './dims';
import type { Dimension } from './types';

const d = (key: string, value: string, min: number | null, max: number | null, info = false): Dimension => ({
  key, label: key, value, number: Number.isNaN(Number(value)) ? null : Number(value), unit: 'mm', min, max, info,
});

describe('dimText', () => {
  it('shows the range when the brands disagree, a single value when they agree', () => {
    expect(dimText(d('diametre_exterieur', '76', 75, 78))).toBe('75–78');
    expect(dimText(d('hauteur', '93', 85, 94))).toBe('85–94');
    expect(dimText(d('diametre_interieur_de_la_bague_d_etancheite', '62', 62, 62))).toBe('62');
  });

  it('falls back to the raw value without bounds', () => {
    expect(dimText(d('hauteur', '93', null, null))).toBe('93');
    expect(dimText(d('thread', '3/4-16', null, null))).toBe('3/4-16');
  });
});

describe('dimMid', () => {
  it('is the midpoint of min and max, else the value', () => {
    expect(dimMid(d('hauteur', '93', 85, 94))).toBe(89.5);
    expect(dimMid(d('diametre_exterieur', '76', 75, 78))).toBe(76.5);
    expect(dimMid(d('hauteur', '93', null, null))).toBe(93);
    expect(dimMid(d('thread', '3/4-16', null, null))).toBeNull();
  });
});

describe('findDim', () => {
  it('finds the outer diameter and height, never an info-only key', () => {
    const dims = [d('diametre_2', '71', 71, 71, true), d('diametre_1', '80', 80, 80), d('hauteur_1', '90', 90, 90)];
    expect(findDim(dims, 'outer')?.key).toBe('diametre_1');
    expect(findDim(dims, 'height')?.key).toBe('hauteur_1');
    expect(findDim([d('diametre_exterieur', '0', 0, 0)], 'outer')).toBeUndefined();
  });
});

describe('baseView', () => {
  const fingerprint = [
    d('diametre_exterieur', '76', 75, 78),
    d('diametre_exterieur_de_la_bague_d_etancheite', '71', 71, 72),
    d('diametre_interieur_de_la_bague_d_etancheite', '62', 62, 62),
    d('thread', '3/4-16', null, null),
  ];

  it('draws from the fingerprint midpoints and labels with the shared formatter', () => {
    expect(baseView(fingerprint)).toEqual({
      outer: { mid: 76.5, text: '75–78' },
      sealOuter: { mid: 71.5, text: '71–72' },
      sealInner: { mid: 62, text: '62' },
      thread: '3/4-16',
    });
  });

  it('is null without an outer diameter, and tolerates a missing seal', () => {
    expect(baseView([d('hauteur', '93', 85, 94)])).toBeNull();
    expect(baseView([fingerprint[0]!])).toEqual({ outer: { mid: 76.5, text: '75–78' }, sealOuter: null, sealInner: null, thread: null });
  });
});

describe('threadSize', () => {
  it('reads UNF inch threads and metric threads into major diameter and pitch, in mm', () => {
    expect(threadSize('3/4-16')).toEqual({ major: 19.05, pitch: 1.5875 });
    expect(threadSize('3/4"-16 UNF')).toEqual({ major: 19.05, pitch: 1.5875 });
    expect(threadSize('M20x1.5')).toEqual({ major: 20, pitch: 1.5 });
    expect(threadSize('M 22 x 1,5')).toEqual({ major: 22, pitch: 1.5 });
  });

  it('is null for anything else', () => {
    expect(threadSize('vissé')).toBeNull();
    expect(threadSize('')).toBeNull();
  });
});
