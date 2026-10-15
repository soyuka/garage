import { ApiError, classifyStatus, mapCategories, mapNarrowing, mapRegistration, mapVehicle, mapVin } from './api';
import { authFetch } from './auth';
import type { CarType, Category, Narrowing, Registration, Vehicle, VinDecode } from './types';

export interface FinderApi {
  vehicle(plate: string): Promise<Vehicle>;
  vin(vin: string): Promise<VinDecode>;
  categories(carId: number, carType: CarType): Promise<Category[]>;
  narrowed(carId: number, carType: CarType, categoryId: number, make: string | null): Promise<Narrowing>;
  extractRegistration(file: File): Promise<Registration>;
}

const LD = { Accept: 'application/ld+json' };

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
  vehicle: async (plate) => mapVehicle(await getJson(`/vehicles/${encodeURIComponent(plate)}`, { headers: LD })),
  vin: async (vin) => mapVin(await getJson(`/vins/${encodeURIComponent(vin)}`, { headers: LD })),
  categories: async (carId, carType) =>
    mapCategories(await getJson(`/cars/${carId}/part-categories?carType=${carType}`, { headers: LD })),
  narrowed: async (carId, carType, categoryId, make) => {
    const q = new URLSearchParams({ carType });
    if (make) q.set('make', make);
    return mapNarrowing(await getJson(`/cars/${carId}/part-categories/${categoryId}/narrowed?${q}`, { headers: LD }));
  },
  extractRegistration: async (file) => {
    const body = new FormData();
    body.append('file', file);
    return mapRegistration(await getJson('/registration-documents/extract', { method: 'POST', body, headers: { Accept: 'application/json' } }));
  },
};

// `?demo` serves recorded responses (XB-301-ZB, oil filter 100259) so UI work never spends the
// catalogue's daily test quota; any other narrowing in demo mode answers as an exhausted quota.
async function demoApi(): Promise<FinderApi> {
  const [vehicle, vin, categories, narrowed] = await Promise.all([
    import('./fixtures/vehicle.json'),
    import('./fixtures/vin.json'),
    import('./fixtures/categories.json'),
    import('./fixtures/narrowed.json'),
  ]);
  const later = <T,>(value: T, ms = 450): Promise<T> => new Promise((r) => setTimeout(() => r(value), ms));
  return {
    vehicle: (plate) => (plate === 'XB-301-ZB' ? later(mapVehicle(vehicle.default)) : Promise.reject(new ApiError('notFound'))),
    vin: (v) => later({ ...mapVin(vin.default), vin: v }),
    categories: () => later(mapCategories(categories.default)),
    narrowed: (_c, _t, categoryId) =>
      categoryId === 100259 ? later(mapNarrowing(narrowed.default), 1100) : Promise.reject(new ApiError('quota')),
    extractRegistration: () =>
      later({
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
      }, 1400),
  };
}

export const isDemo = (): boolean => new URLSearchParams(window.location.search).has('demo');

export const loadApi = (): Promise<FinderApi> => (isDemo() ? demoApi() : Promise.resolve(live));
