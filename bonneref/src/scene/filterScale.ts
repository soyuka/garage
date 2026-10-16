import { measure, type Measure } from '../dims';
import type { Dimension } from '../types';

export interface Size {
  x: number;
  y: number;
  z: number;
}

export interface FilterScale extends Size {
  height: Measure | null;
  diameter: Measure | null;
}

const ratio = (target: Measure | null, measured: number): number => (target !== null && measured > 0 ? target.mid / measured : 1);

export function filterScale(meshSizeMm: Size, dims: readonly Dimension[]): FilterScale {
  const height = measure(dims, 'height');
  const diameter = measure(dims, 'outer');
  const radial = ratio(diameter, Math.max(meshSizeMm.x, meshSizeMm.z));
  return { x: radial, y: ratio(height, meshSizeMm.y), z: radial, height, diameter };
}
