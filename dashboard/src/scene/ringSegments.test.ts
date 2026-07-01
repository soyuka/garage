import { describe, it, expect } from 'vitest';
import { computeRingSegments } from './ringSegments';
import type { ForecastDue } from '../types';

// The turntable rim is divided into one arc per échéance, overdue arcs first (contiguous red arc) so the
// red proportion visually matches how much of the schedule is overdue — see Plateau's Turntable().

function due(type: string, over: Partial<ForecastDue> = {}): ForecastDue {
  return {
    type, action: null, component: null, rule: 'schedule', lastPerformedAt: '2024-11-15',
    dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: 365, usage: null, ...over,
  };
}

const TAU = Math.PI * 2;

describe('computeRingSegments — one ring arc per échéance', () => {
  it('returns a single full-circle segment when there is no due', () => {
    expect(computeRingSegments([])).toEqual([{ thetaStart: 0, thetaLength: TAU, overdue: false }]);
  });

  it('emits one segment per due', () => {
    const segs = computeRingSegments([due('oil_change'), due('coolant'), due('brake_fluid')]);
    expect(segs).toHaveLength(3);
  });

  it('sorts overdue segments first, forming a contiguous red arc from 12 o\'clock', () => {
    const segs = computeRingSegments([
      due('coolant'),
      due('oil_change', { overdue: true }),
      due('brake_fluid'),
      due('brake_pads', { overdue: true }),
    ]);
    expect(segs.map((s) => s.overdue)).toEqual([true, true, false, false]);
    expect(segs[0].thetaStart).toBe(0);
    // the overdue arc is contiguous: second overdue segment starts right after the first (plus the gap)
    expect(segs[1].thetaStart).toBeCloseTo(segs[0].thetaStart + segs[0].thetaLength + 0.04, 10);
  });

  it('sums thetaLength + gaps to a full circle', () => {
    const gapRad = 0.04;
    const segs = computeRingSegments([due('a'), due('b'), due('c'), due('d', { overdue: true })], { gapRad });
    const sumLengths = segs.reduce((s, seg) => s + seg.thetaLength, 0);
    const sumGaps = gapRad * segs.length;
    expect(sumLengths + sumGaps).toBeCloseTo(TAU, 10);
  });

  it('never overlaps — each segment starts where the previous one plus its gap ends', () => {
    const gapRad = 0.04;
    const segs = computeRingSegments([due('a'), due('b'), due('c')], { gapRad });
    for (let i = 1; i < segs.length; i++) {
      expect(segs[i].thetaStart).toBeCloseTo(segs[i - 1].thetaStart + segs[i - 1].thetaLength + gapRad, 10);
    }
  });

  it('every segment has a positive width', () => {
    const segs = computeRingSegments([due('a'), due('b'), due('c', { overdue: true })]);
    for (const s of segs) expect(s.thetaLength).toBeGreaterThan(0);
  });

  it('respects a custom gapRad', () => {
    const segs = computeRingSegments([due('a'), due('b')], { gapRad: 0.1 });
    const sumLengths = segs.reduce((s, seg) => s + seg.thetaLength, 0);
    expect(sumLengths + 0.1 * segs.length).toBeCloseTo(TAU, 10);
  });
});
