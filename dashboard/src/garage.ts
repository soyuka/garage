import { authFetch } from './auth';
import type { MaintenanceAttachment, MaintenanceRecord, PlannedPart, PlannedTask, ServiceRule, VehicleForecast, VehicleSummary } from './types';

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

/** The card's editable scalars — the subset the PATCH endpoint accepts (merge-patch: send only what changed). */
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

/** Editable-card write: JSON Merge Patch of the vehicle's card fields. */
export async function patchVehicle(iri: string, patch: VehiclePatch): Promise<VehicleSummary> {
  const res = await authFetch(`${API}${iri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/merge-patch+json', Accept: 'application/ld+json' },
    body: JSON.stringify(patch),
  });
  if (!res.ok) throw new Error(`vehicle update failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/** Create a blank vehicle (the parc's "+ Ajouter"); returns the fresh read-model to select. */
export async function createVehicle(): Promise<VehicleSummary> {
  const res = await authFetch(`${API}/garage/vehicles`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    body: '{}',
  });
  if (!res.ok) throw new Error(`vehicle create failed: ${res.status}`);
  return res.json() as Promise<VehicleSummary>;
}

/** Soft-delete a vehicle: it drops off the parc but its history survives. */
export async function deleteVehicle(iri: string): Promise<void> {
  const res = await authFetch(`${API}${iri}`, { method: 'DELETE', headers: { Accept: 'application/ld+json' } });
  if (!res.ok && res.status !== 204) throw new Error(`vehicle delete failed: ${res.status}`);
}

/** Remove a fact from the card by key (soft-delete; re-POST the key to bring it back). */
export async function deleteVehicleFact(vehicleIri: string, key: string): Promise<void> {
  const res = await authFetch(`${API}${vehicleIri}/facts/${encodeURIComponent(key)}`, {
    method: 'DELETE',
    headers: { Accept: 'application/ld+json' },
  });
  if (!res.ok && res.status !== 204) throw new Error(`fact delete failed: ${res.status}`);
}

/** The body of a manual task add. */
export interface PlannedTaskInput {
  type: string;
  label?: string | null;
  target?: { value: number; unit?: string | null } | null;
  dueDate?: string | null;
  parts?: PlannedPart[];
}

/** Add a checklist task by hand (POST planned-tasks). */
export async function createTask(vehicleIri: string, input: PlannedTaskInput): Promise<PlannedTask> {
  const res = await authFetch(`${API}${vehicleIri}/planned-tasks`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    body: JSON.stringify(input),
  });
  if (!res.ok) throw new Error(`task create failed: ${res.status}`);
  const t = (await res.json()) as PlannedTask & { '@id'?: string };
  return { ...t, id: t['@id'] ?? t.id };
}

/**
 * "Archiver les entretiens terminés": file every done task into the past-maintenance log. Returns
 * how many were archived (0 on a re-run — the call is idempotent). The caller refetches both the
 * checklist and the records afterwards.
 */
export async function archiveTasks(vehicleIri: string): Promise<{ archived: number }> {
  const res = await authFetch(`${API}${vehicleIri}/planned-tasks/archive`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    body: '{}',
  });
  if (!res.ok) throw new Error(`archive failed: ${res.status}`);
  const data = (await res.json()) as { archived?: number };
  return { archived: data.archived ?? 0 };
}

/**
 * GET a vehicle's maintenance forecast (the forecast subresource): the due items (soonest-first) and
 * the coverage gaps. Mirrors the records/tasks fetchers; the response needs no @id normalisation
 * (the items are value objects, not addressable resources).
 */
export async function fetchForecast(vehicleIri: string): Promise<VehicleForecast> {
  const res = await authFetch(`${API}${vehicleIri}/forecast`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`forecast fetch failed: ${res.status}`);
  const data = (await res.json()) as VehicleForecast;
  return { vehicle: data.vehicle, due: data.due ?? [], gaps: data.gaps ?? [] };
}

type RawRule = ServiceRule & { '@id'?: string };

/**
 * GET a vehicle's recurring maintenance schedule — the service-schedule subresource (a hydra
 * collection of ServiceRule). Mirrors the records/tasks fetchers; the rule's `id` is already the
 * bare ULID we DELETE by, so no @id normalisation is needed.
 */
