import type { PlannedTask, VehicleSummary } from './types';

const API = import.meta.env.VITE_API_BASE ?? '';

/** GET one garage vehicle by its IRI — the item read inlines its facts (the listing does not). */
export async function getVehicle(iri: string): Promise<VehicleSummary> {
  const res = await fetch(`${API}${iri}`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`vehicle fetch failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/**
 * GET a vehicle's open checklist — the planned-tasks subresource (a hydra collection of PlannedTask).
 * The API serves each task's IRI as `@id`; we normalize it to `id` so the UI keys/tick-off it like
 * facts do.
 */
export async function getVehicleTasks(vehicleIri: string): Promise<PlannedTask[]> {
  const res = await fetch(`${API}${vehicleIri}/planned-tasks`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`tasks fetch failed: ${res.status}`);
  const data = (await res.json()) as { member?: RawTask[]; 'hydra:member'?: RawTask[] };
  const members = data.member ?? data['hydra:member'] ?? [];
  return members.map((t) => ({ ...t, id: t.id ?? t['@id'] }) as PlannedTask);
}

type RawTask = PlannedTask & { '@id'?: string };

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

/**
 * Record a manually-entered spec on a vehicle: POST one {key, value, unit?} to its facts
 * subresource. Lands as a source "user" (unverified) fact; returns the fresh vehicle with facts.
 */
export async function postVehicleFact(
  iri: string,
  fact: { key: string; value: string; unit?: string | null },
): Promise<VehicleSummary> {
  const res = await fetch(`${API}${iri}/facts`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    body: JSON.stringify(fact),
  });
  if (!res.ok) throw new Error(`fact write failed: ${res.status}`);
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
