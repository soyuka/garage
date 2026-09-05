import type { MaintenanceRecord } from '../types';
import { HOME, toSpherical } from './cameraRig';

// The visit ring (CHOREOGRAPHY § "Stations — data given a body", "History — the visit ring").

export interface VisitSlab {
  id: string;
  index: number;
  angle: number;
  position: [number, number, number];
  height: number;
  hasCost: boolean;
  costCents: number | null;
}

/** Between HoloPodium's near (3.42–3.47) and far (3.74–3.765) echo rings. */
export const RING_RADIUS = 3.6;
export const FLOOR_HEIGHT = 0.18;
export const MAX_HEIGHT = 1.0;
const MAX_SPAN = (200 * Math.PI) / 180;
const MAX_STEP = (14 * Math.PI) / 180;

/** The camera's resting azimuth (toSpherical's convention, atan2(x,z) off HOME) — the arc's centre when
 * the caller does not supply one. Derived from HOME rather than a duplicated literal. */
function homeAzimuth(): number {
  const offset: [number, number, number] = [
    HOME.position[0] - HOME.target[0],
    HOME.position[1] - HOME.target[1],
    HOME.position[2] - HOME.target[2],
  ];
  return toSpherical(offset).azimuth;
}

function effectiveCost(record: MaintenanceRecord): number | null {
  return record.effectiveCostCents ?? record.costCents ?? null;
}

// A visit with no cost at all is wire only at the floor height (`hasCost: false`): unknown is not zero.
export function visitRingLayout(
  records: MaintenanceRecord[],
  opts: { radius?: number; cameraAzimuth?: number; maxSpan?: number; maxStep?: number } = {},
): VisitSlab[] {
  const n = records.length;
  if (n === 0) return [];

  const radius = opts.radius ?? RING_RADIUS;
  const cameraAzimuth = opts.cameraAzimuth ?? homeAzimuth();
  const maxSpan = opts.maxSpan ?? MAX_SPAN;
  const maxStep = opts.maxStep ?? MAX_STEP;

  const sorted = [...records].sort((a, b) => a.performedAt.localeCompare(b.performedAt) || a.id.localeCompare(b.id));
  const costs = sorted.map(effectiveCost);
  const maxCost = costs.reduce<number>((m, c) => (c != null && c > m ? c : m), 0);

  const step = n > 1 ? Math.min(maxStep, maxSpan / (n - 1)) : 0;
  const totalSpan = step * (n - 1);
  const start = cameraAzimuth - totalSpan / 2;

  return sorted.map((record, index) => {
    const angle = start + index * step;
    const cost = costs[index];
    const hasCost = cost != null;
    const height = hasCost && maxCost > 0 ? FLOOR_HEIGHT + (cost! / maxCost) * (MAX_HEIGHT - FLOOR_HEIGHT) : FLOOR_HEIGHT;
    return {
      id: record.id,
      index,
      angle,
      position: [radius * Math.sin(angle), 0.02, radius * Math.cos(angle)],
      height,
      hasCost,
      costCents: cost,
    };
  });
}
