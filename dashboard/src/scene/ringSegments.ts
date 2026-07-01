import type { ForecastDue } from '../types';

// The turntable rim is a HEALTH halo (Plateau's Turntable()) — today one solid ring. This module divides
// it into one arc per échéance so the red proportion visually matches how much of the fleet's due work is
// overdue, instead of the whole ring flipping red on a single overdue item. Pure geometry only: Plateau
// renders a <ringGeometry thetaStart thetaLength> per segment; colour selection stays in Plateau/signalColors.

export interface RingSegment {
  thetaStart: number;
  thetaLength: number;
  overdue: boolean;
}

const TAU = Math.PI * 2;
const DEFAULT_GAP_RAD = 0.04;

/** Equal-width arcs around the full circle, one per due, overdue ones first (contiguous), starting at 12 o'clock. */
export function computeRingSegments(due: ForecastDue[], opts?: { gapRad?: number }): RingSegment[] {
  if (due.length === 0) return [{ thetaStart: 0, thetaLength: TAU, overdue: false }];

  const gapRad = opts?.gapRad ?? DEFAULT_GAP_RAD;
  const ordered = [...due].sort((a, b) => Number(b.overdue) - Number(a.overdue));
  const n = ordered.length;
  const totalGap = gapRad * n;
  const thetaLength = (TAU - totalGap) / n;

  let cursor = 0;
  return ordered.map((d) => {
    const seg: RingSegment = { thetaStart: cursor, thetaLength, overdue: d.overdue };
    cursor += thetaLength + gapRad;
    return seg;
  });
}
