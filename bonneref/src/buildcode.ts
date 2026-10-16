import { normalisePlate } from './format';
import type { MyVehicle } from './my-vehicles';
import { carRef } from './route';
import type { BuildCode, CarType } from './types';

// The car's decoded code de fabrication, kept for the tab per car (plate, else catalogue ref): a fact of
// the car, not of one result, so every later turn and result on that car carries it.
const KEY = 'bonneref.buildCodes';
const OPR = /^\d{4,5}$/;
const DAY = /^\d{4}-\d{2}-\d{2}$/;

interface CarIdentity {
  plate: string | null;
  carId: number | null;
  carType: CarType;
}

export const vehicleKey = (v: CarIdentity): string | null => (v.plate ? (normalisePlate(v.plate) ?? v.plate.toUpperCase()) : v.carId ? carRef(v.carId, v.carType) : null);

const MONTH = new Intl.DateTimeFormat('fr-FR', { month: 'short', year: 'numeric', timeZone: 'UTC' });

export const buildCodeLabel = (c: BuildCode): string => `OPR ${c.organisationNumber} · ${MONTH.format(new Date(`${c.date}T00:00:00Z`))}`;

const valid = (c: unknown): c is BuildCode =>
  typeof c === 'object' && c !== null && OPR.test(String((c as BuildCode).organisationNumber)) && DAY.test(String((c as BuildCode).date));

function read(): Record<string, unknown> {
  try {
    const parsed: unknown = JSON.parse(sessionStorage.getItem(KEY) ?? '{}');
    return typeof parsed === 'object' && parsed !== null ? (parsed as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

export function buildCodeFor(v: CarIdentity): BuildCode | null {
  const key = vehicleKey(v);
  const stored = key ? read()[key] : null;
  return valid(stored) ? { organisationNumber: stored.organisationNumber, date: stored.date } : null;
}

export function rememberBuildCode(v: CarIdentity, code: BuildCode): void {
  const key = vehicleKey(v);
  if (!key) return;
  try {
    sessionStorage.setItem(KEY, JSON.stringify({ ...read(), [key]: code }));
  } catch {
    /* the chip still shows it until the tab reloads */
  }
}

// The garage row this car already is, so its code lands there as a fact: same plate, or same kType when
// neither carries a plate. A car the user never saved stays out of the garage.
export function garageMatch(mine: readonly MyVehicle[], v: CarIdentity): string | null {
  const plate = vehicleKey({ ...v, carId: null });
  const hit = plate ? mine.find((m) => m.plate !== null && vehicleKey({ ...m, carId: null }) === plate) : mine.find((m) => m.plate === null && m.carId !== null && m.carId === v.carId);
  return hit?.iri ?? null;
}
