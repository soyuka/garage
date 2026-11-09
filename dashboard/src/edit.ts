// Pure coercion for the hero inline-edit (#9 vehicle CRUD). A click-to-edit field hands back a raw
// draft string; these turn it into the typed value the vehicle merge-PATCH expects. Re-authored fresh
// from the old app's commitInt/commitUsage contract (strip non-digits; empty clears a nullable field).

/**
 * Parse an integer from a draft, stripping every non-digit first (so a thin-space-grouped reading
 * like "142 000" or a unit-suffixed "2018 km" coerces cleanly). An empty/blank draft → null, which
 * clears a nullable scalar (year/kType). A draft with no digits at all → null (never a NaN write).
 */
export function coerceInt(raw: string): number | null {
  const digits = raw.replace(/[^\d]/g, '');
  if (digits === '') return null;
  const n = parseInt(digits, 10);
  return Number.isNaN(n) ? null : n;
}

/** A blank draft clears an optional text field (→ null); otherwise the trimmed value. */
export function emptyToNull(raw: string): string | null {
  const trimmed = raw.trim();
  return trimmed === '' ? null : trimmed;
}

/**
 * The unit to write when a vehicle has no usageUnit yet: engine-hours for groundscare machinery
 * (mowers/generators meter `h`, not distance), else kilometers. Garage-side enum spelling.
 */
export function defaultUsageUnit(vehicleType: string | null | undefined): 'km' | 'h' {
  return vehicleType === 'groundscare' ? 'h' : 'km';
}

/**
 * The vehicle-edit usage units. CRITICAL: the Vehicle resource binds App\Garage\UsageUnit, whose
 * hours case is spelled `h` — the maintenance-side DTOs spell it `hours`. The vehicle merge-PATCH
 * MUST send the garage spelling (`h`) or the write is rejected.
 */
export const VEHICLE_USAGE_UNITS = ['km', 'mi', 'h'] as const;
export type VehicleUsageUnit = (typeof VEHICLE_USAGE_UNITS)[number];
