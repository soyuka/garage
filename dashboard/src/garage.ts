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
import type { PageLabel } from './scene/documentViewer';
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
  VehicleDocument,
  VehicleDocumentKind,
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

// ── Response guards ────────────────────────────────────────────────────────────
// One error-message format (`<label> failed: <status>`) for every verb. `res.ok` is already the
// 200–299 range (204 included), so a "no content" write needs no special-casing here.

async function okJson<T>(res: Response, label: string): Promise<T> {
  if (!res.ok) throw new Error(`${label} failed: ${res.status}`);
  return res.json() as Promise<T>;
}

function okVoid(res: Response, label: string): void {
  if (!res.ok) throw new Error(`${label} failed: ${res.status}`);
}

// ── Reads ─────────────────────────────────────────────────────────────────────

/** GET the parc listing. Each member carries `nextDue`/`openTasks`; `health`/`facts`/`plannedTasks` ride the item GET only. */
export async function getVehicles(): Promise<VehicleSummary[]> {
  const res = await authFetch(`${API}/garage/vehicles`, { headers: { Accept: LD } });
  return hydraMembers(await okJson(res, 'vehicles fetch'));
}

/** GET one vehicle by its IRI — the item read inlines `facts`, `health`, `plannedTasks`. */
export async function getVehicle(iri: string): Promise<VehicleSummary> {
  const res = await authFetch(`${API}${iri}`, { headers: { Accept: LD } });
  const v = await okJson<VehicleSummary & { plannedTasks?: Array<PlannedTask & { '@id'?: string }> }>(res, 'vehicle fetch');
  // The vehicle handle stays `@id`; only its inlined planned tasks need the id normalization.
  if (v.plannedTasks) v.plannedTasks = v.plannedTasks.map((t) => normalizeIri(t));
  return v;
}

/** GET a vehicle's forecast (due[] soonest-first + coverage gaps). Value objects — no @id normalization. */
export async function getForecast(iri: string): Promise<VehicleForecast> {
  const res = await authFetch(`${API}${iri}/forecast`, { headers: { Accept: LD } });
  const data = await okJson<VehicleForecast>(res, 'forecast fetch');
  return { vehicle: data.vehicle ?? iri, due: data.due ?? [], gaps: data.gaps ?? [] };
}

/** GET a vehicle's past maintenance (newest first). Each record serves its IRI as `@id` → normalize to `id`. */
export async function getMaintenanceRecords(iri: string): Promise<MaintenanceRecord[]> {
  const res = await authFetch(`${API}${iri}/maintenance-records`, { headers: { Accept: LD } });
  return hydraMembers<MaintenanceRecord & { '@id'?: string }>(await okJson(res, 'records fetch')).map((r) => normalizeIri(r));
}

/**
 * GET a vehicle's open checklist — the planned-tasks SUBRESOURCE. This is authoritative: the vehicle
 * item GET was observed to inline `plannedTasks: []` even when open tasks exist (a backend divergence),
 * so the bundle sources the checklist here. Each task's IRI is normalized `@id`→`id` (it is the PATCH target).
 */
export async function getVehicleTasks(iri: string, includeDismissed = false): Promise<PlannedTask[]> {
  const query = includeDismissed ? '?includeDismissed=true' : '';
  const res = await authFetch(`${API}${iri}/planned-tasks${query}`, { headers: { Accept: LD } });
  return hydraMembers<PlannedTask & { '@id'?: string }>(await okJson(res, 'tasks fetch')).map((t) => normalizeIri(t));
}

/**
 * Load the full Atelier bundle for one vehicle: the item read-model + forecast + past visits + the
 * checklist + the filed documents, in parallel. The checklist comes from the subresource (authoritative) and overrides the
 * item GET's inline `plannedTasks`, so the établi reflects open-task parts (incl. a CTA patchTask merge).
 */
export async function getVehicleBundle(iri: string): Promise<VehicleBundle> {
  const [vehicle, forecast, records, plannedTasks, documents] = await Promise.all([
    getVehicle(iri),
    getForecast(iri),
    getMaintenanceRecords(iri),
    getVehicleTasks(iri),
    // A documents outage must not take the car page down with it: null reads as "unavailable" in the
    // documentation panel, never as an empty file.
    listVehicleDocuments(iri).catch(() => null),
  ]);
  return { vehicle: { ...vehicle, plannedTasks }, forecast, records, documents };
}

// ── Catalog / SIV reads (net-new §5.1 + §5.8 — feed the deterministic wayfinder chips) ─────────────

