import { serviceLabel } from './labels';
import type { MaintenanceRecord } from './types';

// Pure helpers for the maintenance-records surface (#11). Money is integer minor units on the wire
// (costCents/priceCents); the manual form takes euros, so the client converts both ways. Rebuilt fresh.

/**
 * Parse a euro amount typed by hand into a major-unit number. The maintenance WRITE shape takes euros
 * under `cost`/`price` (the backend converts to costCents/priceCents — verified against the API's own
 * functional tests); the READ shape returns cents. Accepts a comma OR dot decimal (fr-FR), strips
 * grouping whitespace + a currency symbol, rounds to the nearest cent. Empty/blank/non-numeric → null.
 */
export function parseEuros(input: string): number | null {
  const cleaned = input.replace(/\s/g, '').replace(',', '.').replace(/[^\d.]/g, '');
  if (cleaned === '' || cleaned === '.') return null;
  const euros = Number.parseFloat(cleaned);
  return Number.isNaN(euros) ? null : Math.round(euros * 100) / 100;
}

/** Cents → an editable comma-decimal euro string for prefilling the form (1250 → "12,50"). */
export function centsToEurosInput(cents: number | null | undefined): string {
  if (cents == null) return '';
  return (cents / 100).toFixed(2).replace('.', ',');
}

/**
 * The maintenance-side usage units. CRITICAL: records + the service-schedule bind the MAINTENANCE
 * UsageUnit (km|mi|hours) — hours is `hours` here, NOT the garage `h` the vehicle PATCH uses. See PROVENANCE.
 */
export const MAINT_USAGE_UNITS = ['km', 'mi', 'hours'] as const;

/** Map a vehicle's garage-side usageUnit (`h`) to the maintenance spelling (`hours`) for the record/schedule forms. */
export function maintUnitFor(vehicleUsageUnit: string | null | undefined): string {
  return vehicleUsageUnit === 'h' ? 'hours' : vehicleUsageUnit === 'mi' ? 'mi' : 'km';
}

/** A visit's headline: its label, else a single service's label, else a generic word, else a dash. */
export function visitTitle(record: MaintenanceRecord): string {
  if (record.label && record.label.trim()) return record.label.trim();
  if (record.items.length === 1) return serviceLabel(record.items[0].type);
  if (record.items.length > 1) return 'Entretien';
  return '—';
}
