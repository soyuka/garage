import { factRegion, type FactRegion } from './factConstellation';
import type { VehicleFact } from '../types';

// The legend groups like the body (CHOREOGRAPHY § "The legend is grouped like the body"): the fiche
// lists facts by region, in anchor order, each group keeping the facts' arrival order — the same
// order the constellation stacks them, lowest first. Pure grouping, no r3f here (mirrors
// factConstellation.ts's own split of layout math from rendering).

/** Anchor order the legend groups follow: capot, pare-brise, baie moteur, train avant, train arrière,
 * sans emplacement. */
export const REGION_ORDER: FactRegion[] = ['hood', 'windshield', 'bay', 'frontAxle', 'rearAxle', 'unplaced'];

/**
 * Facts bucketed by `factRegion`, in `REGION_ORDER`; a region with no facts is omitted rather than
 * rendered empty. Arrival order is kept inside each group — grouping never reorders.
 */
export function groupFactsByRegion(facts: VehicleFact[]): Array<{ region: FactRegion; facts: VehicleFact[] }> {
  const buckets = new Map<FactRegion, VehicleFact[]>();
  for (const fact of facts) {
    const region = factRegion(fact.key);
    const bucket = buckets.get(region);
    if (bucket) bucket.push(fact);
    else buckets.set(region, [fact]);
  }
  return REGION_ORDER.filter((region) => buckets.has(region)).map((region) => ({
    region,
    facts: buckets.get(region)!,
  }));
}