export async function fetchServiceSchedule(vehicleIri: string): Promise<ServiceRule[]> {
  const res = await authFetch(`${API}${vehicleIri}/service-schedule`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`schedule fetch failed: ${res.status}`);
  const data = (await res.json()) as { member?: RawRule[]; 'hydra:member'?: RawRule[] };
  return data.member ?? data['hydra:member'] ?? [];
}

/** The body of a schedule-rule write — the fields the POST endpoint accepts (source is server-set). */
export interface ServiceRuleInput {
  type: string;
  action: 'change' | 'replace' | 'clean' | 'inspect';
  component?: string | null;
  everyUsageValue?: number | null;
  everyUsageUnit?: 'km' | 'mi' | 'hours' | null;
  everyMonths?: number | null;
  firstUsageValue?: number | null;
  url?: string | null;
}

/**
 * Add or correct a recurring rule (POST service-schedule). UPSERT keyed on (type, action, component):
 * re-POSTing the same identity corrects the cadence in place. Lands server-side as source "user".
 * The server returns 422 when no cadence is given — surface its message so the form can explain.
 */
export async function saveServiceRule(vehicleIri: string, input: ServiceRuleInput): Promise<ServiceRule> {
  const res = await authFetch(`${API}${vehicleIri}/service-schedule`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    body: JSON.stringify(input),
  });
  if (!res.ok) {
    // RFC 7807 / hydra error: prefer the server's human description (e.g. the 422 "cadence required").
    const detail = await res
      .json()
      .then((d: { detail?: string; description?: string; 'hydra:description'?: string }) => d.detail ?? d.description ?? d['hydra:description'])
      .catch(() => undefined);
    throw new Error(detail ?? `schedule write failed: ${res.status}`);
  }
  return res.json() as Promise<ServiceRule>;
}

/** Remove a recurring rule by its ULID (DELETE service-schedule/{ruleId}; soft-delete → drops off). */
export async function deleteServiceRule(vehicleIri: string, ruleId: string): Promise<void> {
  const res = await authFetch(`${API}${vehicleIri}/service-schedule/${encodeURIComponent(ruleId)}`, {
    method: 'DELETE',
    headers: { Accept: 'application/ld+json' },
  });
  if (!res.ok && res.status !== 204) throw new Error(`schedule delete failed: ${res.status}`);
}

type RawRecord = MaintenanceRecord & { '@id'?: string };

/** GET a vehicle's past maintenance (newest first). The API serves the IRI as `@id`; normalize to `id`. */
export async function getMaintenanceRecords(vehicleIri: string): Promise<MaintenanceRecord[]> {
  const res = await authFetch(`${API}${vehicleIri}/maintenance-records`, { headers: { Accept: 'application/ld+json' } });
  if (!res.ok) throw new Error(`records fetch failed: ${res.status}`);
  const data = (await res.json()) as { member?: RawRecord[]; 'hydra:member'?: RawRecord[] };
  const members = data.member ?? data['hydra:member'] ?? [];
  return members.map((r) => ({ ...r, id: r['@id'] ?? r.id }) as MaintenanceRecord);
}

/** A part as entered on the manual visit form. `price` is in major units (euros); the API stores it as priceCents. */
export interface PartInput {
  ref: string;
  brand?: string;
  label?: string;
  qty?: number;
  price?: number;
}

/** One service line of a manual visit: a type + the parts it consumed. */
export interface MaintenanceItemInput {
  type: string;
  label?: string | null;
  parts: PartInput[];
}

/** The body of a manual maintenance visit — a list of service items plus visit-level fields. */
export interface MaintenanceRecordInput {
  items: MaintenanceItemInput[];
  performedAt: string;
  label?: string | null;
  usageValue?: number | null;
  usageUnit?: string | null;
  notes?: string | null;
  cost?: number | null;
  currency?: string | null;
  performedBy?: string | null;
}

/** Log a past service by hand (POST maintenance-records). */
export async function createMaintenanceRecord(vehicleIri: string, input: MaintenanceRecordInput): Promise<void> {
  const res = await authFetch(`${API}${vehicleIri}/maintenance-records`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    body: JSON.stringify(input),
  });
  if (!res.ok) throw new Error(`record create failed: ${res.status}`);
}

