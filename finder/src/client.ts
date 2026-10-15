import { ApiError, classifyStatus, mapCategories, mapNarrowing, mapPhotoIntake, mapRegistration, mapVehicle, mapVin } from './api';
import { authFetch } from './auth';
import { planPart, type PlanBody, type PlannedTask } from './garage';
import { seedRecent } from './recent';
import { getJournal, getJournalDetail, mapDetail, mapRows, type JournalDetail, type JournalRow } from './journal';
import type { CarType, Category, Narrowing, PhotoIntake, Registration, Vehicle, VinDecode } from './types';

// Which rule of the single input led to a catalogue read. Sent as headers so an authenticated
// search lands in the journal (api/src/Finder/FinderSearchJournalListener.php); the URL, and so
// every cache in front of it, stays the same.
export interface Trace {
  rule: string;
  input: string;
}

export interface FinderApi {
  vehicle(plate: string, trace?: Trace): Promise<Vehicle>;
  vin(vin: string, trace?: Trace): Promise<VinDecode>;
  categories(carId: number, carType: CarType): Promise<Category[]>;
  narrowed(carId: number, carType: CarType, categoryId: number, make: string | null, trace?: Trace): Promise<Narrowing>;
  extractRegistration(file: Blob): Promise<Registration>;
  photoIntake(photo: Blob): Promise<PhotoIntake>;
  journal(): Promise<JournalRow[]>;
  journalEntry(id: string): Promise<JournalDetail>;
  planPart(body: PlanBody): Promise<PlannedTask>;
}

const LD = { Accept: 'application/ld+json' };

const traced = (trace?: Trace): RequestInit => ({
  headers: { ...LD, ...(trace ? { 'X-Finder-Rule': trace.rule, 'X-Finder-Input': encodeURIComponent(trace.input.slice(0, 200)) } : {}) },
});

async function getJson(url: string, init: RequestInit = {}): Promise<unknown> {
  let res: Response;
  try {
    res = await authFetch(url, init);
  } catch {
    throw new ApiError('network');
  }
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new ApiError(/quota|limit/i.test(body) && res.status >= 500 ? 'quota' : classifyStatus(res.status));
  }
  return res.json();
}

const live: FinderApi = {
  vehicle: async (plate, trace) => mapVehicle(await getJson(`/vehicles/${encodeURIComponent(plate.replace(/[\s-]/g, ''))}`, traced(trace))),
  vin: async (vin, trace) => mapVin(await getJson(`/vins/${encodeURIComponent(vin)}`, traced(trace))),
  categories: async (carId, carType) =>
    mapCategories(await getJson(`/cars/${carId}/part-categories?carType=${carType}`, { headers: LD })),
  narrowed: async (carId, carType, categoryId, make, trace) => {
    const q = new URLSearchParams({ carType });
    if (make) q.set('make', make);
    return mapNarrowing(await getJson(`/cars/${carId}/part-categories/${categoryId}/narrowed?${q}`, traced(trace)));
  },
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
  planPart,
};

// `?demo` serves recorded responses (XB-301-ZB, oil filter 100259) so UI work never spends the
// catalogue's daily test quota; any other narrowing in demo mode answers as an exhausted quota.
async function demoApi(): Promise<FinderApi> {
  const [vehicle, vin, categories, narrowed, journal] = await Promise.all([
    import('./fixtures/vehicle.json'),
    import('./fixtures/vin.json'),
    import('./fixtures/categories.json'),
    import('./fixtures/narrowed.json'),
    import('./fixtures/journal.json'),
  ]);
  const registration: Registration = {
    plate: 'XB-301-ZB',
    vin: 'JMZGG14F671528417',
    legible: true,
    warnings: [],
    fields: [
      { code: 'A', label: 'Immatriculation', value: 'XB-301-ZB' },
      { code: 'B', label: 'Première immatriculation', value: '29/03/2007' },
      { code: 'D.1', label: 'Marque', value: 'MAZDA' },
      { code: 'D.3', label: 'Dénomination commerciale', value: 'MAZDA6' },
      { code: 'E', label: 'Numéro VIN', value: 'JMZGG14F671528417' },
      { code: 'P.3', label: 'Énergie', value: 'ES' },
    ],
  };
  seedRecent('XB-301-ZB', [
    { categoryId: 100032, label: 'Disque de frein' },
    { categoryId: 100151, label: 'Bougie d’allumage' },
    { categoryId: 100260, label: 'Filtre à air' },
  ]);
  const later = <T,>(value: T, ms = 450): Promise<T> => new Promise((r) => setTimeout(() => r(value), ms));
  return {
    vehicle: (plate) => (plate === 'XB-301-ZB' ? later(mapVehicle(vehicle.default)) : Promise.reject(new ApiError('notFound'))),
    vin: (v) => later({ ...mapVin(vin.default), vin: v }),
    categories: () => later(mapCategories(categories.default)),
    narrowed: (_c, _t, categoryId) =>
      categoryId === 100259 ? later(mapNarrowing(narrowed.default), 1100) : Promise.reject(new ApiError('quota')),
    extractRegistration: () => later(registration, 1400),
    photoIntake: () => later({ kind: 'carte_grise', registration } as const, 1400),
    journal: () => later(mapRows(journal.default.list), 300),
    journalEntry: (id) => {
      const entry = (journal.default.entries as Record<string, unknown>)[id];
      return entry ? later(mapDetail(entry), 300) : Promise.reject(new ApiError('notFound'));
    },
    planPart: () => later({ task: '/garage/planned-tasks/01DEMOTASK', vehicle: '/garage/vehicles/01DEMOVEHICLE' }, 500),
  };
}

export const isDemo = (): boolean => new URLSearchParams(window.location.search).has('demo');

export const loadApi = (): Promise<FinderApi> => (isDemo() ? demoApi() : Promise.resolve(live));
