// ─────────────────────────────────────────────────────────────────────────────
// Atelier — the garage REST client (API Platform, application/ld+json).
//
// Tier-1 transport (see PROVENANCE: a faithful client of the same REST routes,
// re-authored fresh from the old dashboard reference, no import path back). Reads
// are split into two pure shape-helpers (unit-tested) — hydra member extraction and
// the @id→id normalization — under thin authFetch verbs.
//
// The vehicle handle EVERYWHERE is the dereferenceable `@id` IRI. Subresource items
// (tasks, records, rules) serve the IRI as `@id` AND a bare ULID as `id`; we
// normalize `id := @id` so a later PATCH targets a real URL, not `https://host<ulid>`.
// ─────────────────────────────────────────────────────────────────────────────

import { API, authFetch } from './auth';
import type { FillVolume } from './fluids';
import type { SivVehicle } from './siv';
import type {
  MaintenanceAttachment,
  MaintenanceRecord,
  PlannedPart,
  PlannedTask,
  ServiceAction,
  ServiceRule,
  UsageUnit,
  VehicleBundle,
  VehicleForecast,
  VehicleSummary,
} from './types';

const LD = 'application/ld+json';
const MERGE = 'application/merge-patch+json';

// ── Pure shape helpers (unit-tested) ──────────────────────────────────────────

/** Read a hydra collection's members, tolerating both the modern `member` and legacy `hydra:member`. */
export function hydraMembers<T>(data: { member?: T[]; 'hydra:member'?: T[] }): T[] {
  return data.member ?? data['hydra:member'] ?? [];
}

/**
 * Normalize a subresource item so its `id` is the dereferenceable `@id` IRI (and drop the now-redundant
 * `@id` key). The API serves both; our model keys on `id` as the IRI, and PATCHing the bare ULID would
 * build a broken URL.
 */
export function normalizeIri<T extends { id?: string }>(raw: T & { '@id'?: string }): T {
  const { '@id': iri, ...rest } = raw;
  return { ...(rest as T), id: iri ?? raw.id };
}

// ── Reads ─────────────────────────────────────────────────────────────────────

