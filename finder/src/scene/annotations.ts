import { findDim, dimText, threadOf } from '../dims';
import { NNBSP } from '../format';
import type { Dimension } from '../types';

// Every label drawn on the part comes from the cluster fingerprint: a cote the catalogue does not
// carry is not drawn at all, never estimated from the mesh.
export interface FilterAnnotations {
  diameter: string | null;
  height: string | null;
  thread: string | null;
}

export function filterAnnotations(dims: readonly Dimension[]): FilterAnnotations {
  const outer = findDim(dims, 'outer');
  const height = findDim(dims, 'height');
  return {
    diameter: outer ? `Ø${NNBSP}${dimText(outer)}` : null,
    height: height ? dimText(height) : null,
    thread: threadOf(dims),
  };
}

export type BrakeComponent = 'disc' | 'other';

export interface BrakeAnnotations {
  diameter: string | null;
  thickness: string | null;
}

// Only a disc's cotes belong on the disc mesh: a pad's thickness drawn there would point at the wrong part.
export function brakeAnnotations(dims: readonly Dimension[], component: BrakeComponent): BrakeAnnotations {
  if (component !== 'disc') return { diameter: null, thickness: null };
  const outer = findDim(dims, 'outer');
  const thickness = findDim(dims, 'thickness');
  return {
    diameter: outer ? `Ø${NNBSP}${dimText(outer)}` : null,
    thickness: thickness ? `ép.${NNBSP}${dimText(thickness)}` : null,
  };
}
