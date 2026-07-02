import type { Echeance } from './echeances';

// The slot math: where each ring pin sits around the turning disc. Pins are the minimal spatial index
// (index + signal dot + hair leader toward the rail); their PROSE lives in the persistent left rail
// ("Entretien"). Worst-first items fill the left flank (nearest the rail), the rest the right — the top
// (car-info) and bottom (composer + Le Noyau) stay clear. 0deg = top, clockwise.

export interface EcheanceSlot<T extends Echeance = Echeance> {
  echeance: T;
  angleDeg: number; // around the disc, 0deg = top, clockwise
}

const LEFT_FLANK: [number, number] = [212, 328]; // upper-left → lower-left (nearest the rail)
const RIGHT_FLANK: [number, number] = [148, 32]; // lower-right → upper-right (descending)

function spread(count: number, [a, b]: [number, number]): number[] {
  if (count === 0) return [];
  if (count === 1) return [(a + b) / 2];
  return Array.from({ length: count }, (_, i) => a + ((b - a) * i) / (count - 1));
}

/** Spread N pins across both flanks — worst-first items fill the left flank, the rest the right. */
export function layoutSlots<T extends Echeance>(items: T[]): EcheanceSlot<T>[] {
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
