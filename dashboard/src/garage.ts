import type { VehicleSummary } from './types';

const API = import.meta.env.VITE_API_BASE ?? '';

/** GET one garage vehicle by its IRI — the item read inlines its facts (the listing does not). */
export async function getVehicle(iri: string): Promise<VehicleSummary> {
  const res = await fetch(`${API}${iri}`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`vehicle fetch failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/** Editable-card write: JSON Merge Patch of the vehicle's own scalars (usage reading, plate). */
export async function patchVehicle(
  iri: string,
  patch: { usageValue?: number; usageUnit?: string; plate?: string | null },
): Promise<VehicleSummary> {
  const res = await fetch(`${API}${iri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/merge-patch+json', Accept: 'application/ld+json' },
    body: JSON.stringify(patch),
  });
  if (!res.ok) throw new Error(`vehicle update failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/** Tick a planned task off the checklist: PATCH its status (the only writable field). */
export async function patchTask(iri: string, status: 'open' | 'done' | 'dismissed'): Promise<void> {
  const res = await fetch(`${API}${iri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/merge-patch+json', Accept: 'application/ld+json' },
    body: JSON.stringify({ status }),
  });
  if (!res.ok) throw new Error(`task update failed: ${res.status}`);
}
