import type { BodyRegion } from './anchors';
import { canonicalService } from '../labels';

// ─────────────────────────────────────────────────────────────────────────────
// C3 — the schematic assembly. The pure core: what a reveal DRAWS under the ghosted shell.
//
// These are AUTHORED primitives placed in the region's anchor space, not geometry pulled out of the
// glb — and that is the honest move, not a shortcut. `public/mazda6-gh-web.glb` is one node and one
// mesh (no hood, no door, no engine), so there is nothing to extract; and even if there were, a
// diagram that claimed to be THIS car's fuel-filter circuit would be a claim the data cannot support.
// A blueprint of a fuel-filter circuit is true of the class of thing and reads as a diagram, which is
// what it is. It also generalises across body classes for free, which extracted geometry never would.
//
// Coordinate space: OFFSETS from the region's anchor (anchors.ts), car-local metres, +x = front.
// ─────────────────────────────────────────────────────────────────────────────

/** The primitive vocabulary. Five shapes, because a diagram with a shape per part is a parts catalogue. */
export type AssemblyShape =
  | 'canister' // a cylinder: filters, the things you unscrew and replace
  | 'housing' // a box: the block, an airbox, a battery, a caliper
  | 'disc' // a flat cylinder seen as a disc: brake discs
  | 'line' // a thin run: a circuit, a hose, a pipe
  | 'node'; // a small marker: a plug, a sensor, a bleed point

export interface AssemblyPart {
  /** the service/part key this stands for — the same vocabulary `regionsForKeys` reads. */
  key: string;
  label: string;
  shape: AssemblyShape;
  /** offset from the region anchor, car-local metres. */
  offset: [number, number, number];
  /** the primitive's main dimension in metres (length for a line, radius for a disc/canister). Sized to
   * READ at the reveal framing, not to match the real component — this is a schematic, and a
   * to-scale 70 mm filter beside a 4.3 m car is a dot nobody can see. */
  size: number;
  /** named by the reveal's `partKeys` — drawn in the operator hue instead of blueprint edge. */
  highlighted: boolean;
}

type PartSpec = Omit<AssemblyPart, 'highlighted'>;

// Each diagram is read at the region's anchor, so the offsets are small — tens of centimetres. They
// describe a LAYOUT (what sits next to what, what connects to what), never a measurement.
const DIAGRAMS: Record<BodyRegion, PartSpec[]> = {
  hood: [
    { key: 'engine', label: 'Bloc moteur', shape: 'housing', offset: [0, -0.12, 0], size: 1.116 },
    { key: 'oil_filter', label: 'Filtre à huile', shape: 'canister', offset: [-0.18, -0.16, 0.26], size: 0.135 },
    { key: 'oil_change', label: 'Circuit d’huile', shape: 'line', offset: [-0.05, -0.24, 0.16], size: 0.792 },
    { key: 'spark_plug', label: 'Bougies', shape: 'node', offset: [0.14, 0.06, -0.12], size: 0.072 },
    { key: 'coolant', label: 'Circuit de refroidissement', shape: 'line', offset: [0.2, -0.04, 0.2], size: 0.648 },
  ],
  bay: [
    { key: 'air_filter', label: 'Boîtier de filtre à air', shape: 'housing', offset: [0.1, 0.02, -0.22], size: 0.54 },
    { key: 'fuel_filter', label: 'Filtre à carburant', shape: 'canister', offset: [-0.12, -0.14, 0.24], size: 0.126 },
    { key: 'fuel_line', label: 'Ligne carburant', shape: 'line', offset: [-0.02, -0.16, 0.12], size: 0.9 },
    { key: 'battery', label: 'Batterie', shape: 'housing', offset: [0.06, 0.04, 0.3], size: 0.432 },
    { key: 'cabin_filter', label: 'Filtre d’habitacle', shape: 'canister', offset: [-0.3, 0.06, -0.06], size: 0.108 },
  ],
  windshield: [
    { key: 'inspection', label: 'Poste de conduite', shape: 'housing', offset: [0, -0.1, 0], size: 0.72 },
    { key: 'wipers', label: 'Tringlerie d’essuie-glace', shape: 'line', offset: [0.12, -0.02, 0], size: 0.9 },
    { key: 'cabin_filter', label: 'Filtre d’habitacle', shape: 'canister', offset: [0.16, -0.14, 0.2], size: 0.108 },
  ],
  frontAxle: [
    { key: 'brake_discs', label: 'Disque de frein', shape: 'disc', offset: [0, 0, 0.02], size: 0.306 },
    { key: 'brake_pads', label: 'Plaquettes', shape: 'housing', offset: [-0.1, 0.09, 0.04], size: 0.216 },
    { key: 'brake_fluid', label: 'Flexible de frein', shape: 'line', offset: [-0.06, 0.16, 0.0], size: 0.468 },
    { key: 'tyres', label: 'Pneumatique', shape: 'node', offset: [0, 0, 0.16], size: 0.09 },
  ],
  rearAxle: [
    { key: 'brake_discs', label: 'Disque arrière', shape: 'disc', offset: [0, 0, 0.02], size: 0.27 },
    { key: 'brake_pads', label: 'Plaquettes arrière', shape: 'housing', offset: [0.09, 0.08, 0.04], size: 0.198 },
    { key: 'brake_fluid', label: 'Flexible de frein', shape: 'line', offset: [0.06, 0.15, 0.0], size: 0.432 },
  ],
};

/**
 * The diagram for a region, with the parts named by `keys` marked. Every part of the region is always
 * drawn — the circuit is the answer to "where does this sit", and a lone floating filter answers
 * nothing. `keys` selects what is CALLED OUT, not what exists, which is the same relationship
 * `focusView.keys` has with the rail.
 *
 * Keys fold through {@see canonicalService} so a spelling variant off the wire still lands, and an
 * unknown key simply highlights nothing rather than erroring — same best-effort contract as
 * `regionForFrame`.
 */
export function assemblyFor(region: BodyRegion, keys: string[] = []): AssemblyPart[] {
  const wanted = new Set(keys.map(canonicalService));
  return DIAGRAMS[region].map((p) => ({ ...p, highlighted: wanted.has(canonicalService(p.key)) }));
}

/** Does this region have a diagram worth revealing? Total — every region does today, but a caller
 * must not assume that, and a reveal with nothing to draw should decline rather than ghost the car
 * to show an empty bay. */
export function hasAssembly(region: BodyRegion): boolean {
  return (DIAGRAMS[region]?.length ?? 0) > 0;
}

/** How many parts a region's reveal will land — the denominator the ghost's depth tracks. */
export function assemblySize(region: BodyRegion): number {
  return DIAGRAMS[region]?.length ?? 0;
}

// The rise stagger, same shape as the rail's: parts unfurl one after another so the eye can follow the
// circuit being drawn, but the ramp is CAPPED so the last part of a five-part diagram is not late.
export const ASSEMBLY_STAGGER = 0.07; // seconds between parts
export const ASSEMBLY_STAGGER_CAP = 4; // steps; past this every part shares the last slot

export function assemblyDelay(index: number): number {
  return Math.min(index, ASSEMBLY_STAGGER_CAP) * ASSEMBLY_STAGGER;
}
