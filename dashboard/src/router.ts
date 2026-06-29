// Minimal hash-based dev router. The OAuth callback (Stage C) owns the real pathname (/callback), so
// dev routes live in the hash — they never collide with auth's path-based redirect handling. This is
// the seam Stage A's Le Noyau playground (and later stage playgrounds) plug into.

import { useSyncExternalStore } from 'react';

export interface DevRoute {
  path: string; // hash path, leading slash, no trailing slash (except root "/")
  title: string;
}

/** Normalize a `window.location.hash` to a bare route path ("/", "/noyau"). */
export function parseHash(hash: string): string {
  const raw = hash.replace(/^#/, '').split(/[?#]/, 1)[0];
  if (raw === '' || raw === '/') return '/';
  return raw.replace(/\/+$/, '');
}

/** The registered route whose path equals the parsed hash, or undefined for an unknown path. */
export function matchRoute(hash: string, routes: DevRoute[]): DevRoute | undefined {
  const path = parseHash(hash);
  return routes.find((r) => r.path === path);
}

// ── Live vehicle selection route (#/v/<seg>) ──────────────────────────────────
// The selected vehicle rides the hash so a reload restores it. `seg` is the IRI's last segment (the
// bare ULID) — short and stable; the orchestrator resolves it back to the full `@id` against the
// loaded collection (auth owns the pathname; selection owns the hash). Matches the dashboard-url memory.

/** The IRI's last path segment — the short, URL-friendly vehicle handle. */
export function segForIri(iri: string): string {
  return iri.split('/').filter(Boolean).pop() ?? '';
}

/** The selection hash for a vehicle IRI. */
export function hashForVehicle(iri: string): string {
  return `#/v/${segForIri(iri)}`;
}

/** The selected vehicle seg if the parsed path is the `/v/<seg>` route, else null (the parc landing). */
export function vehicleSegFromHash(path: string): string | null {
  const m = /^\/v\/(.+)$/.exec(path);
  return m ? m[1] : null;
}

/** Subscribe a component to the current hash route path; re-renders on hashchange. */
export function useHashPath(): string {
  return useSyncExternalStore(
    (cb) => {
      window.addEventListener('hashchange', cb);
      return () => window.removeEventListener('hashchange', cb);
    },
    () => parseHash(window.location.hash),
    () => '/',
  );
}
