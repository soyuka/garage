import { authFetch } from './auth';
import type { Category, Family, Reference, Vehicle } from './types';

const title = (s: string): string => s.charAt(0) + s.slice(1).toLowerCase();

export function vehicleShortName(v: Vehicle): string | null {
  if (!v.make) return null;
  const model = (v.model ?? v.modelEn ?? '')
    .replace(new RegExp(`^${v.make}\\s*`, 'i'), '')
    .replace(/\(.*?\)/g, '')
    .trim()
    .split(/\s+/)[0];
  return [title(v.make), model].filter(Boolean).join(' ');
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
}

export function planBody(v: Vehicle, category: Category, family: Family, oe: Reference | null, equivalent: Reference | null): PlanBody {
  const parts = [oe, equivalent]
    .filter((r): r is Reference => r !== null)
    .filter((r, i, all) => all.findIndex((o) => o.number === r.number) === i)
    .map((r) => ({ ref: r.number, brand: r.brand, label: category.label }));
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

export const atelierHref = (vehicleIri: string, origin: string): string => `${origin}/#/v/${vehicleIri.split('/').pop() ?? ''}`;

export type PlanErrorKind = 'auth' | 'ambiguous' | 'invalid' | 'network' | 'failed';

export class PlanError extends Error {
  constructor(public readonly kind: PlanErrorKind) {
    super(kind);
  }
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
  if (!res.ok) throw new PlanError(KIND[res.status] ?? 'failed');
  const json = (await res.json()) as { '@id'?: string; vehicle?: string };
  if (!json['@id'] || !json.vehicle) throw new PlanError('failed');
  return { task: json['@id'], vehicle: json.vehicle };
}
