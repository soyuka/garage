import type { SpecNode } from '../types';
import { HOME, toSpherical } from './cameraRig';

// The fluid volumes' body seam. Mirrors visitRing.ts / factConstellation.ts file-for-file: pure layout core, no r3f here.

export interface FluidCylinder {
  key: string;
  index: number;
  position: [number, number, number];
  height: number;
  radius: number;
  hasVolume: boolean;
  litres: number | null;
}

export const RADIUS = 0.15;
export const LITRE_HEIGHT = 0.14;
export const H_MAX = 1.4;
export const FLOOR_HEIGHT = 0.12;
export const ROW_RADIUS = 2.3;
export const GAP = 0.12;

/** The camera's resting azimuth (toSpherical's convention, atan2(x,z) off HOME) — the row's centre
 *  when the caller does not supply one. Same derivation as visitRing.ts's homeAzimuth. */
function homeAzimuth(): number {
  const offset: [number, number, number] = [
    HOME.position[0] - HOME.target[0],
    HOME.position[1] - HOME.target[1],
    HOME.position[2] - HOME.target[2],
  ];
  return toSpherical(offset).azimuth;
}

/**
 * Every volume node laid out as a cylinder in a row in front of the car, facing the camera's home
 * azimuth. Only nodes carrying `volumeLitres !== undefined` get a body at all — an info-only node
 * (a grade, a spec string) has none and is dropped from the row entirely. Among those that remain, a
 * confirmed volume (`litres` a number) sets the height, capped at H_MAX; past the cap the radius
 * grows so the cylinder's volume (π r² h) stays proportional to litres regardless of the cap. A mass
 * or otherwise non-volume reading (`litres === null`) stands wire-only at FLOOR_HEIGHT/RADIUS (truth
 * first — unknown/non-volume is not zero). Row order follows node order; cylinders sit edge to edge
 * (GAP between them, radius-aware), the whole row centred on its own middle.
 */
export function fluidVolumesLayout(nodes: SpecNode[], opts: { radius?: number; azimuth?: number } = {}): FluidCylinder[] {
  const withVolume = nodes.filter((n) => n.volumeLitres !== undefined);
  const n = withVolume.length;
  if (n === 0) return [];

  const rowRadius = opts.radius ?? ROW_RADIUS;
  const az = opts.azimuth ?? homeAzimuth();
  const center: [number, number, number] = [rowRadius * Math.sin(az), 0.02, rowRadius * Math.cos(az)];
  // The row runs along the camera's rightward ground-plane vector (same construction as cameraRig's
  // station target shift), so it reads as a line laid across the view rather than radially.
  const right: [number, number, number] = [Math.cos(az), 0, -Math.sin(az)];

  const bodies = withVolume.map((node) => {
    const litres = node.volumeLitres ?? null;
    if (litres == null) return { litres, hasVolume: false, height: FLOOR_HEIGHT, radius: RADIUS };
    const rawHeight = litres * LITRE_HEIGHT;
    const height = Math.min(rawHeight, H_MAX);
    // rawHeight/height === 1 while uncapped, so radius stays RADIUS; past the cap it grows exactly
    // enough that RADIUS² × rawHeight (∝ litres) is conserved — never recomputed from height alone.
    const radius = RADIUS * Math.sqrt(rawHeight / height);
    return { litres, hasVolume: true, height, radius };
  });

  const totalWidth = bodies.reduce((sum, b) => sum + 2 * b.radius, 0) + GAP * (n - 1);
  let edge = -totalWidth / 2;

  return withVolume.map((node, index) => {
    const body = bodies[index];
    const offset = edge + body.radius;
    edge += 2 * body.radius + GAP;
    return {
      key: node.key,
      index,
      position: [center[0] + right[0] * offset, center[1], center[2] + right[2] * offset],
      height: body.height,
      radius: body.radius,
      hasVolume: body.hasVolume,
      litres: body.litres,
    };
  });
}
