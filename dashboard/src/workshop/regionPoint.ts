import type { BodyRegion } from '../scene/anchors';

export interface BodyBox {
  length: number;
  height: number;
  width: number;
}

export type Point = [number, number, number];

// Fractions of the mesh's own bounding box, centred on x/z with the floor at y = 0 and the front at −x
// (vehicleMesh.ts ROTATION_Y). anchors.ts regionAnchors reads proxy DIMS at +x and would land on the
// tail of a real mesh.
const FRACTIONS: Record<BodyRegion, { x: number; y: number; z: number }> = {
  hood: { x: -0.32, y: 0.62, z: 0 },
  bay: { x: -0.22, y: 0.55, z: 0 },
  windshield: { x: -0.1, y: 0.86, z: 0 },
  frontAxle: { x: -0.31, y: 0.2, z: 0.5 },
  rearAxle: { x: 0.31, y: 0.2, z: 0.5 },
};

export function regionPoint(region: BodyRegion, box: BodyBox): Point {
  const f = FRACTIONS[region];
  return [f.x * box.length, f.y * box.height, f.z * box.width];
}
