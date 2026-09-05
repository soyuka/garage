import { DIMS, type BodyClass } from './CarProxy';
import { regionAnchors, regionForType, type BodyRegion } from './anchors';
import type { VehicleFact } from '../types';

// The fact constellation (CHOREOGRAPHY § "Stations — data given a body", "Data — the fact
// constellation"). Mirrors visitRing.ts file-for-file: pure layout core, no r3f here.

export type FactRegion = BodyRegion | 'unplaced';

/** First node's height above the anchor, and the per-node step above that (metres). */
export const FIRST_OFFSET = 0.35;
export const STEP = 0.22;

/** Max nodes in one column before a region spills sideways into a second column. */
export const COLUMN_MAX = 8;
/** Lateral gap (metres, along the car's z axis) between a region's columns. */
export const COLUMN_GAP = 0.3;

// The curated prefix table (CHOREOGRAPHY § "Data — the fact constellation", "placement"). `rear_` is
// checked FIRST: `rear_brake_pads` starts with `rear_`, not `brake_`, so the rear override never
// competes with the front-axle prefixes below it.
const PREFIX_REGION: ReadonlyArray<readonly [string, BodyRegion]> = [
  ['rear_', 'rearAxle'],
  ['oil_', 'bay'],
  ['coolant_', 'bay'],
  ['engine_', 'bay'],
  ['battery', 'bay'],
  ['belt', 'bay'],
  ['spark', 'bay'],
  ['gearbox_', 'bay'],
  ['transmission_', 'bay'],
  ['power_steering', 'bay'],
  ['ac_', 'bay'],
  ['refrigerant', 'bay'],
  ['fuel_', 'bay'],
  ['alternator', 'bay'],
  ['starter', 'bay'],
  ['brake_', 'frontAxle'],
  ['tyre_', 'frontAxle'],
  ['wheel', 'frontAxle'],
  ['wiper', 'windshield'],
  ['windshield', 'windshield'],
];

/**
 * Where a `VehicleFact` stands on the car. Derived, never stored: a `<type>_ref` key reuses the
 * service-type map ({@see regionForType}); everything else goes through the curated prefix table
 * above. A key no rule places is `'unplaced'` — the station never guesses a location.
 */
export function factRegion(key: string): FactRegion {
  if (key.endsWith('_ref')) return regionForType(key.slice(0, -'_ref'.length));
  for (const [prefix, region] of PREFIX_REGION) {
    if (key.startsWith(prefix)) return region;
  }
  return 'unplaced';
}

// roofY mirrors CarProxy.tsx's own cabin-top math (bodyH + cabinH) — not the anchors.ts cabinTop
// (which also folds in wheelR and a -0.06 trim): the unplaced anchor only needs to read as "above the
// car", not to sit exactly on the modelled roof line. Moto has no DIMS entry, so it borrows its own
// highest anchor (windshield) as the roof reference instead.
function roofY(bodyClass: BodyClass): number {
  if (bodyClass === 'moto') return regionAnchors('moto').windshield[1];
  const d = DIMS[bodyClass];
  return d.bodyH + d.cabinH;
}

/** The sixth anchor, standing above the roof centre — where an unplaced fact's column rises from. */
export function unplacedAnchor(bodyClass: BodyClass): [number, number, number] {
  return [0, roofY(bodyClass) + 0.5, 0];
}

export interface FactNode {
  key: string;
  index: number;
  region: FactRegion;
  position: [number, number, number];
  anchor: [number, number, number];
  /** This node's column's own ground point — the leader's foot (§ "body"). Equal to `anchor` with
   * its z nudged by the column's lateral offset; the same point for every node in one column. */
  foot: [number, number, number];
  verified: boolean;
  /** True for the lowest node in its column — the one leader line's target (§ "body"). */
  first: boolean;
}

/**
 * Every `VehicleFact` placed as a node standing over the part of the car it describes. Facts sharing a
 * region stack in columns of at most `COLUMN_MAX` above that region's anchor, in the order they
 * arrived (grouping never reorders); the first node in a column sits `FIRST_OFFSET` above the anchor,
 * each next one `STEP` higher. A region needing more than one column spills sideways along the car's z
 * axis, columns centred on the anchor (`n` — and so the column count — is only known after grouping,
 * hence the two passes below).
 */
export function factConstellationLayout(facts: VehicleFact[], bodyClass: BodyClass): FactNode[] {
  const anchors = regionAnchors(bodyClass);
  const unplaced = unplacedAnchor(bodyClass);

  const regionCount = new Map<FactRegion, number>();
  for (const fact of facts) {
    const region = factRegion(fact.key);
    regionCount.set(region, (regionCount.get(region) ?? 0) + 1);
  }

  const regionIndex = new Map<FactRegion, number>();
  return facts.map((fact, index) => {
    const region = factRegion(fact.key);
    const anchor = region === 'unplaced' ? unplaced : anchors[region];
    const i = regionIndex.get(region) ?? 0;
    regionIndex.set(region, i + 1);

    const n = regionCount.get(region) ?? 1;
    const cols = Math.ceil(n / COLUMN_MAX);
    const c = Math.floor(i / COLUMN_MAX);
    const r = i % COLUMN_MAX;
    const offset = cols > 1 ? (c - (cols - 1) / 2) * COLUMN_GAP : 0;

    const foot: [number, number, number] = [anchor[0], anchor[1], anchor[2] + offset];
    return {
      key: fact.key,
      index,
      region,
      position: [anchor[0], anchor[1] + FIRST_OFFSET + r * STEP, anchor[2] + offset],
      anchor: [...anchor] as [number, number, number],
      foot,
      verified: fact.verified,
      first: r === 0,
    };
  });
}
