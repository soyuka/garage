import { vehicleSegFromHash } from './router';

/** The fixture-driven playgrounds (devRoutes.tsx); `/dev/workshop` also owns its sub-paths. */
export const DEV_PATHS = ['/dev', '/noyau', '/atelier', '/scene', '/parc', '/auth', '/dev/workshop'] as const;

export type AppRoute = 'workshop' | 'legacy' | 'dev' | 'notFound';

/** What a hash path renders. Playgrounds exist only in a dev build: production never routes to them. */
export function appRoute(path: string, dev: boolean): AppRoute {
  if (path === '/' || vehicleSegFromHash(path) !== null) return 'workshop';
  if (path === '/legacy') return 'legacy';
  if (dev && (DEV_PATHS.some((p) => p === path) || path.startsWith('/dev/workshop'))) return 'dev';
  return 'notFound';
}
