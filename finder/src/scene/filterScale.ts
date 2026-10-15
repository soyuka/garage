import type { Dimension } from '../types';

export interface Size {
  x: number;
  y: number;
  z: number;
}

export interface FilterScale extends Size {
  height: number | null;
  diameter: number | null;
}

const measure = (dims: readonly Dimension[], re: RegExp): number | null =>
  dims.find((d) => !d.info && re.test(d.key) && d.number !== null && d.number > 0)?.number ?? null;

const ratio = (target: number | null, measured: number): number =>
  target !== null && measured > 0 ? target / measured : 1;

export function filterScale(meshSizeMm: Size, dims: readonly Dimension[]): FilterScale {
  const height = measure(dims, /^hauteur(_1)?$/);
  const diameter = measure(dims, /^diametre(_exterieur|_1)?$/);
  const radial = ratio(diameter, Math.max(meshSizeMm.x, meshSizeMm.z));
  return { x: radial, y: ratio(height, meshSizeMm.y), z: radial, height, diameter };
}
