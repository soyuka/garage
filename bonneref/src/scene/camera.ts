export type Vec3 = readonly [number, number, number];

export const FOV = 22;
export const HOME = { azimuth: 0.62, polar: 1.12 } as const;
export const POLAR_MARGIN = 0.05;

const rad = (deg: number): number => (deg * Math.PI) / 180;

// Distance at which a sphere of `radius` fills `fill` of the narrower half-view, so the part keeps its
// framing whatever the drawing area's aspect.
export function fitDistance(radius: number, fovDeg: number, aspect: number, fill: number): number {
  const halfV = Math.tan(rad(fovDeg) / 2);
  const half = Math.min(halfV, halfV * aspect);
  return radius / (fill * half);
}

export function orbitPosition(target: Vec3, distance: number, azimuth: number, polar: number): Vec3 {
  return [
    target[0] + distance * Math.sin(polar) * Math.sin(azimuth),
    target[1] + distance * Math.cos(polar),
    target[2] + distance * Math.sin(polar) * Math.cos(azimuth),
  ];
}
