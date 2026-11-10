import { describe, expect, it } from 'vitest';
import tokens from './tokens.css?raw';
import type { DocScanHit } from '../types';
import { LINE_COUNT, PAGE_SKELETON, SEARCH_LOG, decodeText, hitBoxes, hitSlot, hitText, markTerms, scanIntents, searchTimeline, specForHit, sweepCrossFraction, typingMs } from './docScan';

const hit = (n: number, documentId = 'DOC1'): DocScanHit => ({
  documentId,
  page: n,
  pageLabel: `21A-${n}`,
  url: `/garage/vehicles/V/documents/${documentId}/file`,
  title: 'MR 361',
  contentType: 'application/pdf',
  filename: 'mr361.pdf',
  pagesUrl: `/garage/vehicles/V/documents/${documentId}/pages`,
  snippet: 'Capacité en huile 2,4 litres',
  sourceRef: 'MR 361 — Transmission',
});

describe('the search log timings are the duration tokens', () => {
  const token = (name: string) => {
    const m = new RegExp(`--w-dur-${name}:\\s*(\\d+)ms;`).exec(tokens);
    return m ? Number(m[1]) : null;
  };

  it('declares each JS-side duration once, in tokens.css', () => {
    expect(token('doc-scan')).toBe(SEARCH_LOG.sweepMs);
    expect(token('search-decode')).toBe(SEARCH_LOG.decodeMs);
    expect(token('search-settle')).toBe(SEARCH_LOG.settleMs);
    expect(token('search-stagger')).toBe(SEARCH_LOG.staggerMs);
    expect(token('search-type-char')).toBe(SEARCH_LOG.typeCharMs);
    expect(token('search-type-max')).toBe(SEARCH_LOG.typeMaxMs);
  });
});

describe('typingMs', () => {
  it('types at 30ms a character', () => {
    expect(typingMs('huile', false)).toBe(5 * SEARCH_LOG.typeCharMs);
  });

  it('caps a long query at 900ms', () => {
    expect(typingMs('courroie de distribution galet tendeur', false)).toBe(SEARCH_LOG.typeMaxMs);
  });

  it('reduced motion: the query is there at once', () => {
    expect(typingMs('huile', true)).toBe(0);
  });
});

describe('the box page', () => {
  it('is a title bar, ten text lines, one figure and one table', () => {
    expect(PAGE_SKELETON.filter((b) => b.kind === 'title')).toHaveLength(1);
    expect(PAGE_SKELETON.filter((b) => b.kind === 'line')).toHaveLength(LINE_COUNT);
    expect(PAGE_SKELETON.filter((b) => b.kind === 'figure')).toHaveLength(1);
    expect(PAGE_SKELETON.filter((b) => b.kind === 'table')).toHaveLength(1);
    for (const b of PAGE_SKELETON) {
      expect(b.x + b.w).toBeLessThanOrEqual(100);
      expect(b.y + b.h).toBeLessThanOrEqual(100);
    }
  });

  it('spreads the hits over the text lines, rank order top to bottom, never two on one line', () => {
    for (let count = 1; count <= SEARCH_LOG.maxHits; count++) {
      const slots = Array.from({ length: count }, (_, i) => hitSlot(i, count));
      expect(slots.every((s) => s >= 0 && s < LINE_COUNT)).toBe(true);
      expect([...slots].sort((a, b) => a - b)).toEqual(slots);
      expect(new Set(slots).size).toBe(count);
    }
    expect(Array.from({ length: 6 }, (_, i) => hitSlot(i, 6))).toEqual([0, 2, 4, 5, 7, 9]);
  });

  it('a hit box sits on its text line; at most six', () => {
    const lines = PAGE_SKELETON.filter((b) => b.kind === 'line');
    expect(hitBoxes(3)).toEqual([lines[hitSlot(0, 3)], lines[hitSlot(1, 3)], lines[hitSlot(2, 3)]]);
    expect(hitBoxes(9)).toHaveLength(SEARCH_LOG.maxHits);
    expect(hitBoxes(0)).toEqual([]);
  });

  it('is the same page every time: positions derive from the index only', () => {
    expect(hitBoxes(4)).toEqual(hitBoxes(4));
  });
});

describe('sweepCrossFraction', () => {
  it('the eased line is at the top at 0 and at the bottom at 1', () => {
    expect(sweepCrossFraction(0)).toBe(0);
    expect(sweepCrossFraction(1)).toBeCloseTo(1, 3);
  });

  it('rides the system ease-out: half the page is crossed well before half the time', () => {
    const half = sweepCrossFraction(0.5);
    expect(half).toBeGreaterThan(0);
    expect(half).toBeLessThan(0.25);
    expect(sweepCrossFraction(0.9)).toBeGreaterThan(half);
  });
});

