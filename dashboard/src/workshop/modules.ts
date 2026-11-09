import { dueSignal } from '../derive';
import { canonicalService } from '../labels';
import type { ForecastDue, Iri, MaintenanceRecord, PlannedPart, PlannedTask, VehicleBundle, VehicleDocumentKind, VehicleSummary } from '../types';
import { normalizeUnit, usageSlope, type TrackUnit } from './kmTrack';

export type JobState = 'over' | 'due' | 'planned';

export interface Remaining {
  value: number;
  unit: TrackUnit;
}

export interface JobItem {
  /** The forecast service type, or the task IRI for a task the forecast does not carry. */
  id: string;
  type: string;
  label: string | null;
  state: JobState;
  /** An open task with neither target nor date: work to do now, not a forecast. */
  immediate: boolean;
  remaining: Remaining | null;
  /** The reading the job falls due at, on the vehicle's own axis. */
  dueReading: number | null;
  dueDate: string | null;
  days: number | null;
  taskId: Iri | null;
  parts: PlannedPart[];
}

export interface CtModule {
  dueAt: string | null;
  days: number | null;
  state: JobState | 'unknown';
  lastPerformedAt: string | null;
}

export interface DocRow {
  id: string;
  title: string;
  kind: VehicleDocumentKind | 'bill';
  date: string;
  contentType: string;
  filename: string;
  url: string;
  pagesUrl?: string;
  size?: number;
  pageCount?: number;
}

export interface Modules {
  aFaire: JobItem[];
  bientot: JobItem[];
  derniereVisite: MaintenanceRecord | null;
  controleTechnique: CtModule | null;
  documentsRecents: DocRow[];
  documentsUnavailable: boolean;
}

export interface NextJob {
  type: string;
  label: string;
  state: JobState;
  immediate: boolean;
  remaining: Remaining | null;
  days: number | null;
  dueDate: string | null;
}

const SOON_DAYS = 30;
const RECENT_DOCS = 5;
const DAY_MS = 86_400_000;

function isInspection(type: string): boolean {
  return canonicalService(type) === 'inspection';
}

function stateOfDue(due: ForecastDue): JobState {
  if (due.overdue) return 'over';
  return dueSignal(due) === 'amber' ? 'due' : 'planned';
}

function stateOfDays(days: number): JobState {
  if (days < 0) return 'over';
  return days <= SOON_DAYS ? 'due' : 'planned';
}

function dayDiff(iso: string, now: Date): number {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  if (!m) return NaN;
  const target = Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  const today = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());
  return Math.round((target - today) / DAY_MS);
}

function urgency(job: JobItem, slope: number | null): number {
  if (job.days !== null) return job.days;
  if (job.remaining && slope) return job.remaining.value / slope;
  return Number.POSITIVE_INFINITY;
}

function jobFromDue(due: ForecastDue, task: PlannedTask | undefined): JobItem {
  return {
    id: due.type,
    type: due.type,
    label: task?.label?.trim() ? task.label.trim() : null,
    state: stateOfDue(due),
    immediate: false,
    remaining: due.usage ? { value: due.usage.remaining, unit: normalizeUnit(due.usage.unit) } : null,
    dueReading: due.usage ? due.usage.due : null,
    dueDate: due.dueAt ?? due.projectedDueAt,
    days: due.daysUntilDue,
    taskId: task?.id ?? null,
    parts: task?.parts ?? [],
  };
}

function jobFromTask(task: PlannedTask, bundle: VehicleBundle, slope: number | null, now: Date): JobItem {
  const base = {
    id: task.id,
    type: task.type,
    label: task.label?.trim() ? task.label.trim() : null,
    taskId: task.id,
    parts: task.parts,
  };
  const at = bundle.vehicle.usageValue;
  if (task.target && at != null) {
    const remaining = task.target.value - at;
    const days = slope ? remaining / slope : null;
    const state: JobState = remaining <= 0 ? 'over' : days !== null ? stateOfDays(days) : 'planned';
    const unit = normalizeUnit(task.target.unit ?? bundle.vehicle.usageUnit);
    return { ...base, state, immediate: false, remaining: { value: remaining, unit }, dueReading: task.target.value, dueDate: task.dueDate ?? null, days: days === null ? null : Math.round(days) };
  }
  if (task.dueDate) {
    const days = dayDiff(task.dueDate, now);
    return { ...base, state: stateOfDays(days), immediate: false, remaining: null, dueReading: null, dueDate: task.dueDate, days };
  }
  return { ...base, state: 'due', immediate: true, remaining: null, dueReading: null, dueDate: null, days: null };
}

/** Filed documents and visit bills, newest first. */
export function documentRows(bundle: VehicleBundle, limit = Number.POSITIVE_INFINITY): DocRow[] {
  const rows: DocRow[] = (bundle.documents ?? []).map((d) => ({
    id: d.id,
    title: d.title,
    kind: d.kind,
    date: d.addedAt.slice(0, 10),
    contentType: d.contentType,
    filename: d.filename,
    url: d.url,
    pagesUrl: d.pagesUrl,
    size: d.size,
    pageCount: d.pageCount,
  }));
  for (const r of bundle.records) {
    for (const a of r.attachments ?? []) {
      rows.push({ id: a.id, title: a.filename, kind: 'bill', date: r.performedAt, contentType: a.contentType, filename: a.filename, url: a.url, size: a.size });
    }
  }
  return rows.sort((a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : 0)).slice(0, limit);
}

