import { normalisePlate } from './format';

export type Route =
  | { view: 'entry' }
  | { view: 'vehicle'; plate: string }
  | { view: 'result'; plate: string; categoryId: number; clusterId: string | null };

export function parseRoute(hash: string): Route {
  const parts = hash.replace(/^#\/?/, '').split('/').filter(Boolean).map(decodeURIComponent);
  if (parts[0] !== 'p' || parts[1] === undefined) return { view: 'entry' };
  const plate = normalisePlate(parts[1]);
  if (plate === null) return { view: 'entry' };
  if (parts[2] !== 'c' || !/^\d+$/.test(parts[3] ?? '')) return { view: 'vehicle', plate };
  const clusterId = parts[4] === 'k' && parts[5] ? parts[5] : null;
  return { view: 'result', plate, categoryId: Number(parts[3]), clusterId };
}

export function routeToHash(route: Route): string {
  switch (route.view) {
    case 'entry':
      return '#/';
    case 'vehicle':
      return `#/p/${route.plate}`;
    case 'result':
      return `#/p/${route.plate}/c/${route.categoryId}${route.clusterId ? `/k/${encodeURIComponent(route.clusterId)}` : ''}`;
  }
}
