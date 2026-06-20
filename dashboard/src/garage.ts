import { authFetch } from './auth';
import type { PlannedPart, PlannedTask, VehicleSummary } from './types';

const API = import.meta.env.VITE_API_BASE ?? '';

/** GET one garage vehicle by its IRI — the item read inlines its facts (the listing does not). */
export async function getVehicle(iri: string): Promise<VehicleSummary> {
  const res = await authFetch(`${API}${iri}`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`vehicle fetch failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/**
 * GET a vehicle's checklist — the planned-tasks subresource (a hydra collection of PlannedTask).
 * The API serves each task's IRI as `@id` AND a bare ULID as `id`; the UI needs the IRI to PATCH it,
 * so normalize `id` to `@id` (the scalar `id` would build a broken `https://host<ulid>` PATCH URL).
 *
 * With `includeDismissed` the soft-removed tasks ride along beneath the live list — the "see all"
 * toggle — so a wrongly-dropped task can be reviewed and reopened.
 */
export async function getVehicleTasks(vehicleIri: string, includeDismissed = false): Promise<PlannedTask[]> {
  const query = includeDismissed ? '?includeDismissed=true' : '';
  const res = await authFetch(`${API}${vehicleIri}/planned-tasks${query}`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`tasks fetch failed: ${res.status}`);
  const data = (await res.json()) as { member?: RawTask[]; 'hydra:member'?: RawTask[] };
  const members = data.member ?? data['hydra:member'] ?? [];
  return members.map((t) => ({ ...t, id: t['@id'] ?? t.id }) as PlannedTask);
}

type RawTask = PlannedTask & { '@id'?: string };

/** Editable-card write: JSON Merge Patch of the vehicle's own scalars (usage reading, plate). */
export async function patchVehicle(
  iri: string,
  patch: { usageValue?: number; usageUnit?: string; plate?: string | null },
): Promise<VehicleSummary> {
  const res = await authFetch(`${API}${iri}`, {
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
  const res = await authFetch(`${API}${iri}/facts`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    body: JSON.stringify(fact),
  });
  if (!res.ok) throw new Error(`fact write failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/** Tick a planned task off the checklist: PATCH its status. */
export async function patchTask(iri: string, status: 'open' | 'done' | 'dismissed'): Promise<void> {
  const res = await authFetch(`${API}${iri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/merge-patch+json', Accept: 'application/ld+json' },
    body: JSON.stringify({ status }),
  });
  if (!res.ok) throw new Error(`task update failed: ${res.status}`);
}

/**
 * Merge parts onto a task's shopping list: PATCH its `parts`. The server merges (dedup by ref) and
 * never clears the list — this is the deterministic CTA "add reference" path, no agent turn.
 */
export async function mergeTaskParts(iri: string, parts: PlannedPart[]): Promise<void> {
  const res = await authFetch(`${API}${iri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/merge-patch+json', Accept: 'application/ld+json' },
    body: JSON.stringify({ parts }),
  });
  if (!res.ok) throw new Error(`task parts update failed: ${res.status}`);
}
