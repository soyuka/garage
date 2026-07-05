// The engine-hours usage unit is spelled differently on the two axes: the garage vehicle PATCH uses
// the short `h`, while the maintenance record/schedule axis uses `hours`. Both crossings of that
// divergence live here so the mapping is declared once (see PROVENANCE — a known cross-stack hazard).

/**
 * The maintenance-side usage units. CRITICAL: records + the service-schedule bind the MAINTENANCE
 * UsageUnit (km|mi|hours) — hours is `hours` here, NOT the garage `h` the vehicle PATCH uses.
 */
export const MAINT_USAGE_UNITS = ['km', 'mi', 'hours'] as const;

/** Map a vehicle's garage-side usageUnit (`h`) to the maintenance spelling (`hours`) for the record/schedule forms. */
export function maintUnitFor(vehicleUsageUnit: string | null | undefined): string {
  return vehicleUsageUnit === 'h' ? 'hours' : vehicleUsageUnit === 'mi' ? 'mi' : 'km';
}

/** Engine-hours is spelled `hours` on the maintenance axis but reads better as the short `h` in a cadence. */
export function cadenceUnit(unit: string): string {
  return unit === 'hours' ? 'h' : unit;
}