/**
 * GET a car's fluid capacities + specs (§5.8 FillVolume ★) — engine oil / gearbox / brake fluid /
 * coolant / A-C refrigerant. Keyed by kType (the TecDoc carId). Hydra collection. Backs "Capacités fluides".
 */
export async function getFillVolumes(kType: number): Promise<FillVolume[]> {
  const res = await authFetch(`${API}/cars/${kType}/fill-volumes`, { headers: { Accept: LD } });
  return hydraMembers<FillVolume>(await okJson(res, 'fill-volumes fetch'));
}

/**
 * GET the SIV registration identity for a plate (§5.1 Vehicle ★) — power / displacement / gearbox /
 * weight / first registration… The rich "carte grise". Item read (not a collection). Backs "Fiche technique".
 */
export async function getSivVehicle(plate: string): Promise<SivVehicle> {
  const res = await authFetch(`${API}/vehicles/${encodeURIComponent(plate)}`, { headers: { Accept: LD } });
  return okJson<SivVehicle>(res, 'plate lookup');
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
  okVoid(res, 'task parts merge');
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
  return okJson<VehicleSummary>(res, 'vehicle update');
}

/** Create a blank vehicle (the parc "+ Ajouter"); returns the fresh read-model to select. */
export async function createVehicle(): Promise<VehicleSummary> {
  const res = await authFetch(`${API}/garage/vehicles`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: '{}',
  });
  return okJson<VehicleSummary>(res, 'vehicle create');
}

/** Soft-delete a vehicle: it drops off the parc, its history survives. */
export async function deleteVehicle(iri: string): Promise<void> {
  const res = await authFetch(`${API}${iri}`, { method: 'DELETE', headers: { Accept: LD } });
  okVoid(res, 'vehicle delete');
}

/** Record a manual spec (POST one {key,value,unit?} fact). Lands as source "user" (unverified). */
export async function postVehicleFact(iri: string, fact: { key: string; value: string; unit?: string | null }): Promise<VehicleSummary> {
  const res = await authFetch(`${API}${iri}/facts`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: JSON.stringify(fact),
  });
  return okJson<VehicleSummary>(res, 'fact write');
}

/** Remove a fact by key (soft-delete; re-POST the key to bring it back). */
export async function deleteVehicleFact(iri: string, key: string): Promise<void> {
  const res = await authFetch(`${API}${iri}/facts/${encodeURIComponent(key)}`, { method: 'DELETE', headers: { Accept: LD } });
  okVoid(res, 'fact delete');
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
  return normalizeIri(await okJson<PlannedTask & { '@id'?: string }>(res, 'task create'));
}

/** Tick / dismiss a task: PATCH its status. */
export async function patchTaskStatus(taskIri: string, status: 'open' | 'done' | 'dismissed'): Promise<void> {
  const res = await authFetch(`${API}${taskIri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': MERGE, Accept: LD },
    body: JSON.stringify({ status }),
  });
  okVoid(res, 'task status update');
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
  okVoid(res, 'task update');
}

/** File every done task into the past-maintenance log. Idempotent → {archived:n}. */
export async function archiveTasks(vehicleIri: string): Promise<{ archived: number }> {
  const res = await authFetch(`${API}${vehicleIri}/planned-tasks/archive`, {
    method: 'POST',
    headers: { 'Content-Type': LD, Accept: LD },
    body: '{}',
  });
  const data = await okJson<{ archived?: number }>(res, 'archive');
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
  okVoid(res, 'part add');
}

/** Merge-edit one part line by its ULID (PATCH the parts subresource). */
export async function updateTaskPart(taskIri: string, partId: string, fields: TaskPartInput): Promise<void> {
  const res = await authFetch(`${API}${taskIri}/parts/${partId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': MERGE, Accept: LD },
    body: JSON.stringify(fields),
  });
  okVoid(res, 'part update');
}

/** Remove one part line by its ULID (DELETE the parts subresource). */
export async function removeTaskPart(taskIri: string, partId: string): Promise<void> {
  const res = await authFetch(`${API}${taskIri}/parts/${partId}`, { method: 'DELETE', headers: { Accept: LD } });
  okVoid(res, 'part remove');
}

// ── Service schedule (recurring rules) ──────────────────────────────────────────

type RawRule = ServiceRule & { '@id'?: string };

/** GET the recurring schedule (the rule's `id` is already the bare ULID we DELETE by — no @id norm). */
export async function getServiceSchedule(iri: string): Promise<ServiceRule[]> {
  const res = await authFetch(`${API}${iri}/service-schedule`, { headers: { Accept: LD } });
  return hydraMembers<RawRule>(await okJson(res, 'schedule fetch'));
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
  okVoid(res, 'schedule delete');
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
  okVoid(res, 'record create');
}

