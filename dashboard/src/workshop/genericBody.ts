import { resolveMesh, type MeshSpec } from '../scene/vehicleMesh';
import type { VehicleSummary } from '../types';

type Body = Pick<VehicleSummary, 'make' | 'model' | 'vehicleType'>;

function fold(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().replace(/\s+/g, ' ').trim();
}

/**
 * Real registrations arrive as "MAZDA" / "MAZDA6" or "RENAULT" / "ESPACE IV": fold case and accents, and
 * drop the make when the model repeats it, so the mesh table sees "mazda" / "6".
 */
export function normalizeBody(v: Body): Body {
  const make = fold(v.make ?? '');
  let model = fold(v.model ?? '');
  if (make && model.startsWith(make)) model = model.slice(make.length).replace(/^[\s-]+/, '');
  return { make, model, vehicleType: v.vehicleType };
}

/** The mesh a vehicle draws with, robust to the spellings real data uses. */
export function vehicleMesh(v: Body): MeshSpec {
  return resolveMesh(normalizeBody(v));
}

/** The vehicle is drawn with its type's stand-in body, not a mesh of its own model. */
export function isGenericBody(v: Body): boolean {
  const n = normalizeBody(v);
  const own = resolveMesh(n).url;
  const standIn = resolveMesh({ vehicleType: v.vehicleType, make: '', model: '' }).url;
  if (own !== standIn) return false;
  // The car stand-in is itself a Mazda 6: for that one car it is the real body.
  return !(n.make === 'mazda' && /^6\b/.test(n.model));
}

/** The low-detail mesh to draw. */
export function parcThumbUrl(v: Body): string {
  return vehicleMesh(v).thumbUrl;
}

/** The name to show: the owner's own casing, the make once even when the model repeats it ("MAZDA" + "MAZDA6" → "MAZDA 6"). */
export function displayName(v: Pick<VehicleSummary, 'make' | 'model'>): string {
  const make = (v.make ?? '').trim();
  let model = (v.model ?? '').trim();
  const target = fold(make);
  if (target) {
    let cut = 0;
    while (cut < model.length && fold(model.slice(0, cut)).length < target.length) cut++;
    if (fold(model.slice(0, cut)) === target) model = model.slice(cut).replace(/^[\s-]+/, '');
  }
  return [make, model].filter(Boolean).join(' ');
}
