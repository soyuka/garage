import type { BodyRegion } from './anchors';
import type { Locale } from '../i18n/locale';
import { messages, type Messages } from '../i18n/messages';
import { canonicalService } from '../labels';
import { MOTION } from '../tokens/motion';

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
  | 'disc' // stands on the axle, axis along z (corrected 2026-09-05: it was drawn flat since C3, CHOREOGRAPHY § The reveal)
  | 'line' // a thin run: a circuit, a hose, a pipe
  | 'node'; // a small marker: a plug, a sensor, a bleed point

/** The disc primitive's rotation: a CylinderGeometry's axis is y; a brake disc's is the axle (z). */
export const DISC_UPRIGHT: readonly [number, number, number] = [Math.PI / 2, 0, 0];

/** The generic meshes that may stand in for a primitive — file stems under public/parts/. Generic means a
 * mesh of the part's KIND: never a vehicle-specific one (engine-v6.glb is refused, see SOURCES.md) and never
 * a state (spark-plug-worn.glb). */
export type PartMesh = 'oil-filter' | 'spark-plug' | 'battery' | 'brake-disc' | 'engine-block';
export interface PartMeshSpec {
  file: PartMesh;
  /** yaw so the source's axis matches car-local space (+x = front, z = the axle). */
  rotationY?: number;
  /** pitch so the source's long axis stands vertical (the spark plug lies along z on disk; a plug
   * stands in its socket, so it stands here too — CHOREOGRAPHY § The reveal). */
  rotationX?: number;
}

export interface AssemblyPart {
  /** the service/part key this stands for — the same vocabulary `regionsForKeys` reads. */
  key: string;
  /** resolved from the diagram's `labelKey` against the interface language. */
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
  /** the generic mesh that stands in for `shape`, if the part's kind has one (`PART_MESHES`); absent →
   * the primitive draws. */
  mesh?: PartMeshSpec;
}

// The spec carries a label KEY, not a label: the diagram is geometry plus identity, and what those
// identities are CALLED belongs to the catalog like every other caption.
type PartSpec = Omit<AssemblyPart, 'highlighted' | 'label'> & { labelKey: keyof Messages['assembly'] };

// Each diagram is read at the region's anchor, so the offsets are small — tens of centimetres. They
// describe a LAYOUT (what sits next to what, what connects to what), never a measurement.
const DIAGRAMS: Record<BodyRegion, PartSpec[]> = {
  hood: [
    { key: 'engine', labelKey: 'engineBlock', shape: 'housing', offset: [0, -0.12, 0], size: 1.116 },
    { key: 'oil_filter', labelKey: 'oilFilter', shape: 'canister', offset: [-0.18, -0.16, 0.26], size: 0.135 },
    { key: 'oil_change', labelKey: 'oilCircuit', shape: 'line', offset: [-0.05, -0.24, 0.16], size: 0.792 },
    { key: 'spark_plug', labelKey: 'sparkPlugs', shape: 'node', offset: [0.14, 0.06, -0.12], size: 0.072 },
    { key: 'coolant', labelKey: 'coolantCircuit', shape: 'line', offset: [0.2, -0.04, 0.2], size: 0.648 },
  ],
  bay: [
    { key: 'air_filter', labelKey: 'airFilterHousing', shape: 'housing', offset: [0.1, 0.02, -0.22], size: 0.54 },
    { key: 'fuel_filter', labelKey: 'fuelFilter', shape: 'canister', offset: [-0.12, -0.14, 0.24], size: 0.126 },
    { key: 'fuel_line', labelKey: 'fuelLine', shape: 'line', offset: [-0.02, -0.16, 0.12], size: 0.9 },
    { key: 'battery', labelKey: 'battery', shape: 'housing', offset: [0.06, 0.04, 0.3], size: 0.432 },
    { key: 'cabin_filter', labelKey: 'cabinFilter', shape: 'canister', offset: [-0.3, 0.06, -0.06], size: 0.108 },
  ],
  windshield: [
    { key: 'inspection', labelKey: 'cockpit', shape: 'housing', offset: [0, -0.1, 0], size: 0.72 },
    { key: 'wipers', labelKey: 'wiperLinkage', shape: 'line', offset: [0.12, -0.02, 0], size: 0.9 },
    { key: 'cabin_filter', labelKey: 'cabinFilter', shape: 'canister', offset: [0.16, -0.14, 0.2], size: 0.108 },
  ],
  frontAxle: [
    { key: 'brake_discs', labelKey: 'brakeDisc', shape: 'disc', offset: [0, 0, 0.02], size: 0.306 },
    { key: 'brake_pads', labelKey: 'brakePads', shape: 'housing', offset: [-0.1, 0.09, 0.04], size: 0.216 },
    { key: 'brake_fluid', labelKey: 'brakeHose', shape: 'line', offset: [-0.06, 0.16, 0.0], size: 0.468 },
    { key: 'tyres', labelKey: 'tyre', shape: 'node', offset: [0, 0, 0.16], size: 0.09 },
  ],
  rearAxle: [
    { key: 'brake_discs', labelKey: 'rearDisc', shape: 'disc', offset: [0, 0, 0.02], size: 0.27 },
    { key: 'brake_pads', labelKey: 'rearPads', shape: 'housing', offset: [0.09, 0.08, 0.04], size: 0.198 },
    { key: 'brake_fluid', labelKey: 'brakeHose', shape: 'line', offset: [0.06, 0.15, 0.0], size: 0.432 },
  ],
};