function lastVisit(records: MaintenanceRecord[]): MaintenanceRecord | null {
  let best: MaintenanceRecord | null = null;
  for (const r of records) {
    if (!best || r.performedAt > best.performedAt || (r.performedAt === best.performedAt && (r.usageValue ?? 0) > (best.usageValue ?? 0))) best = r;
  }
  return best;
}

function inspection(bundle: VehicleBundle): CtModule | null {
  const due = bundle.forecast.due.find((d) => isInspection(d.type));
  if (due) return { dueAt: due.dueAt ?? due.projectedDueAt, days: due.daysUntilDue, state: stateOfDue(due), lastPerformedAt: due.lastPerformedAt };
  const logged = bundle.records
    .filter((r) => r.items.some((i) => isInspection(i.type)))
    .map((r) => r.performedAt)
    .sort()
    .pop();
  return logged ? { dueAt: null, days: null, state: 'unknown', lastPerformedAt: logged } : null;
}

export function deriveModules(bundle: VehicleBundle, now: Date): Modules {
  const slope = usageSlope(bundle, now);
  const open = ((bundle.vehicle.plannedTasks ?? []) as PlannedTask[]).filter((t) => t.status === 'open');
  const byType = new Map(open.map((t) => [canonicalService(t.type), t]));
  const forecastTypes = new Set(bundle.forecast.due.map((d) => canonicalService(d.type)));

  const jobs: JobItem[] = [
    ...bundle.forecast.due.map((d) => jobFromDue(d, byType.get(canonicalService(d.type)))),
    ...open.filter((t) => !forecastTypes.has(canonicalService(t.type))).map((t) => jobFromTask(t, bundle, slope, now)),
  ];
  const bySoonest = (a: JobItem, b: JobItem) => urgency(a, slope) - urgency(b, slope);

  const aFaire = [
    ...jobs.filter((j) => j.state === 'over').sort(bySoonest),
    ...jobs.filter((j) => j.immediate),
  ];
  const bientot = jobs.filter((j) => j.state !== 'over' && !j.immediate && !isInspection(j.type)).sort(bySoonest);

  return {
    aFaire,
    bientot,
    derniereVisite: lastVisit(bundle.records),
    controleTechnique: inspection(bundle),
    documentsRecents: documentRows(bundle, RECENT_DOCS),
    documentsUnavailable: bundle.documents === null,
  };
}

/** The listing's one-line next job (the collection carries `nextDue`, never the full forecast). */
export function nextJobOf(vehicle: VehicleSummary): NextJob | null {
  const n = vehicle.nextDue;
  if (!n) return null;
  const state: JobState = n.overdue ? 'over' : n.daysUntilDue !== null && n.daysUntilDue <= SOON_DAYS ? 'due' : 'planned';
  return {
    type: n.type,
    label: n.label,
    state,
    immediate: false,
    remaining: n.usageRemaining !== null ? { value: n.usageRemaining, unit: normalizeUnit(n.usageUnit ?? vehicle.usageUnit) } : null,
    days: n.daysUntilDue,
    dueDate: n.dueAt ?? n.projectedDueAt,
  };
}

const STATE_RANK: Record<JobState, number> = { over: 0, due: 1, planned: 2 };

/** The parc read by urgency: overdue first, then soonest; untracked vehicles last. Stable on ties. */
export function rankParc(vehicles: VehicleSummary[], bundles: Record<string, VehicleBundle> = {}, now: Date = new Date()): VehicleSummary[] {
  const key = (v: VehicleSummary): [number, number] => {
    const job = vehicleNextJob(v, bundles[v['@id']], now);
    if (job?.immediate) return [STATE_RANK.over, 0];
    if (!job) return [3, 0];
    return [STATE_RANK[job.state], job.days ?? Number.POSITIVE_INFINITY];
  };
  return vehicles
    .map((v, i) => ({ v, i, k: key(v) }))
    .sort((a, b) => a.k[0] - b.k[0] || a.k[1] - b.k[1] || a.i - b.i)
    .map((x) => x.v);
}

/** What the projection slot shows when nothing is summoned: the most pressing job, if any. */
export function urgentJob(modules: Modules): JobItem | null {
  return modules.aFaire[0] ?? modules.bientot[0] ?? null;
}

export interface ParcDue {
  vehicleIri: Iri;
  job: JobItem;
}

