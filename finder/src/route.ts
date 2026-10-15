import { normaliseFni, normalisePlate } from './format';
import type { CarType } from './types';

// A vehicle ref is either a plate ("XB-301-ZB", "1234 AB 56") or a catalogue car the agent identified
// without one ("k19107-PC").
export type Route =
  | { view: 'entry' }
  | { view: 'vehicle'; ref: string }
  | { view: 'result'; ref: string; categoryId: number; clusterId: string | null };

export type VehicleRef = { kind: 'plate'; plate: string } | { kind: 'car'; carId: number; carType: CarType };

const CAR = /^k([1-9]\d*)-(PC|CV)$/;

export const carRef = (carId: number, carType: CarType): string => `k${carId}-${carType}`;

export function parseRef(ref: string): VehicleRef | null {
  const car = CAR.exec(ref);
  if (car) return { kind: 'car', carId: Number(car[1]), carType: car[2] as CarType };
  const plate = normalisePlate(ref) ?? normaliseFni(ref);
  return plate ? { kind: 'plate', plate } : null;
}

export function parseRoute(hash: string): Route {
  const parts = hash.replace(/^#\/?/, '').split('/').filter(Boolean).map(decodeURIComponent);
  if ((parts[0] !== 'p' && parts[0] !== 'k') || parts[1] === undefined) return { view: 'entry' };
  const ref = parts[0] === 'k' ? (CAR.test(`k${parts[1]}`) ? `k${parts[1]}` : null) : (normalisePlate(parts[1]) ?? normaliseFni(parts[1]));
  if (ref === null) return { view: 'entry' };
  if (parts[2] !== 'c' || !/^\d+$/.test(parts[3] ?? '')) return { view: 'vehicle', ref };
  const clusterId = parts[4] === 'k' && parts[5] ? parts[5] : null;
  return { view: 'result', ref, categoryId: Number(parts[3]), clusterId };
}

const refHash = (ref: string): string => (CAR.test(ref) ? `#/k/${ref.slice(1)}` : `#/p/${encodeURIComponent(ref.replace(/ /g, ''))}`);

export function routeToHash(route: Route): string {
  switch (route.view) {
    case 'entry':
      return '#/';
    case 'vehicle':
      return refHash(route.ref);
    case 'result':
      return `${refHash(route.ref)}/c/${route.categoryId}${route.clusterId ? `/k/${encodeURIComponent(route.clusterId)}` : ''}`;
  }
}