/** Edit a logged visit (PATCH /garage/maintenance-records/{id}). The body replaces the whole visit. */
export async function updateMaintenanceRecord(recordIri: string, input: MaintenanceRecordInput): Promise<void> {
  const res = await authFetch(`${API}${recordIri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/merge-patch+json', Accept: 'application/ld+json' },
    body: JSON.stringify(input),
  });
  if (!res.ok) throw new Error(`record update failed: ${res.status}`);
}

/** Remove a logged visit (DELETE /garage/maintenance-records/{id}). */
export async function deleteMaintenanceRecord(recordIri: string): Promise<void> {
  const res = await authFetch(`${API}${recordIri}`, {
    method: 'DELETE',
    headers: { Accept: 'application/ld+json' },
  });
  if (!res.ok) throw new Error(`record delete failed: ${res.status}`);
}

/**
 * Attach one bill (PDF/JPEG/PNG) to a logged visit: multipart POST to its attachments subresource.
 * The browser sets the multipart Content-Type (with boundary) itself — never set it by hand.
 * Returns the stored attachment's metadata.
 */
export async function uploadMaintenanceAttachment(recordIri: string, file: File): Promise<MaintenanceAttachment> {
  const body = new FormData();
  body.append('file', file);
  const res = await authFetch(`${API}${recordIri}/attachments`, { method: 'POST', body });
  if (!res.ok) throw new Error(`attachment upload failed: ${res.status}`);
  return res.json() as Promise<MaintenanceAttachment>;
}

/** Remove one bill from a visit (DELETE the attachment IRI). */
export async function deleteMaintenanceAttachment(attachmentUrl: string): Promise<void> {
  const res = await authFetch(`${API}${attachmentUrl}`, { method: 'DELETE', headers: { Accept: 'application/ld+json' } });
  if (!res.ok && res.status !== 204) throw new Error(`attachment delete failed: ${res.status}`);
}

/**
 * Fetch a bill's bytes with the Bearer and hand back an object URL for viewing. The endpoint is
 * owner-gated, so a plain <a href>/<img src> (no Authorization header) would 404 — we fetch then
 * URL.createObjectURL. The caller must URL.revokeObjectURL when done to avoid leaking the blob.
 */
export async function fetchAttachmentObjectUrl(attachmentUrl: string): Promise<string> {
  const res = await authFetch(`${API}${attachmentUrl}`);
  if (!res.ok) throw new Error(`attachment fetch failed: ${res.status}`);
  return URL.createObjectURL(await res.blob());
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

/** Editable definition fields of a task. A merge-patch: omitted keys keep, a sent `null` clears. */
export interface TaskEdit {
  type?: string;
  label?: string | null;
  target?: { value: number; unit?: string | null } | null;
  dueDate?: string | null;
}

/** Edit a task's definition (type/label/target/dueDate) — merge-patch, leaves status & parts intact. */
export async function updateTask(iri: string, fields: TaskEdit): Promise<void> {
  const res = await authFetch(`${API}${iri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/merge-patch+json', Accept: 'application/ld+json' },
    body: JSON.stringify(fields),
  });
  if (!res.ok) throw new Error(`task update failed: ${res.status}`);
}

/** A writable planned-task part line — the parts subresource's input shape (no derived `spec`, no
 *  `id`). All keys optional and nullable: omitted keeps (merge-patch), `null` clears. */
export interface TaskPartInput {
  ref?: string;
  label?: string | null;
  brand?: string | null;
  qty?: number | null;
  priceCents?: number | null;
}

/** Append a line to a task's shopping list (POST the parts subresource; upserts by ref server-side). */
export async function addTaskPart(taskIri: string, part: TaskPartInput): Promise<void> {
  const res = await authFetch(`${API}${taskIri}/parts`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/ld+json', Accept: 'application/ld+json' },
    body: JSON.stringify(part),
  });
  if (!res.ok) throw new Error(`part add failed: ${res.status}`);
}

/** Merge-edit one line by its ULID (PATCH the parts subresource). */
export async function updateTaskPart(taskIri: string, partId: string, fields: TaskPartInput): Promise<void> {
  const res = await authFetch(`${API}${taskIri}/parts/${partId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/merge-patch+json', Accept: 'application/ld+json' },
    body: JSON.stringify(fields),
  });
  if (!res.ok) throw new Error(`part update failed: ${res.status}`);
}

/** Remove one line by its ULID (DELETE the parts subresource). */
export async function removeTaskPart(taskIri: string, partId: string): Promise<void> {
  const res = await authFetch(`${API}${taskIri}/parts/${partId}`, {
    method: 'DELETE',
    headers: { Accept: 'application/ld+json' },
  });
  if (!res.ok) throw new Error(`part remove failed: ${res.status}`);
}
