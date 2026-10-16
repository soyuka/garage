import { normaliseFni, normalisePlate } from './format';
import type { CarType } from './types';

// A vehicle ref is either a plate ("XB-301-ZB", "1234 AB 56") or a catalogue car the agent identified
// without one ("k19107-PC").
// `answer`: settled by the cote question (`/k/<id>`, the only form before 2026-10-16); `user`: picked
// in the variant table (`/v/<id>`), the user's decision, never presented as confirmed; `agent`: retained
// by the agent from what the user gave (`/r/<id>/<basis>/<setAside>`), an indication resting on
// per-article data. A pick after the agent's verdict keeps it (`/v/<id>/r/<id>/<basis>/<setAside>`).
export interface Verdict {
  id: string;
  basis: string;
  setAside: string;
}

export type ClusterChoice =
  | { id: string; by: 'answer' }
  | { id: string; by: 'user'; verdict?: Verdict }
  | { id: string; by: 'agent'; basis: string; setAside?: string };

export const BASIS_MAX = 160;

export type Route =
  | { view: 'entry' }
  | { view: 'vehicle'; ref: string }
  | { view: 'result'; ref: string; categoryId: number; productGroupId: number | null; cluster: ClusterChoice | null };

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
  const rest = parts.slice(4);
  let productGroupId: number | null = null;
  if (rest[0] === 'g') {
    productGroupId = /^[1-9]\d*$/.test(rest[1] ?? '') ? Number(rest[1]) : null;
    rest.splice(0, 2);
  }
  return { view: 'result', ref, categoryId: Number(parts[3]), productGroupId, cluster: parseChoice(rest) };
}

const text = (raw: string | undefined): string => (raw ?? '').trim().slice(0, BASIS_MAX);

function parseChoice(rest: string[]): ClusterChoice | null {
  const id = rest[1];
  if (!id) return null;
  if (rest[0] === 'k') return { id, by: 'answer' };
  if (rest[0] === 'r') {
    const setAside = text(rest[3]);
    return { id, by: 'agent', basis: text(rest[2]), ...(setAside ? { setAside } : {}) };
  }
  if (rest[0] !== 'v') return null;
  const verdict = rest[2] === 'r' ? parseChoice(rest.slice(2)) : null;
  return verdict?.by === 'agent' ? { id, by: 'user', verdict: { id: verdict.id, basis: verdict.basis, setAside: verdict.setAside ?? '' } } : { id, by: 'user' };
}

const refHash = (ref: string): string => (CAR.test(ref) ? `#/k/${ref.slice(1)}` : `#/p/${encodeURIComponent(ref.replace(/ /g, ''))}`);

function clusterHash(c: ClusterChoice): string {
  const id = encodeURIComponent(c.id);
  if (c.by === 'agent') {
    const tail = c.setAside ? [c.basis, c.setAside] : c.basis ? [c.basis] : [];
    return `/r/${id}${tail.map((t) => `/${encodeURIComponent(t)}`).join('')}`;
  }
  if (c.by === 'user') return `/v/${id}${c.verdict ? clusterHash({ id: c.verdict.id, by: 'agent', basis: c.verdict.basis, setAside: c.verdict.setAside }) : ''}`;
  return `/k/${id}`;
}

export function routeToHash(route: Route): string {
  switch (route.view) {
    case 'entry':
      return '#/';
    case 'vehicle':
      return refHash(route.ref);
    case 'result':
      return `${refHash(route.ref)}/c/${route.categoryId}${route.productGroupId ? `/g/${route.productGroupId}` : ''}${route.cluster ? clusterHash(route.cluster) : ''}`;
  }
}
