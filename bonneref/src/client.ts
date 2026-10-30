import { ApiError, classifyStatus, mapCategories, mapNarrowing, mapPhotoIntake, mapRegistration, mapSelection, mapVehicle, mapVin } from './api';
import { apiUrl } from './api-base';
import { authFetch } from './auth';
import { planPart, saveVehicle, type PlanBody, type PlannedTask, type SaveBody, type SavedVehicle } from './garage';
import { getHistory, getJournal, getJournalDetail, hideFromHistory, type JournalDetail, type JournalRow } from './journal';
import type { CarType, Category, Narrowing, PhotoIntake, Registration, StoredSelection, Vehicle, VinDecode } from './types';
import { vehicleEngine } from './ui/VehicleChip';

// A user-initiated submit (composer or chip click): which rule of the single input led to a catalogue
// read, the exact text typed, and one id shared by every request of that submit. Sent as headers so an
// authenticated search lands in the journal as one row (api/src/Finder/FinderSearchJournalListener.php);
// the URL, and so every cache in front of it, stays the same. Restores, hash loads, retries and
// background fetches carry no Trace and are never journaled.
export interface Trace {
  rule: string;
  input: string;
  searchId: string;
  plate?: string | null;
  vehicle?: Vehicle | null;
}

export const newTrace = (rule: string, input: string): Trace => ({ rule, input, searchId: crypto.randomUUID() });

// The vehicle exactly as its chip shows it, for the rows that only know a carId.
export const vehicleDescriptor = (v: Vehicle) => ({ plate: v.plate, carId: v.carId, carType: v.carType, make: v.make, model: v.modelEn ?? v.model, engine: vehicleEngine(v) || null });

export interface FinderApi {
  vehicle(plate: string, trace?: Trace): Promise<Vehicle>;
  vin(vin: string, trace?: Trace): Promise<VinDecode>;
  categories(carId: number, carType: CarType): Promise<Category[]>;
  car(carId: number, carType: CarType): Promise<unknown>;
  narrowed(carId: number, carType: CarType, categoryId: number, make: string | null, productGroupId: number | null, trace?: Trace): Promise<Narrowing>;
  selection(id: string): Promise<StoredSelection>;
  extractRegistration(file: Blob): Promise<Registration>;
  photoIntake(photo: Blob): Promise<PhotoIntake>;
  journal(): Promise<JournalRow[]>;
  journalEntry(id: string): Promise<JournalDetail>;
  history(): Promise<JournalRow[]>;
  hideFromHistory(id: string): Promise<void>;
  planPart(body: PlanBody): Promise<PlannedTask>;
  saveVehicle(body: SaveBody): Promise<SavedVehicle>;
}

const LD = { Accept: 'application/ld+json' };

export const traced = (trace?: Trace): RequestInit => ({
  headers: {
    ...LD,
    ...(trace ? { 'Search-Rule': trace.rule, 'Search-Input': encodeURIComponent(trace.input.slice(0, 200)), 'Search-Id': trace.searchId } : {}),
    ...(trace?.plate ? { 'Search-Plate': encodeURIComponent(trace.plate) } : {}),
    ...(trace?.vehicle ? { 'Search-Vehicle': encodeURIComponent(JSON.stringify(vehicleDescriptor(trace.vehicle))) } : {}),
  },
});

async function getJson(url: string, init: RequestInit = {}): Promise<unknown> {
  let res: Response;
  try {
    res = await authFetch(apiUrl(url), init);
  } catch {
    throw new ApiError('network');
  }
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new ApiError(/quota|limit/i.test(body) && res.status >= 500 ? 'quota' : classifyStatus(res.status));
  }
  return res.json();
}

// Public by id: a shared link is opened by someone else, so no bearer, and a stale one cannot 401 it.
async function getSelection(id: string): Promise<StoredSelection> {
  let res: Response;
  try {
    res = await fetch(apiUrl(`/agent/selections/${encodeURIComponent(id)}`), { headers: { Accept: 'application/json' } });
  } catch {
    throw new ApiError('network');
  }
  if (!res.ok) throw new ApiError(classifyStatus(res.status));
  return mapSelection(await res.json());
}

export function narrowedPath(carId: number, carType: CarType, categoryId: number, make: string | null, productGroupId: number | null): string {
  const q = new URLSearchParams({ carType });
  if (make) q.set('make', make);
  if (productGroupId !== null) q.set('productGroupId', String(productGroupId));
  return `/cars/${carId}/part-categories/${categoryId}/narrowed?${q}`;
}

export const liveApi: FinderApi = {
  vehicle: async (plate, trace) => mapVehicle(await getJson(`/vehicles/${encodeURIComponent(plate.replace(/[\s-]/g, ''))}`, traced(trace))),
  vin: async (vin, trace) => mapVin(await getJson(`/vins/${encodeURIComponent(vin)}`, traced(trace))),
  categories: async (carId, carType) =>
    mapCategories(await getJson(`/cars/${carId}/part-categories?carType=${carType}`, { headers: LD })),
  car: (carId, carType) => getJson(`/cars/${carId}/identity?carType=${carType}`, { headers: LD }),
  narrowed: async (carId, carType, categoryId, make, productGroupId, trace) => mapNarrowing(await getJson(narrowedPath(carId, carType, categoryId, make, productGroupId), traced(trace))),
  selection: (id) => getSelection(id),
  extractRegistration: async (file) => {
    const body = new FormData();
    body.append('file', file);
    return mapRegistration(await getJson('/registration-documents/extract', { method: 'POST', body, headers: { Accept: 'application/json' } }));
  },
  photoIntake: async (photo) => {
    const body = new FormData();
    body.append('file', photo, 'photo.jpg');
    return mapPhotoIntake(await getJson('/photo-intake', { method: 'POST', body, headers: { Accept: 'application/json' } }));
  },
  journal: getJournal,
  journalEntry: getJournalDetail,
  history: getHistory,
  hideFromHistory,
  planPart,
  saveVehicle,
};