describe('searchTimeline', () => {
  it('types, then sweeps; each box lights as the line passes it, rows at least 60ms apart', () => {
    const tl = searchTimeline('huile', 3, false);
    expect(tl.typeMs).toBe(150);
    expect(tl.sweepAt).toBe(150);
    expect(tl.lights).toHaveLength(3);
    tl.lights.forEach((at, i) => {
      const box = hitBoxes(3)[i];
      expect(at).toBeGreaterThanOrEqual(Math.round(tl.sweepAt + SEARCH_LOG.sweepMs * sweepCrossFraction(box.y / 100)));
      if (i > 0) expect(at - tl.lights[i - 1]).toBeGreaterThanOrEqual(SEARCH_LOG.staggerMs);
    });
    expect(tl.doneAt).toBe(Math.max(tl.sweepAt + SEARCH_LOG.sweepMs, tl.lights[2] + SEARCH_LOG.decodeMs));
  });

  it('no match: the sweep still runs, nothing lights', () => {
    const tl = searchTimeline('turbo', 0, false);
    expect(tl.lights).toEqual([]);
    expect(tl.doneAt).toBe(tl.sweepAt + SEARCH_LOG.sweepMs);
  });

  it('reduced motion: everything is there at once', () => {
    expect(searchTimeline('courroie', 4, true)).toEqual({ typeMs: 0, sweepAt: 0, lights: [0, 0, 0, 0], doneAt: 0 });
  });
});

describe('decodeText', () => {
  const glyphs = /^[A-Z0-9·/-]$/;

  it('resolves left to right: the first part is final, the rest random glyphs', () => {
    const out = decodeText('21A-5 huile', 0.5, () => 0);
    expect(out).toHaveLength(11);
    expect(out.slice(0, 5)).toBe('21A-5');
    for (const ch of out.slice(6)) expect(ch).toMatch(glyphs);
  });

  it('keeps the spaces, so the words hold their shape while they decode', () => {
    expect(decodeText('ab cd', 0, () => 0.5)[2]).toBe(' ');
  });

  it('is the final text at 1 and all glyphs at 0', () => {
    expect(decodeText('Capacité', 1, Math.random)).toBe('Capacité');
    for (const ch of decodeText('huile', 0, () => 0.99)) expect(ch).toMatch(glyphs);
  });
});

describe('hitText', () => {
  it('leads with the page and cuts the snippet around the first matched term', () => {
    const snippet = '…Vidanger la boîte de vitesses. Capacité en huile 2,4 litres - préconisation Elf Tranself…';
    const text = hitText('21A-6', snippet, ['huile'], 32);
    expect(text.startsWith('21A-6 · ')).toBe(true);
    expect(text.length).toBeLessThanOrEqual(32);
    expect(text).toContain('huile');
  });

  it('starts the snippet at the top when the term is near it, and folds whitespace', () => {
    expect(hitText('p. 3', 'Huile\n  moteur 5W-40', ['huile'], 40)).toBe('p. 3 · Huile moteur 5W-40');
  });

  it('a snippet with no term match keeps its head', () => {
    expect(hitText('p. 3', 'Couple de serrage 25 N.m', ['turbo'], 20)).toBe('p. 3 · Couple de se…');
  });
});

describe('markTerms', () => {
  it('marks the query terms, folded like the API (case, accents)', () => {
    expect(markTerms('Capacité HUILE', ['capacite', 'huile'])).toEqual([
      { text: 'Capacité', mark: true },
      { text: ' ', mark: false },
      { text: 'HUILE', mark: true },
    ]);
  });

  it('plain text when no term', () => {
    expect(markTerms('Couple 25 N.m', [])).toEqual([{ text: 'Couple 25 N.m', mark: false }]);
  });
});

describe('specForHit', () => {
  it('opens the hit page of its document, with the words to mark', () => {
    expect(specForHit(hit(7), 'capacité huile')).toEqual({
      url: '/garage/vehicles/V/documents/DOC1/file',
      title: 'MR 361',
      contentType: 'application/pdf',
      filename: 'mr361.pdf',
      pagesUrl: '/garage/vehicles/V/documents/DOC1/pages',
      page: 7,
      highlight: 'capacité huile',
    });
  });
});

describe('scanIntents', () => {
  const frame = { type: 'documentScan' as const, vehicle: '/garage/vehicles/KANGOO', query: 'huile', hits: [hit(2)] };

  it('drives the scanned car in first when another one is on screen', () => {
    expect(scanIntents(frame, '/garage/vehicles/CLIO')).toEqual([{ type: 'selectVehicle', id: '/garage/vehicles/KANGOO' }, frame]);
    expect(scanIntents(frame, null)).toEqual([{ type: 'selectVehicle', id: '/garage/vehicles/KANGOO' }, frame]);
  });

  it('plays straight away on the car already on screen', () => {
    expect(scanIntents(frame, '/garage/vehicles/KANGOO')).toEqual([frame]);
  });
});
