import { authFetch } from './auth';
import { parseRoute, routeToHash } from './route';
import type { Category, Family, Reference, Vehicle } from './types';

const title = (s: string): string => s.charAt(0) + s.slice(1).toLowerCase();

export function vehicleShortName(v: Vehicle): string | null {
  if (!v.make) return null;
  const model = (v.model ?? v.modelEn ?? '')
    .replace(new RegExp(`^${v.make}\\s*`, 'i'), '')
    .replace(/\(.*?\)/g, '')
    .trim()
    .split(/\s+/)[0];
  return [title(v.make), model && /^[A-Z]{2,}$/.test(model) ? title(model) : model].filter(Boolean).join(' ');
}

export const addLabel = (v: Vehicle): string => {
  const name = vehicleShortName(v);
  return name ? `Ajouter au ${name}` : 'Ajouter au véhicule';
};

const FILTER_TYPE = { oil: 'oil_filter', fuel: 'fuel_filter', air: 'air_filter' } as const;

export function maintenanceType(category: Category, family: Family): string {
  if (family.kind === 'filter') return FILTER_TYPE[family.filter];
  if (family.kind === 'brake') return 'brakes';
  return category.label
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_|_$/g, '');
}

export interface PlanPartLine {
  ref: string;
  brand: string;
  label: string;
  findRoute: string | null;
}

export interface PlanBody {
  make: string | null;
  model: string | null;
  carId: number | null;
  plate: string | null;
  vin: string | null;
  type: string;
  label: string;
  source: 'user';
  addedBy: 'finder';
  parts: PlanPartLine[];
  // After a 409: the garage vehicle IRI the user picked, or confirmNew for a car not listed.
  vehicle?: string;
  confirmNew?: boolean;
}

export function planBody(v: Vehicle, category: Category, family: Family, oe: Reference | null, equivalent: Reference | null, resultHash: string | null): PlanBody {
  const parts = [oe, equivalent]
    .filter((r): r is Reference => r !== null)
    .filter((r, i, all) => all.findIndex((o) => o.number === r.number) === i)
    .map((r) => ({ ref: r.number, brand: r.brand, label: category.label, findRoute: resultHash?.replace(/^#/, '') ?? null }));
  return {
    make: v.make,
    model: v.model ?? v.modelEn,
    carId: v.carId,
    plate: v.plate,
    vin: v.vin,
    type: maintenanceType(category, family),
    label: category.label,
    source: 'user',
    addedBy: 'finder',
    parts,
  };
}

// The garage page's sheet of a saved vehicle; an IRI without a ULID falls back to the list.
export const garageHref = (vehicleIri: string): string => routeToHash(parseRoute(`#/garage/${vehicleIri.split('/').pop() ?? ''}`));

export type PlanErrorKind = 'auth' | 'ambiguous' | 'invalid' | 'network' | 'failed';

export interface GarageCandidate {
  iri: string;
  plate: string | null;
  name: string | null;
}

export class PlanError extends Error {
  constructor(
    public readonly kind: PlanErrorKind,
    public readonly candidates: GarageCandidate[] = [],
  ) {
    super(kind);
  }
}

type Json = Record<string, unknown>;
const text = (v: unknown): string | null => (typeof v === 'string' && v.trim() !== '' ? v.trim() : null);

function candidatesOf(raw: unknown): GarageCandidate[] {
  const list = typeof raw === 'object' && raw !== null && Array.isArray((raw as Json).candidates) ? ((raw as Json).candidates as unknown[]) : [];
  return list.flatMap((c) => {
    const j = (typeof c === 'object' && c !== null ? c : {}) as Json;
    const iri = text(j['@id']);
    if (iri === null) return [];
    const shape: Vehicle = { plate: null, make: text(j.make), model: text(j.model), modelEn: null, version: null, engineCode: null, powerHp: null, firstRegistration: null, energy: null, vin: null, carId: null, carType: 'PC' };
    return [{ iri, plate: text(j.plate), name: vehicleShortName(shape) }];
  });
}

async function failure(res: Response): Promise<PlanError> {
  const kind = KIND[res.status] ?? 'failed';
  return new PlanError(kind, kind === 'ambiguous' ? candidatesOf(await res.json().catch(() => null)) : []);
}

export interface PlannedTask {
  task: string;
  vehicle: string;
}

const KIND: Record<number, PlanErrorKind> = { 401: 'auth', 403: 'auth', 409: 'ambiguous', 422: 'invalid' };

export async function planPart(body: PlanBody): Promise<PlannedTask> {
  let res: Response;
  try {
    res = await authFetch('/garage/planned-tasks', {
      method: 'POST',
      headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
      body: JSON.stringify(body),
    });
  } catch {
    throw new PlanError('network');
  }
  if (!res.ok) throw await failure(res);
  const json = (await res.json()) as { '@id'?: string; vehicle?: string };
  if (!json['@id'] || !json.vehicle) throw new PlanError('failed');
  return { task: json['@id'], vehicle: json.vehicle };
}

export interface RegistrationBody {
  vin?: string;
  firstRegistration?: string;
  cnit?: string;
  displacement?: string;
  powerKw?: string;
  energy?: string;
  fiscalPower?: string;
}

export interface SaveBody {
  make: string | null;
  model: string | null;
  carId: number | null;
  plate: string | null;
  vin: string | null;
  vehicle?: string;
  confirmNew?: boolean;
  // Carte grise fields, recorded as facts on the saved vehicle by the same call.
  registration?: RegistrationBody;
  // The car's code de fabrication (OPR): decoded server-side, recorded as organisation_number + build_date.
  buildCode?: string;
}

export const saveBody = (v: Vehicle): SaveBody => ({ make: v.make, model: v.model ?? v.modelEn, carId: v.carId, plate: v.plate, vin: v.vin });

export interface SavedVehicle {
  vehicle: string;
  created: boolean;
}

// POST /garage/vehicles/save: 201 created, 200 matched (same plate, or the picked IRI), 409 + candidates.
export async function saveVehicle(body: SaveBody): Promise<SavedVehicle> {
  let res: Response;
  try {
    res = await authFetch('/garage/vehicles/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
      body: JSON.stringify(body),
    });
  } catch {
    throw new PlanError('network');
  }
  if (!res.ok) throw await failure(res);
  const json = (await res.json()) as { '@id'?: string };
  if (!json['@id']) throw new PlanError('failed');
  return { vehicle: json['@id'], created: res.status === 201 };
}
