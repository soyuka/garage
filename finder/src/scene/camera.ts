export type Vec3 = readonly [number, number, number];

export interface Pose {
  position: Vec3;
  target: Vec3;
}

export const FOV = 22;
export const HOME = { azimuth: 0.62, polar: 1.12 } as const;
export const POLAR_MARGIN = 0.05;
export const RESET_S = 0.45;

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

export const resetPose = (target: Vec3, distance: number): Pose => ({
  target,
  position: orbitPosition(target, distance, HOME.azimuth, HOME.polar),
});

export const easeOut = (t: number): number => 1 - (1 - Math.min(Math.max(t, 0), 1)) ** 3;

const mix = (a: Vec3, b: Vec3, k: number): Vec3 => [a[0] + (b[0] - a[0]) * k, a[1] + (b[1] - a[1]) * k, a[2] + (b[2] - a[2]) * k];

export const lerpPose = (from: Pose, to: Pose, t: number): Pose => {
  const k = easeOut(t);
  return { position: mix(from.position, to.position, k), target: mix(from.target, to.target, k) };
};