/** GET the parc listing. Each member carries `nextDue`/`openTasks`; `health`/`facts`/`plannedTasks` ride the item GET only. */
export async function getVehicles(): Promise<VehicleSummary[]> {
  const res = await authFetch(`${API}/garage/vehicles`, { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`vehicles fetch failed: ${res.status}`);
  return hydraMembers(await res.json());
}

/** GET one vehicle by its IRI — the item read inlines `facts`, `health`, `plannedTasks`. */
export async function getVehicle(iri: string): Promise<VehicleSummary> {
  const res = await authFetch(`${API}${iri}`, { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`vehicle fetch failed: ${res.status}`);
  const v = (await res.json()) as VehicleSummary & { plannedTasks?: Array<PlannedTask & { '@id'?: string }> };
  // The vehicle handle stays `@id`; only its inlined planned tasks need the id normalization.
  if (v.plannedTasks) v.plannedTasks = v.plannedTasks.map((t) => normalizeIri(t));
  return v;
}

/** GET a vehicle's forecast (due[] soonest-first + coverage gaps). Value objects — no @id normalization. */
export async function getForecast(iri: string): Promise<VehicleForecast> {
  const res = await authFetch(`${API}${iri}/forecast`, { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`forecast fetch failed: ${res.status}`);
  const data = (await res.json()) as VehicleForecast;
  return { vehicle: data.vehicle ?? iri, due: data.due ?? [], gaps: data.gaps ?? [] };
}

/** GET a vehicle's past maintenance (newest first). Each record serves its IRI as `@id` → normalize to `id`. */
export async function getMaintenanceRecords(iri: string): Promise<MaintenanceRecord[]> {
  const res = await authFetch(`${API}${iri}/maintenance-records`, { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`records fetch failed: ${res.status}`);
  return hydraMembers<MaintenanceRecord & { '@id'?: string }>(await res.json()).map((r) => normalizeIri(r));
}

/**
 * GET a vehicle's open checklist — the planned-tasks SUBRESOURCE. This is authoritative: the vehicle
 * item GET was observed to inline `plannedTasks: []` even when open tasks exist (a backend divergence),
 * so the bundle sources the checklist here. Each task's IRI is normalized `@id`→`id` (it is the PATCH target).
 */
export async function getVehicleTasks(iri: string, includeDismissed = false): Promise<PlannedTask[]> {
  const query = includeDismissed ? '?includeDismissed=true' : '';
  const res = await authFetch(`${API}${iri}/planned-tasks${query}`, { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`tasks fetch failed: ${res.status}`);
  return hydraMembers<PlannedTask & { '@id'?: string }>(await res.json()).map((t) => normalizeIri(t));
}

/**
 * Load the full Atelier bundle for one vehicle: the item read-model + forecast + past visits + the
 * checklist, in parallel. The checklist comes from the subresource (authoritative) and overrides the
 * item GET's inline `plannedTasks`, so the établi reflects open-task parts (incl. a CTA patchTask merge).
 */
export async function getVehicleBundle(iri: string): Promise<VehicleBundle> {
  const [vehicle, forecast, records, plannedTasks] = await Promise.all([
    getVehicle(iri),
    getForecast(iri),
    getMaintenanceRecords(iri),
    getVehicleTasks(iri),
  ]);
  return { vehicle: { ...vehicle, plannedTasks }, forecast, records };
}

// ── Catalog / SIV reads (net-new §5.1 + §5.8 — feed the deterministic wayfinder chips) ─────────────

/**
 * GET a car's fluid capacities + specs (§5.8 FillVolume ★) — engine oil / gearbox / brake fluid /
 * coolant / A-C refrigerant. Keyed by kType (the TecDoc carId). Hydra collection. Backs "Capacités fluides".
 */
export async function getFillVolumes(kType: number): Promise<FillVolume[]> {
  const res = await authFetch(`${API}/cars/${kType}/fill-volumes`, { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`fill-volumes fetch failed: ${res.status}`);
  return hydraMembers<FillVolume>(await res.json());
}

/**
 * GET the SIV registration identity for a plate (§5.1 Vehicle ★) — power / displacement / gearbox /
 * weight / first registration… The rich "carte grise". Item read (not a collection). Backs "Fiche technique".
 */
export async function getSivVehicle(plate: string): Promise<SivVehicle> {
  const res = await authFetch(`${API}/vehicles/${encodeURIComponent(plate)}`, { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`plate lookup failed: ${res.status}`);
  return res.json() as Promise<SivVehicle>;
}

// ── Writes ────────────────────────────────────────────────────────────────────

/**
 * Merge parts onto a task's shopping list (PATCH {taskIri} merge-patch `{parts}`). The server merges
 * (dedup by ref) and never clears the list — this is the deterministic CTA `patchTask` path, NO agent
 * turn. The taskIri MUST be the `@id` (normalized), not a bare ULID (a ULID builds a broken URL).
 */
export async function mergeTaskParts(taskIri: string, parts: PlannedPart[]): Promise<void> {
  const res = await authFetch(`${API}${taskIri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': MERGE, Accept: LD },
    body: JSON.stringify({ parts }),
  });
  if (!res.ok) throw new Error(`task parts merge failed: ${res.status}`);
}

// ── Vehicle CRUD + facts ──────────────────────────────────────────────────────

/** The editable card scalars — the subset PATCH accepts (merge-patch: send only what changed). */
export interface VehiclePatch {
  make?: string;
  model?: string;
  year?: number | null;
  engineVariant?: string | null;
  engineCode?: string | null;
  kType?: number | null;
  vehicleType?: string;
  plate?: string | null;
  usageValue?: number;
  usageUnit?: string;
}

/** Edit a vehicle's card fields (merge-patch). Returns the fresh read-model. */
export async function patchVehicle(iri: string, patch: VehiclePatch): Promise<VehicleSummary> {
  const res = await authFetch(`${API}${iri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': MERGE, Accept: LD },
    body: JSON.stringify(patch),
  });
  if (!res.ok) throw new Error(`vehicle update failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/** Create a blank vehicle (the parc "+ Ajouter"); returns the fresh read-model to select. */
export async function createVehicle(): Promise<VehicleSummary> {
  const res = await authFetch(`${API}/garage/vehicles`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: '{}',
  });
  if (!res.ok) throw new Error(`vehicle create failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/** Soft-delete a vehicle: it drops off the parc, its history survives. */
export async function deleteVehicle(iri: string): Promise<void> {
  const res = await authFetch(`${API}${iri}`, { method: 'DELETE', headers: { Accept: LD } });
  if (!res.ok && res.status !== 204) throw new Error(`vehicle delete failed: ${res.status}`);
}

/** Record a manual spec (POST one {key,value,unit?} fact). Lands as source "user" (unverified). */
export async function postVehicleFact(iri: string, fact: { key: string; value: string; unit?: string | null }): Promise<VehicleSummary> {
  const res = await authFetch(`${API}${iri}/facts`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: JSON.stringify(fact),
  });
  if (!res.ok) throw new Error(`fact write failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/** Remove a fact by key (soft-delete; re-POST the key to bring it back). */
export async function deleteVehicleFact(iri: string, key: string): Promise<void> {
  const res = await authFetch(`${API}${iri}/facts/${encodeURIComponent(key)}`, { method: 'DELETE', headers: { Accept: LD } });
  if (!res.ok && res.status !== 204) throw new Error(`fact delete failed: ${res.status}`);
}

// ── Planned tasks ──────────────────────────────────────────────────────────────

export interface PlannedTaskInput {
  type: string;
  label?: string | null;
  target?: { value: number; unit?: string | null } | null;
  dueDate?: string | null;
  parts?: PlannedPart[];
}

/** Add a checklist task by hand (POST planned-tasks). Normalizes the returned IRI to `id`. */
export async function createTask(vehicleIri: string, input: PlannedTaskInput): Promise<PlannedTask> {
  const res = await authFetch(`${API}${vehicleIri}/planned-tasks`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: JSON.stringify(input),
  });
  if (!res.ok) throw new Error(`task create failed: ${res.status}`);
  return normalizeIri((await res.json()) as PlannedTask & { '@id'?: string });
}

/** Tick / dismiss a task: PATCH its status. */
export async function patchTaskStatus(taskIri: string, status: 'open' | 'done' | 'dismissed'): Promise<void> {
  const res = await authFetch(`${API}${taskIri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': MERGE, Accept: LD },
    body: JSON.stringify({ status }),
  });
  if (!res.ok) throw new Error(`task status update failed: ${res.status}`);
}

/** Editable task definition (merge-patch: omitted keeps, a sent null clears). Leaves status + parts intact. */
export interface TaskEdit {
  type?: string;
  label?: string | null;
  target?: { value: number; unit?: string | null } | null;
  dueDate?: string | null;
}

export async function updateTask(taskIri: string, fields: TaskEdit): Promise<void> {
  const res = await authFetch(`${API}${taskIri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': MERGE, Accept: LD },
    body: JSON.stringify(fields),
  });
  if (!res.ok) throw new Error(`task update failed: ${res.status}`);
}

/** File every done task into the past-maintenance log. Idempotent → {archived:n}. */
export async function archiveTasks(vehicleIri: string): Promise<{ archived: number }> {
  const res = await authFetch(`${API}${vehicleIri}/planned-tasks/archive`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: '{}',
  });
  if (!res.ok) throw new Error(`archive failed: ${res.status}`);
  const data = (await res.json()) as { archived?: number };
  return { archived: data.archived ?? 0 };
}

/** A writable parts-subresource line (all keys optional/nullable: omitted keeps, null clears). */
export interface TaskPartInput {
  ref?: string;
  label?: string | null;
  brand?: string | null;
  qty?: number | null;
  priceCents?: number | null;
}

/** Append a part line (POST the parts subresource; upserts by ref server-side). */
export async function addTaskPart(taskIri: string, part: TaskPartInput): Promise<void> {
  const res = await authFetch(`${API}${taskIri}/parts`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: JSON.stringify(part),
  });
  if (!res.ok) throw new Error(`part add failed: ${res.status}`);
}

/** Merge-edit one part line by its ULID (PATCH the parts subresource). */
export async function updateTaskPart(taskIri: string, partId: string, fields: TaskPartInput): Promise<void> {
  const res = await authFetch(`${API}${taskIri}/parts/${partId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': MERGE, Accept: LD },
    body: JSON.stringify(fields),
  });
  if (!res.ok) throw new Error(`part update failed: ${res.status}`);
}

/** Remove one part line by its ULID (DELETE the parts subresource). */
export async function removeTaskPart(taskIri: string, partId: string): Promise<void> {
  const res = await authFetch(`${API}${taskIri}/parts/${partId}`, { method: 'DELETE', headers: { Accept: LD } });
  if (!res.ok && res.status !== 204) throw new Error(`part remove failed: ${res.status}`);
}

// ── Service schedule (recurring rules) ──────────────────────────────────────────

type RawRule = ServiceRule & { '@id'?: string };

/** GET the recurring schedule (the rule's `id` is already the bare ULID we DELETE by — no @id norm). */
export async function getServiceSchedule(iri: string): Promise<ServiceRule[]> {
  const res = await authFetch(`${API}${iri}/service-schedule`, { headers: { Accept: LD } });
  if (!res.ok) throw new Error(`schedule fetch failed: ${res.status}`);
  return hydraMembers<RawRule>(await res.json());
}

export interface ServiceRuleInput {
  type: string;
  action: ServiceAction;
  component?: string | null;
  everyUsageValue?: number | null;
  everyUsageUnit?: UsageUnit | null;
  everyMonths?: number | null;
  firstUsageValue?: number | null;
  url?: string | null;
}

/**
 * Add or correct a recurring rule (POST). UPSERT keyed on (type, action, component). The server returns
 * 422 when no cadence is given — surface its RFC 7807 `detail` so the form can explain.
 */
export async function saveServiceRule(iri: string, input: ServiceRuleInput): Promise<ServiceRule> {
  const res = await authFetch(`${API}${iri}/service-schedule`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: JSON.stringify(input),
  });
  if (!res.ok) {
    const detail = await res
      .json()
      .then((d: { detail?: string; description?: string; 'hydra:description'?: string }) => d.detail ?? d.description ?? d['hydra:description'])
      .catch(() => undefined);
    throw new Error(detail ?? `schedule write failed: ${res.status}`);
  }
  return res.json() as Promise<ServiceRule>;
}

/** Remove a recurring rule by its ULID (soft-delete → drops off). */
export async function deleteServiceRule(iri: string, ruleId: string): Promise<void> {
  const res = await authFetch(`${API}${iri}/service-schedule/${encodeURIComponent(ruleId)}`, { method: 'DELETE', headers: { Accept: LD } });
  if (!res.ok && res.status !== 204) throw new Error(`schedule delete failed: ${res.status}`);
}

// ── Maintenance records + bill attachments ──────────────────────────────────────

/**
 * A part line of a logged visit. CRITICAL: the maintenance-record WRITE shape takes `price` in MAJOR
 * units (euros) — the backend converts to the stored `priceCents` (verified against the API's own
 * functional tests: writes send `price: 12.5`, reads return `priceCents: 1250`). This is NOT the
 * planned-task parts subresource (which stores priceCents directly — see TaskPartInput). See PROVENANCE.
 */
export interface MaintenanceRecordPart {
  ref?: string;
  brand?: string | null;
  label?: string | null;
  qty?: number | null;
  price?: number | null; // euros (major units)
}

/** One service line of a manual visit. */
export interface MaintenanceItemInput {
  type: string;
  label?: string | null;
  parts: MaintenanceRecordPart[];
}

/** A manual visit body — service items + visit-level fields. `cost` is MAJOR units (euros), like `price`. */
export interface MaintenanceRecordInput {
  items: MaintenanceItemInput[];
  performedAt: string; // YYYY-MM-DD
  label?: string | null;
  usageValue?: number | null;
  usageUnit?: string | null;
  notes?: string | null;
  cost?: number | null; // euros (major units) — the backend converts to costCents
  currency?: string | null;
  performedBy?: string | null;
}

/** Log a past service by hand (POST maintenance-records). */
export async function createMaintenanceRecord(vehicleIri: string, input: MaintenanceRecordInput): Promise<void> {
  const res = await authFetch(`${API}${vehicleIri}/maintenance-records`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: JSON.stringify(input),
  });
  if (!res.ok) throw new Error(`record create failed: ${res.status}`);
}

/** Edit a logged visit (PATCH /garage/maintenance-records/{id} — the body replaces the whole visit). */
export async function updateMaintenanceRecord(recordIri: string, input: MaintenanceRecordInput): Promise<void> {
  const res = await authFetch(`${API}${recordIri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': MERGE, Accept: LD },
    body: JSON.stringify(input),
  });
  if (!res.ok) throw new Error(`record update failed: ${res.status}`);
}

/** Remove a logged visit. */
export async function deleteMaintenanceRecord(recordIri: string): Promise<void> {
  const res = await authFetch(`${API}${recordIri}`, { method: 'DELETE', headers: { Accept: LD } });
  if (!res.ok && res.status !== 204) throw new Error(`record delete failed: ${res.status}`);
}

/**
 * Attach one bill (PDF/JPEG/PNG) to a visit: multipart POST. NEVER set Content-Type — the browser sets
 * the multipart boundary itself. Returns the stored attachment metadata.
 */
export async function uploadMaintenanceAttachment(recordIri: string, file: File): Promise<MaintenanceAttachment> {
  const body = new FormData();
  body.append('file', file);
  const res = await authFetch(`${API}${recordIri}/attachments`, { method: 'POST', body });
  if (!res.ok) throw new Error(`attachment upload failed: ${res.status}`);
  return res.json() as Promise<MaintenanceAttachment>;
}

/** Remove one bill (DELETE the attachment IRI). */
export async function deleteMaintenanceAttachment(attachmentUrl: string): Promise<void> {
  const res = await authFetch(`${API}${attachmentUrl}`, { method: 'DELETE', headers: { Accept: LD } });
  if (!res.ok && res.status !== 204) throw new Error(`attachment delete failed: ${res.status}`);
}

/**
 * Fetch a bill's bytes WITH the Bearer and hand back an object URL (the endpoint is owner-gated, so a
 * plain <img src> would 404). The caller MUST URL.revokeObjectURL when done to avoid leaking the blob.
 */
export async function fetchAttachmentObjectUrl(attachmentUrl: string): Promise<string> {
  const res = await authFetch(`${API}${attachmentUrl}`);
  if (!res.ok) throw new Error(`attachment fetch failed: ${res.status}`);
  return URL.createObjectURL(await res.blob());
}
