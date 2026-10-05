import { describe, expect, it } from 'vitest';
import {
  clampPage,
  findMatches,
  firstMatchAtOrAfter,
  fitWidthScale,
  fold,
  highlightTerms,
  layoutPages,
  outputScale,
  pageAt,
  pagesToRender,
  segmentsByItem,
  viewerKind,
  zoomStep,
} from './documentViewer';

describe('fold', () => {
  it('strips accents and lowercases without changing the length, like the API searcher', () => {
    expect(fold('Capacité HUILE')).toBe('capacite huile');
    expect(fold('Préconisation Elf')).toHaveLength('Préconisation Elf'.length);
    expect(fold('Ça')).toBe('ca');
  });
});

describe('highlightTerms', () => {
  it('splits on non-letters, drops French stopwords and one-letter terms', () => {
    expect(highlightTerms('vis de volant couple')).toEqual(['vis', 'volant', 'couple']);
    expect(highlightTerms('a b HUILE')).toEqual(['huile']);
    expect(highlightTerms('Capacité huile, capacité')).toEqual(['capacite', 'huile']);
  });

  it('keeps a lone short term when nothing meaningful is left, and nothing for a stopword-only query', () => {
    expect(highlightTerms('x')).toEqual(['x']);
    expect(highlightTerms('de la')).toEqual([]);
    expect(highlightTerms('   ')).toEqual([]);
    expect(highlightTerms(undefined)).toEqual([]);
  });
});

describe('findMatches', () => {
  it('finds every term occurrence accent- and case-insensitively, in reading order', () => {
    const matches = findMatches(['Capacité en ', 'HUILE 2,4 l — huile'], ['capacite', 'huile']);
    expect(matches).toEqual([
      { start: { item: 0, offset: 0 }, end: { item: 0, offset: 8 } },
      { start: { item: 1, offset: 0 }, end: { item: 1, offset: 5 } },
      { start: { item: 1, offset: 14 }, end: { item: 1, offset: 19 } },
    ]);
  });

  it('matches across text runs the PDF split a word into', () => {
    expect(findMatches(['Capa', 'cité'], ['capacite'])).toEqual([{ start: { item: 0, offset: 0 }, end: { item: 1, offset: 4 } }]);
  });

  it('merges overlapping term hits into one match', () => {
    expect(findMatches(['huile'], ['huile', 'huil'])).toEqual([{ start: { item: 0, offset: 0 }, end: { item: 0, offset: 5 } }]);
  });

  it('returns nothing without terms', () => {
    expect(findMatches(['huile'], [])).toEqual([]);
  });
});

describe('segmentsByItem', () => {
  it('cuts each match into per-run segments carrying the match index', () => {
    const items = ['Capa', 'cité huile'];
    const matches = findMatches(items, ['capacite', 'huile']);
    expect(segmentsByItem(matches, items.map((i) => i.length))).toEqual(
      new Map([
        [0, [{ start: 0, end: 4, match: 0 }]],
        [1, [{ start: 0, end: 4, match: 0 }, { start: 5, end: 10, match: 1 }]],
      ]),
    );
  });

  it('gives a run spanned in the middle of a match the whole run', () => {
    const matches = [{ start: { item: 0, offset: 2 }, end: { item: 2, offset: 1 } }];
    expect(segmentsByItem(matches, [4, 3, 5]).get(1)).toEqual([{ start: 0, end: 3, match: 0 }]);
  });
});

describe('firstMatchAtOrAfter', () => {
  it('picks the first match on or after the opening page, falling back to the first one', () => {
    const pages = [1, 1, 3, 4];
    expect(firstMatchAtOrAfter(pages, 2)).toBe(2);
    expect(firstMatchAtOrAfter(pages, 1)).toBe(0);
    expect(firstMatchAtOrAfter(pages, 9)).toBe(0);
    expect(firstMatchAtOrAfter([], 1)).toBe(-1);
  });
});

describe('page navigation', () => {
  it('clamps a typed page into the document', () => {
    expect(clampPage(0, 12)).toBe(1);
    expect(clampPage(13, 12)).toBe(12);
    expect(clampPage(Number.NaN, 12)).toBe(1);
    expect(clampPage(4.6, 12)).toBe(5);
  });

  it('steps the zoom through fixed stops relative to fit-width', () => {
    expect(zoomStep(1, 1)).toBe(1.25);
    expect(zoomStep(1, -1)).toBe(0.8);
    expect(zoomStep(1.1, 1)).toBe(1.25);
    expect(zoomStep(1.1, -1)).toBe(1);
    expect(zoomStep(4, 1)).toBe(4);
    expect(zoomStep(0.5, -1)).toBe(0.5);
  });

  it('fits the page width into the scroller, minus the gutter', () => {
    expect(fitWidthScale(600, 1224, 24)).toBe(2);
    expect(fitWidthScale(0, 1000, 24)).toBe(1);
  });
});

describe('layoutPages / pageAt / pagesToRender', () => {
  const layout = layoutPages(
    [
      { width: 100, height: 200 },
      { width: 100, height: 200 },
      { width: 200, height: 100 },
    ],
    2,
    10,
  );

  it('stacks the scaled pages with a gap', () => {
    expect(layout.tops).toEqual([10, 420, 830]);
    expect(layout.heights).toEqual([400, 400, 200]);
    expect(layout.widths).toEqual([200, 200, 400]);
    expect(layout.total).toBe(1040);
  });

  it('reads the current page off the upper third of the viewport', () => {
    expect(pageAt(layout, 0, 300)).toBe(1);
    expect(pageAt(layout, 400, 300)).toBe(2);
    expect(pageAt(layout, 1040, 300)).toBe(3);
  });

  it('renders only the pages near the viewport', () => {
    expect(pagesToRender(layout, 0, 300, 0)).toEqual([1]);
    expect(pagesToRender(layout, 0, 300, 300)).toEqual([1, 2]);
    expect(pagesToRender(layout, 800, 100, 0)).toEqual([2, 3]);
  });
});

describe('outputScale', () => {
  it('renders at the device pixel ratio', () => {
    expect(outputScale(800, 1000, 2, 16_777_216)).toBe(2);
  });

  it('caps the canvas area so a deep zoom on a HiDPI screen cannot exhaust memory', () => {
    const s = outputScale(4000, 5000, 2, 16_777_216);
    expect(s).toBeLessThan(2);
    expect(4000 * s * 5000 * s).toBeLessThanOrEqual(16_777_216);
  });
});

describe('viewerKind', () => {
  it('routes by content type', () => {
    expect(viewerKind('application/pdf')).toBe('pdf');
    expect(viewerKind('image/jpeg')).toBe('image');
    expect(viewerKind('IMAGE/PNG')).toBe('image');
    expect(viewerKind('application/zip')).toBe('other');
    expect(viewerKind('')).toBe('other');
  });
});