// The one table (exported; keys are DIAGRAMS keys) mapping a part's KIND to a generic mesh that stands in
// for its primitive (CHOREOGRAPHY § The reveal, 2026-09-04 — corrected 2026-09-05, the fit rule).
export const PART_MESHES: Readonly<Partial<Record<string, PartMeshSpec>>> = {
  oil_filter: { file: 'oil-filter' },
  spark_plug: { file: 'spark-plug', rotationX: Math.PI / 2 }, // source lies along z; stood up to sit in its socket
  battery: { file: 'battery' },
  brake_discs: { file: 'brake-disc', rotationY: Math.PI / 2 }, // source disc axis lies along x; the axle is z
  engine: { file: 'engine-block' },
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
export function assemblyFor(region: BodyRegion, locale: Locale, keys: string[] = []): AssemblyPart[] {
  const labels = messages(locale).assembly;
  const wanted = new Set(keys.map(canonicalService));

  return DIAGRAMS[region].map(({ labelKey, ...p }) => {
    const label = labels[labelKey];
    const highlighted = wanted.has(canonicalService(p.key));
    const mesh = PART_MESHES[p.key];
    return { ...p, label, highlighted, ...(mesh ? { mesh } : {}) };
  });
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

/** The url of the generic mesh a part stands in with, if it has one. */
export function partMeshUrl(part: Pick<AssemblyPart, 'mesh'>): string | undefined {
  return part.mesh ? `/parts/${part.mesh.file}.glb` : undefined;
}

export type Extent = readonly [number, number, number];

/** The primitive's bounding box [x, y, z] — what a stand-in mesh is fitted to (CHOREOGRAPHY § The reveal,
 * correction 2026-09-05). Mirrors PartGeometry in Assembly.tsx; the disc is the UPRIGHT disc (DISC_UPRIGHT). */
export function schematicBox(shape: AssemblyShape, size: number): Extent {
  switch (shape) {
    case 'canister':
      return [size * 2, size * 2.4, size * 2];
    case 'disc':
      return [size * 2, size * 2, size * 0.16];
    case 'housing':
      return [size, size * 0.62, size * 0.78];
    case 'line':
      return [size, 0.012, 0.012];
    case 'node':
      return [size * 2, size * 2, size * 2];
  }
}

/** Fit a buildBlueprint output (floor at y=0, x/z centred) into the primitive's box: the mesh's LONGEST axis
 * is scaled to the box's extent along THAT axis (never to the box's own longest — a tall block under a long
 * housing is fitted to the housing's height), plus the y lift that puts the mesh's centre on the primitive's. */
export function meshFit(extent: Extent, box: Extent): { scale: number; lift: number } {
  let axis = 0;
  for (let i = 1; i < 3; i++) if (extent[i] > extent[axis]) axis = i;
  const scale = extent[axis] > 0 ? box[axis] / extent[axis] : 1;
  return { scale, lift: -(extent[1] * scale) / 2 };
}

export const ASSEMBLY_RISE = MOTION.rise / 1000; // moved from Assembly.tsx `RISE`
export const RISE_FROM = -0.07; // moved from Assembly.tsx

/** Rise progress for the part in slot `delay` at diagram clock `clock`: 0 before its slot, 1 once landed. */
export function riseT(clock: number, delay: number): number {
  return Math.min(1, Math.max(0, (clock - delay) / ASSEMBLY_RISE));
}
