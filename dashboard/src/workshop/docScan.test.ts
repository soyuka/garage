import { describe, expect, it } from 'vitest';
import type { DocScanHit } from '../types';
import { SCAN, scanCaption, scanCursor, scanEndsAt, scanIntents, scanPlan, specForHit } from './docScan';

const hit = (n: number, documentId = 'DOC1'): DocScanHit => ({
  documentId,
  page: n,
  pageLabel: `21A-${n}`,
  url: `/garage/vehicles/V/documents/${documentId}/file`,
  title: 'MR 361',
  contentType: 'application/pdf',
  filename: 'mr361.pdf',
  pagesUrl: `/garage/vehicles/V/documents/${documentId}/pages`,
});

describe('scanPlan', () => {
  it('steps through the hits in rank order, one sweep + hold per hop, then rests on the best hit', () => {
    const plan = scanPlan(3, false);
    const hop = SCAN.sweepMs + SCAN.holdMs;
    expect(plan).toEqual([
      { hit: 0, at: 0, sweep: true },
      { hit: 1, at: hop, sweep: true },
      { hit: 2, at: 2 * hop, sweep: true },
      { hit: 0, at: 3 * hop, sweep: false },
    ]);
  });

  it('caps the scan at four hits and the whole of it inside the budget', () => {
    const plan = scanPlan(6, false);
    expect(plan.filter((s) => s.sweep).map((s) => s.hit)).toEqual([0, 1, 2, 3]);
    expect(scanEndsAt(plan)).toBeLessThanOrEqual(SCAN.budgetMs);
  });

  it('a single hit sweeps once and stays: no hop back', () => {
    expect(scanPlan(1, false)).toEqual([{ hit: 0, at: 0, sweep: true }]);
  });

  it('reduced motion: no sweep, no hops — straight to the best hit', () => {
    expect(scanPlan(4, true)).toEqual([{ hit: 0, at: 0, sweep: false }]);
  });

  it('no hits: nothing to play', () => {
    expect(scanPlan(0, false)).toEqual([]);
    expect(scanPlan(0, true)).toEqual([]);
  });
});

describe('scanCursor', () => {
  const plan = scanPlan(3, false);
  const hop = SCAN.sweepMs + SCAN.holdMs;

  it('is the last step whose start has passed', () => {
    expect(scanCursor(plan, 0)).toBe(0);
    expect(scanCursor(plan, hop - 1)).toBe(0);
    expect(scanCursor(plan, hop)).toBe(1);
    expect(scanCursor(plan, 10 * hop)).toBe(3);
  });

  it('is -1 for an empty plan', () => {
    expect(scanCursor([], 100)).toBe(-1);
  });
});

describe('scanEndsAt', () => {
  it('a final sweep still owns its sweep and hold', () => {
    expect(scanEndsAt([{ hit: 0, at: 0, sweep: true }])).toBe(SCAN.sweepMs + SCAN.holdMs);
  });
  it('a settle step ends the moment it lands', () => {
    expect(scanEndsAt(scanPlan(2, false))).toBe(2 * (SCAN.sweepMs + SCAN.holdMs));
    expect(scanEndsAt(scanPlan(2, true))).toBe(0);
  });
});

describe('specForHit', () => {
  it('opens the hit page of its document with the query highlighted', () => {
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

describe('scanCaption', () => {
  it('names the position among the scanned hits', () => {
    expect(scanCaption({ query: 'huile', hits: [hit(1), hit(2), hit(3), hit(4), hit(5)] }, scanPlan(5, false), 1)).toEqual({ kind: 'position', query: 'huile', position: 2, total: 4 });
  });

  it('resting on the best hit after the hops reads 1/n', () => {
    const plan = scanPlan(3, false);
    expect(scanCaption({ query: 'huile', hits: [hit(1), hit(2), hit(3)] }, plan, plan.length - 1)).toEqual({ kind: 'position', query: 'huile', position: 1, total: 3 });
  });

  it('reduced motion shows the count of hits instead', () => {
    expect(scanCaption({ query: 'huile', hits: [hit(1), hit(2), hit(3), hit(4), hit(5)] }, scanPlan(5, true), 0)).toEqual({ kind: 'count', query: 'huile', count: 5 });
  });

  it('no match', () => {
    expect(scanCaption({ query: 'turbo', hits: [] }, [], -1)).toEqual({ kind: 'none', query: 'turbo' });
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
