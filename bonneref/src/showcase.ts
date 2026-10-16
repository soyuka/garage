import { mapCategories, mapNarrowing } from './api';
import { partFamily } from './family';
import { vehicleShortName } from './garage';
import { cotesLine, pickOe, rankEquivalents } from './result';
import snapshot from './showcase.json';
import type { Dimension, Family, Reference, Variant, Vehicle } from './types';

// The public home's example: one real cached catalogue answer, stored with the page so a visitor's view
// never spends a catalogue call. Plate and VIN are left out of the snapshot (the home shows no plate).
export interface Showcase {
  label: string;
  vehicle: string;
  total: number;
  oe: Reference | null;
  equivalents: Variant[];
  otherBrands: number;
  cotes: string | null;
  family: Family;
  dimensions: Dimension[];
}

function build(): Showcase {
  const [category] = mapCategories({ member: [snapshot.category] });
  const narrowing = mapNarrowing(snapshot.narrowed);
  const cluster = narrowing.clusters[0]!;
  const v = snapshot.vehicle;
  const vehicle: Vehicle = { plate: null, vin: null, make: v.make, model: v.model, modelEn: v.modelEn, version: v.version, engineCode: v.engineCode, powerHp: v.powerHp, firstRegistration: v.firstRegistration, energy: v.energy, carId: v.carId, carType: 'PC' };
  const eq = rankEquivalents(cluster, 4);
  return {
    label: category!.label,
    vehicle: [vehicleShortName(vehicle), v.version].filter(Boolean).join(' '),
    total: narrowing.totalArticles,
    oe: pickOe(cluster, v.make),
    equivalents: eq.shown,
    otherBrands: eq.otherBrands,
    cotes: cotesLine(cluster.dimensions),
    family: partFamily(category!, cluster.dimensions),
    dimensions: cluster.dimensions,
  };
}

export const SHOWCASE: Showcase = build();