/** Edit a logged visit (PATCH /garage/maintenance-records/{id} — the body replaces the whole visit). */
export async function updateMaintenanceRecord(recordIri: string, input: MaintenanceRecordInput): Promise<void> {
  const res = await authFetch(`${API}${recordIri}`, {
    method: 'PATCH',
    headers: { 'Content-Type': MERGE, Accept: LD },
    body: JSON.stringify(input),
  });
  okVoid(res, 'record update');
}

/** Remove a logged visit. */
export async function deleteMaintenanceRecord(recordIri: string): Promise<void> {
  const res = await authFetch(`${API}${recordIri}`, { method: 'DELETE', headers: { Accept: LD } });
  okVoid(res, 'record delete');
}

/**
 * Attach one bill (PDF/JPEG/PNG) to a visit: multipart POST. NEVER set Content-Type — the browser sets
 * the multipart boundary itself. Returns the stored attachment metadata.
 */
export async function uploadMaintenanceAttachment(recordIri: string, file: File): Promise<MaintenanceAttachment> {
  const body = new FormData();
  body.append('file', file);
  const res = await authFetch(`${API}${recordIri}/attachments`, { method: 'POST', body });
  return okJson<MaintenanceAttachment>(res, 'attachment upload');
}

/** Remove one bill (DELETE the attachment IRI). */
export async function deleteMaintenanceAttachment(attachmentUrl: string): Promise<void> {
  const res = await authFetch(`${API}${attachmentUrl}`, { method: 'DELETE', headers: { Accept: LD } });
  okVoid(res, 'attachment delete');
}

/**
 * Fetch an owner-gated file's bytes (a bill or a vehicle document) WITH the Bearer — a bare
 * <a href>/<img src> would 404. The document viewer reads them; nothing is handed to the browser as a URL.
 */
export async function fetchDocumentBlob(fileUrl: string, signal?: AbortSignal): Promise<Blob> {
  const res = await authFetch(`${API}${fileUrl}`, { signal });
  okVoid(res, 'document fetch');
  return res.blob();
}

/** The manual's own page labels (« 23A-19 ») beside each physical page number — no page text. */
export async function fetchDocumentPages(pagesUrl: string, signal?: AbortSignal): Promise<PageLabel[]> {
  const res = await authFetch(`${API}${pagesUrl}`, { headers: { Accept: 'application/json' }, signal });
  return okJson<PageLabel[]>(res, 'document pages fetch');
}

// ── Vehicle documents (manuals, technical notes, quotes…) ───────────────────────

/** GET a vehicle's documents, newest first. A plain JSON array (not hydra); ids are bare ULIDs. */
export async function listVehicleDocuments(vehicleIri: string): Promise<VehicleDocument[]> {
  const res = await authFetch(`${API}${vehicleIri}/documents`, { headers: { Accept: 'application/json' } });
  return okJson<VehicleDocument[]>(res, 'documents fetch');
}

export interface VehicleDocumentUpload {
  file: File;
  kind: VehicleDocumentKind;
  title?: string | null;
  sourceRef?: string | null;
  /** The planned task's bare ULID (not its IRI) — what the documents API stores. */
  plannedTaskId?: string | null;
}

/** The multipart body for a document upload; blank optional fields are left out, never sent empty. */
export function documentUploadBody(input: VehicleDocumentUpload): FormData {
  const body = new FormData();
  body.append('file', input.file);
  body.append('kind', input.kind);
  for (const field of ['title', 'sourceRef', 'plannedTaskId'] as const) {
    const value = input[field]?.trim();
    if (value) body.append(field, value);
  }
  return body;
}

/** File one document on a vehicle (multipart POST — never set Content-Type, the browser sets the boundary). */
export async function uploadVehicleDocument(vehicleIri: string, input: VehicleDocumentUpload): Promise<VehicleDocument> {
  const res = await authFetch(`${API}${vehicleIri}/documents`, { method: 'POST', body: documentUploadBody(input) });
  if (!res.ok) {
    const detail = await res
      .json()
      .then((d: { detail?: string; message?: string }) => d.detail ?? d.message)
      .catch(() => undefined);
    throw new Error(detail ?? `document upload failed: ${res.status}`);
  }
  return res.json() as Promise<VehicleDocument>;
}

/** Remove one document by its bare ULID. */
export async function deleteVehicleDocument(vehicleIri: string, documentId: string): Promise<void> {
  const res = await authFetch(`${API}${vehicleIri}/documents/${encodeURIComponent(documentId)}`, { method: 'DELETE' });
  okVoid(res, 'document delete');
}
