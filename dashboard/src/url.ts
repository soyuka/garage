import { vehicleSegment } from './recency';

// Hash, not path, because auth.ts owns window.location.pathname (OAuth callback + replaceState to '/').
const HASH_RE = /^#?\/v\/([^/]+)$/;

export function parseVehicleHash(hash: string): string | null {
  return HASH_RE.exec(hash)?.[1] ?? null;
}

export function formatVehicleHash(iri: string): string {
  return `#/v/${vehicleSegment(iri)}`;
}

export function writeVehicleHash(iri: string): void {
  // replaceState, not pushState — reload restoration only, no back-button stepping per selection.
  window.history.replaceState(null, '', formatVehicleHash(iri));
}
