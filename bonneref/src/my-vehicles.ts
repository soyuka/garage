import { garageUrl } from './api-base';
import { authFetch } from './auth';
import { garageCarType, vehicleShortName } from './garage';
import { carRef } from './route';
import type { AgentVehicle, CarType } from './types';

// The owner's saved cars (GET /garage/vehicles, api/src/ApiResource/GarageVehicle.php) that the
// catalogue can be scoped to: a plate or a kType. A lawn tractor without either cannot be opened here.
export interface MyVehicle {
  iri: string;
  plate: string | null;
  name: string | null;
  carId: number | null;
  carType: CarType;
  make: string | null;
  model: string | null;
  engine: string | null;
}

type Json = Record<string, unknown>;
const obj = (v: unknown): Json => (typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Json) : {});
const str = (v: unknown): string | null => (typeof v === 'string' && v.trim() !== '' ? v.trim() : null);
const int = (v: unknown): number | null => (typeof v === 'number' && Number.isInteger(v) && v > 0 ? v : null);

export function mapMyVehicles(raw: unknown): MyVehicle[] {
  const rows = Array.isArray(raw) ? raw : (obj(raw).member ?? obj(raw)['hydra:member']);
  return (Array.isArray(rows) ? rows : []).flatMap((r) => {
    const j = obj(r);
    const iri = str(j['@id']);
    const plate = str(j.plate);
    const carId = int(j.kType);
    if (iri === null || (plate === null && carId === null)) return [];
    const make = str(j.make);
    const model = str(j.model);
    const engine = [str(j.engineVariant), str(j.engineCode)].filter(Boolean).join(' · ') || null;
    const name = vehicleShortName({ plate, make, model, modelEn: null, version: null, engineCode: null, powerHp: null, firstRegistration: null, energy: null, vin: null, carId, carType: 'PC' });
    return [{ iri, plate, name, carId, carType: garageCarType(j.carType, j.vehicleType), make, model, engine }];
  });
}

// Where a pick leads: its plate route, else its catalogue car, whose identity the tab keeps.
export function pickTarget(v: MyVehicle): { ref: string; car: AgentVehicle | null } {
  if (v.plate !== null || v.carId === null) return { ref: v.plate ?? '', car: null };
  return { ref: carRef(v.carId, v.carType), car: { carId: v.carId, carType: v.carType, make: v.make, model: v.model, engine: v.engine, plate: null } };
}

export async function listMyVehicles(): Promise<MyVehicle[]> {
  try {
    const res = await authFetch(garageUrl('/garage/vehicles'), { headers: { Accept: 'application/ld+json' } });
    return res.ok ? mapMyVehicles(await res.json()) : [];
  } catch {
    return [];
  }
}