/** Every overdue, immediate or due-soon job across the parc, most urgent first. */
export function parcDues(bundles: VehicleBundle[], now: Date, opts: { excludeHeadline?: boolean } = {}): ParcDue[] {
  const rows: Array<ParcDue & { rank: number; order: number }> = [];
  for (const b of bundles) {
    const m = deriveModules(b, now);
    const headline = opts.excludeHeadline ? urgentJob(m) : null;
    const inspections = b.forecast.due.filter((d) => isInspection(d.type) && stateOfDue(d) === 'due').map((d) => jobFromDue(d, undefined));
    for (const job of [...m.aFaire, ...m.bientot.filter((j) => j.state === 'due'), ...inspections]) {
      if (headline && job.id === headline.id) continue;
      const rank = job.state === 'over' ? 0 : job.immediate ? 1 : 2;
      rows.push({ vehicleIri: b.vehicle['@id'], job, rank, order: rows.length });
    }
  }
  const days = (j: JobItem) => j.days ?? Number.POSITIVE_INFINITY;
  return rows
    .sort((a, b) => a.rank - b.rank || days(a.job) - days(b.job) || a.order - b.order)
    .map(({ vehicleIri, job }) => ({ vehicleIri, job }));
}

/**
 * The one next job for a vehicle, shared by the parc row and the car page: once the bundle is loaded it is
 * the car page's own "most urgent" (open checklist + forecast); before that, the listing's summary.
 */
export function vehicleNextJob(vehicle: VehicleSummary, bundle: VehicleBundle | undefined, now: Date): NextJob | null {
  if (!bundle) return nextJobOf(vehicle);
  const job = urgentJob(deriveModules(bundle, now));
  if (!job) return null;
  return {
    type: job.type,
    label: job.label ?? serviceLabelFallback(job.type, vehicle),
    state: job.state,
    immediate: job.immediate,
    remaining: job.remaining,
    days: job.days,
    dueDate: job.dueDate,
  };
}

// The listing's label when it names the same service, else the raw type for the UI to label.
function serviceLabelFallback(type: string, vehicle: VehicleSummary): string {
  return vehicle.nextDue && canonicalService(vehicle.nextDue.type) === canonicalService(type) ? vehicle.nextDue.label : type;
}

/** Bientôt on the Entretien screen: the next `limit` jobs after the most urgent, the inspection folded in. */
export function entretienNext(modules: Modules, limit: number, urgentShown = true): { jobs: JobItem[]; more: number } {
  const urgent = urgentShown ? urgentJob(modules) : null;
  const rest = [...modules.aFaire, ...modules.bientot].filter((j) => j !== urgent);
  const ct = modules.controleTechnique;
  if (ct?.dueAt && ct.state !== 'unknown' && !rest.some((j) => isInspection(j.type)) && !(urgent && isInspection(urgent.type))) {
    const job: JobItem = { id: 'inspection', type: 'inspection', label: null, state: ct.state, immediate: false, remaining: null, dueReading: null, dueDate: ct.dueAt, days: ct.days, taskId: null, parts: [] };
    const at = rest.findIndex((j) => j.state !== 'over' && !j.immediate && (j.days ?? Number.POSITIVE_INFINITY) > (ct.days ?? Number.POSITIVE_INFINITY));
    rest.splice(at < 0 ? rest.length : at, 0, job);
  }
  return { jobs: rest.slice(0, limit), more: Math.max(0, rest.length - limit) };
}

export interface ToOrder {
  job: JobItem;
  parts: PlannedPart[];
}

export interface Fitted {
  record: MaintenanceRecord;
  parts: PlannedPart[];
}

/** The Pièces screen: parts to order for jobs that are due, then parts already fitted, by visit. */
export function piecesLists(bundle: VehicleBundle, modules: Modules): { toOrder: ToOrder[]; fitted: Fitted[] } {
  const toOrder = [...modules.aFaire, ...modules.bientot]
    .filter((j) => (j.state !== 'planned' || j.immediate) && j.parts.length > 0)
    .map((job) => ({ job, parts: job.parts }));
  const fitted = [...bundle.records]
    .map((record) => ({ record, parts: record.items.flatMap((i) => i.parts) }))
    .filter((f) => f.parts.length > 0)
    .sort((a, b) => (a.record.performedAt < b.record.performedAt ? 1 : a.record.performedAt > b.record.performedAt ? -1 : 0));
  return { toOrder, fitted };
}

export interface JobList {
  jobs: JobItem[];
  more: number;
}

/**
 * The Entretien screen's two lists: À faire (overdue or now) and Bientôt (the rest, the inspection
 * folded in). The job the slot already shows is left out of both; when the slot shows something else,
 * every overdue job stays under À faire rather than sliding into Bientôt.
 */
export function entretienLists(modules: Modules, limit: number, urgentShown: boolean): { aFaire: JobList; bientot: JobList } {
  const urgent = urgentShown ? urgentJob(modules) : null;
  const pressing = modules.aFaire.filter((j) => j !== urgent);
  const later = entretienNext({ ...modules, aFaire: [] }, Number.POSITIVE_INFINITY, false).jobs.filter((j) => j !== urgent);
  const cap = (jobs: JobItem[]): JobList => ({ jobs: jobs.slice(0, limit), more: Math.max(0, jobs.length - limit) });
  return { aFaire: cap(pressing), bientot: cap(later) };
}
