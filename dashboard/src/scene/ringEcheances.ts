import type { Echeance } from './echeances';

// Échéances are moving OFF the right dock and onto the ring: fixed glass panels floating around the
// turning disc's LEFT + bottom-left arc (the right side stays clear for the persistent task dock; the
// top stays clear for the car-info row + toggle). Two sets share the arc, toggled by EcheancePanels:
// DUES (overdue, red — the worst) vs UPCOMING (everything else that isn't a coverage gap).

export interface EcheanceSlot {
  echeance: Echeance;
  angleDeg: number; // around the disc, 0deg = top, clockwise
}

/** dues = overdue (red, non-gap). upcoming = the rest, gaps dropped (a gap has nothing to plan/show here). */
export function splitEcheances(items: Echeance[]): { dues: Echeance[]; upcoming: Echeance[] } {
  const dues: Echeance[] = [];
  const upcoming: Echeance[] = [];
  for (const e of items) {
    if (e.kind === 'gap') continue;
    if (e.signal === 'red') dues.push(e);
    else upcoming.push(e);
  }
  return { dues, upcoming };
}

// The LEFT flank only (clockwise from top: 200deg = lower-left, 320deg = upper-left). Deliberately
// avoids the bottom-centre (~180deg, where the composer sits) and the whole right side (the task dock),
// so panels stack down the car's left with no collision.
// Panels wrap ALL AROUND the car, spread across the LEFT and RIGHT flanks — deliberately skipping the
// top-centre (the car-info title) and bottom-centre (the composer + Le Noyau) dead zones. Overlapping the
// right task dock is fine (the operator asked for it). 0deg = top, clockwise.
const LEFT_FLANK: [number, number] = [212, 328]; // upper-left → lower-left
const RIGHT_FLANK: [number, number] = [148, 32]; // lower-right → upper-right (descending)

// kept for the "+N" overflow pill anchor (upper-left, out past the last left panel).
export const ARC_END_DEG = LEFT_FLANK[0];

function spread(count: number, [a, b]: [number, number]): number[] {
  if (count === 0) return [];
  if (count === 1) return [(a + b) / 2];
  return Array.from({ length: count }, (_, i) => a + ((b - a) * i) / (count - 1));
}

/** Spread N panels across both flanks — worst-first items fill the left flank, the rest the right. */
export function layoutSlots(items: Echeance[]): EcheanceSlot[] {
  const n = items.length;
  if (n === 0) return [];
  const leftCount = Math.ceil(n / 2);
  const leftAngles = spread(leftCount, LEFT_FLANK);
  const rightAngles = spread(n - leftCount, RIGHT_FLANK);
  return items.map((echeance, i) => ({
    echeance,
    angleDeg: i < leftCount ? leftAngles[i] : rightAngles[i - leftCount],
  }));
}
