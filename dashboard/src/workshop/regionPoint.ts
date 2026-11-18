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
type Fractions = Record<BodyRegion, { x: number; y: number; z: number }>;

const CAR: Fractions = {
  hood: { x: -0.32, y: 0.62, z: 0 },
  bay: { x: -0.22, y: 0.55, z: 0 },
  windshield: { x: -0.1, y: 0.86, z: 0 },
  frontAxle: { x: -0.31, y: 0.2, z: 0.5 },
  rearAxle: { x: 0.31, y: 0.2, z: 0.5 },
};

// A bike has no hood: the engine is the block low between the wheels, the bay sits under the tank, the
// windshield is the screen over the dash, and every point is on the centre plane (z = 0).
const MOTO: Fractions = {
  hood: { x: -0.04, y: 0.33, z: 0 },
  bay: { x: 0.02, y: 0.58, z: 0 },
  windshield: { x: -0.3, y: 0.88, z: 0 },
  frontAxle: { x: -0.34, y: 0.24, z: 0 },
  rearAxle: { x: 0.33, y: 0.22, z: 0 },
};

export function isMotorcycle(vehicleType: string): boolean {
  return /moto|motorcycle/i.test(vehicleType);
}

export function regionPoint(region: BodyRegion, box: BodyBox, vehicleType = ''): Point {
  const f = (isMotorcycle(vehicleType) ? MOTO : CAR)[region];
  return [f.x * box.length, f.y * box.height, f.z * box.width];
}
