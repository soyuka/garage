import { ApiError, classifyStatus } from './api';
import { authFetch } from './auth';
import { formatDate, formatMeasure, formatValue } from './format';
import { factLabel, sentence, serviceLabel, sourceLabel, unitLabel } from './garage-labels';
import type { MyVehicle } from './my-vehicles';
import type { CarType } from './types';

// The owner's garage as bonneref shows it (api/src/ApiResource/GarageVehicle.php, PlannedTask.php):
// identity, facts and planned parts. The maintenance forecast (health, nextDue) belongs to the private
// product and is never read here.
export interface GarageRow {
  id: string;
  iri: string;
  plate: string | null;
  vin: string | null;
  make: string | null;
  model: string | null;
  name: string | null;
  year: number | null;
  engineVariant: string | null;
  engineCode: string | null;
  vehicleType: string | null;
  carId: number | null;
  carType: CarType;
  usage: { value: number; unit: string } | null;
  addedAt: string | null;
}

export interface GarageFact {
  key: string;
  label: string;
  // Printed as is: numbers French-grouped, a bare date in words, the unit after a thin space.
  value: string;
  source: string | null;
  url: string | null;
}

export interface GarageSheet {
  vehicle: GarageRow;
  facts: GarageFact[];
}

export interface PlannedPart {
  ref: string;
  brand: string | null;
  label: string | null;
}

export interface PlannedItem {
  id: string;
  label: string;
  parts: PlannedPart[];
}

type Json = Record<string, unknown>;
const obj = (v: unknown): Json => (typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Json) : {});
const str = (v: unknown): string | null => (typeof v === 'string' && v.trim() !== '' ? v.trim() : null);
const int = (v: unknown): number | null => (typeof v === 'number' && Number.isInteger(v) && v > 0 ? v : null);
const num = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null);
const members = (raw: unknown): unknown[] => {
  const rows = Array.isArray(raw) ? raw : (obj(raw).member ?? obj(raw)['hydra:member']);
  return Array.isArray(rows) ? rows : [];
};

const titleCase = (s: string): string => (s === s.toUpperCase() ? s.charAt(0) + s.slice(1).toLowerCase() : s);

export function garageName(make: string | null, model: string | null): string | null {
  const m = model && make ? model.replace(new RegExp(`^${make.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*`, 'i'), '').trim() : model;
  return [make ? titleCase(make) : null, m || null].filter(Boolean).join(' ') || null;
}

function mapRow(raw: unknown): GarageRow | null {
  const j = obj(raw);
  const iri = str(j['@id']);
  const id = str(j.id) ?? iri?.split('/').pop() ?? null;
  if (iri === null || id === null) return null;
  const make = str(j.make);
  const model = str(j.model);
  const usageValue = num(j.usageValue);
  const usageUnit = str(j.usageUnit);
  return {
    id,
    iri,
    plate: str(j.plate),
    vin: str(j.vin),
    make,
    model,
    name: garageName(make, model),
    year: int(j.year),
    engineVariant: str(j.engineVariant),
    engineCode: str(j.engineCode),
    vehicleType: str(j.vehicleType),
    carId: int(j.kType),
    carType: j.vehicleType === 'cv' ? 'CV' : 'PC',
    usage: usageValue !== null && usageUnit !== null ? { value: usageValue, unit: usageUnit } : null,
    addedAt: str(j.addedAt),
  };
}

export const mapGarageVehicles = (raw: unknown): GarageRow[] => members(raw).flatMap((r) => mapRow(r) ?? []);

const DAY = /^\d{4}-\d{2}-\d{2}$/;

// The catalogue joins alternative figures with « | » (« 3 | 3.25 »): each one goes through the formatter.
const measure = (value: string, unit: string): string => {
  const parts = value.split(/\s*\|\s*/);
  return parts.length > 1 ? formatMeasure(parts.map(formatValue).join(' · '), unit) : formatMeasure(value, unit);
};

function mapFact(raw: unknown): GarageFact | null {
  const j = obj(raw);
  const key = str(j.key);
  const value = str(j.value);
  if (key === null || value === null) return null;
  const unit = str(j.unit);
  const source = str(j.source);
  return {
    key,
    label: factLabel(key),
    // Without a unit a number is an identifier (OPR 09824, DAM): printed verbatim, never regrouped.
    value: DAY.test(value) ? formatDate(value) : unit ? measure(value, unitLabel(unit)) : value,
    source: source ? sourceLabel(source) : null,
    url: str(j.url),
  };
}

export function mapGarageSheet(raw: unknown): GarageSheet {
  const vehicle = mapRow(raw);
  if (vehicle === null) throw new ApiError('invalid');
  const facts = Array.isArray(obj(raw).facts) ? (obj(raw).facts as unknown[]) : [];
  return { vehicle, facts: facts.flatMap((f) => mapFact(f) ?? []) };
}

// Open tasks only: a done task is maintenance history, which stays in the private product.
export function mapPlannedParts(raw: unknown): PlannedItem[] {
  return members(raw).flatMap((r) => {
    const j = obj(r);
    const id = str(j.id);
    if (id === null || j.status !== 'open') return [];
    const label = str(j.label);
    const type = str(j.type);
    const parts = (Array.isArray(j.parts) ? j.parts : []).flatMap((p) => {
      const part = obj(p);
      const ref = str(part.ref);
      return ref === null ? [] : [{ ref, brand: str(part.brand), label: str(part.label) }];
    });
    return [{ id, label: label ? sentence(label) : type ? serviceLabel(type) : 'Pièces prévues', parts }];
  });
}

// A garage car opened in the search the way a Mes véhicules pick is: by plate, else by kType.
export function myVehicleOf(row: GarageRow): MyVehicle | null {
  if (row.plate === null && row.carId === null) return null;
  const engine = [row.engineVariant, row.engineCode].filter(Boolean).join(' · ') || null;
  return { iri: row.iri, plate: row.plate, name: row.name, carId: row.carId, carType: row.carType, make: row.make, model: row.model, engine };
}

async function request(path: string, init: RequestInit = {}): Promise<Response> {
  let res: Response;
  try {
    res = await authFetch(path, { ...init, headers: { Accept: 'application/ld+json', ...init.headers } });
  } catch {
    throw new ApiError('network');
  }
  if (!res.ok) throw new ApiError(classifyStatus(res.status));
  return res;
}

const vehiclePath = (id: string): string => `/garage/vehicles/${encodeURIComponent(id)}`;

export const listGarageVehicles = async (): Promise<GarageRow[]> => mapGarageVehicles(await (await request('/garage/vehicles')).json());

export const getGarageVehicle = async (id: string): Promise<GarageSheet> => mapGarageSheet(await (await request(vehiclePath(id))).json());

export const listPlannedParts = async (id: string): Promise<PlannedItem[]> => mapPlannedParts(await (await request(`${vehiclePath(id)}/planned-tasks`)).json());

export async function deleteGarageVehicle(id: string): Promise<void> {
  await request(vehiclePath(id), { method: 'DELETE' });
}

export async function deleteFact(id: string, key: string): Promise<void> {
  await request(`${vehiclePath(id)}/facts/${encodeURIComponent(key)}`, { method: 'DELETE' });
}
