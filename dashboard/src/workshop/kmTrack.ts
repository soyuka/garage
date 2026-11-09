import { dueSignal } from '../derive';
import { canonicalService } from '../labels';
import type { ForecastDue, PlannedTask, VehicleBundle } from '../types';

export type TrackUnit = 'km' | 'mi' | 'h';

export type TickState = 'done' | 'planned' | 'due' | 'over';

export interface Tick {
  id: string;
  value: number;
  state: TickState;
  types: string[];
  /** The operator's own wording (visit or task label), else null — the UI falls back to the service label. */
  label: string | null;
  date: string | null;
  /** Placed from the usage slope, not from a reading. */
  estimated: boolean;
}

export interface KmTrack {
  unit: TrackUnit;
  min: number;
  max: number;
  today: number | null;
  done: Tick[];
  planned: Tick[];
  /** Planned work with a date but no way onto the axis (no slope to project with). */
  unplaced: Tick[];
  empty: boolean;
}

const DAY_MS = 86_400_000;
const SOON_DAYS = 30;

export function normalizeUnit(unit: string | null | undefined): TrackUnit {
  const u = (unit ?? '').trim().toLowerCase();
  if (u === 'h' || u === 'hours' || u === 'hour') return 'h';
  if (u === 'mi' || u === 'miles') return 'mi';
  return 'km';
}

function dayOf(iso: string): number {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  return m ? Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) / DAY_MS : NaN;
}

function today(now: Date): number {
  return Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()) / DAY_MS;
}

function readings(bundle: VehicleBundle, unit: TrackUnit): Array<{ day: number; value: number }> {
  return bundle.records
    .filter((r) => r.usageValue != null && normalizeUnit(r.usageUnit ?? bundle.vehicle.usageUnit) === unit)
    .map((r) => ({ day: dayOf(r.performedAt), value: r.usageValue as number }))
    .filter((p) => !Number.isNaN(p.day));
}

function currentReading(bundle: VehicleBundle, unit: TrackUnit): number | null {
  if (bundle.vehicle.usageValue != null) return bundle.vehicle.usageValue;
  const points = readings(bundle, unit);
  return points.length === 0 ? null : Math.max(...points.map((p) => p.value));
}

/** Usage per day, read from the oldest reading to today's. Null when it cannot be read honestly. */
export function usageSlope(bundle: VehicleBundle, now: Date): number | null {
  const unit = normalizeUnit(bundle.vehicle.usageUnit);
  const points = readings(bundle, unit);
  if (bundle.vehicle.usageValue != null) points.push({ day: today(now), value: bundle.vehicle.usageValue });
  if (points.length < 2) return null;
  points.sort((a, b) => a.day - b.day);
  const first = points[0];
  const last = points[points.length - 1];
  const span = last.day - first.day;
  const grew = last.value - first.value;
  return span > 0 && grew > 0 ? grew / span : null;
}

function stateOfDue(due: ForecastDue): TickState {
  if (due.overdue) return 'over';
  return dueSignal(due) === 'amber' ? 'due' : 'planned';
}

function stateOfDays(days: number): TickState {
  if (days < 0) return 'over';
  return days <= SOON_DAYS ? 'due' : 'planned';
}

function bounds(values: number[], unit: TrackUnit, today: number | null): { min: number; max: number } {
  let lo = Math.min(...values);
  let hi = Math.max(...values);
  // A thin history (one visit, one due at today) would zoom the axis to a few hundred km; keep a
  // readable window around today, mostly behind it so the history side has room.
  const reference = today ?? hi;
  const minSpan = unit === 'h' ? Math.max(40, reference * 0.1) : Math.max(10000, reference * 0.06);
  if (hi - lo < minSpan) {
    const missing = minSpan - (hi - lo);
    lo -= missing * 0.6;
    hi += missing * 0.4;
  }
  const floorSpan = unit === 'h' ? 20 : 2000;
  const span = Math.max(hi - lo, floorSpan);
  const pad = span * 0.04;
  const step = span >= 20000 ? 10000 : span >= 2000 ? 1000 : span >= 200 ? 100 : 10;
  return { min: Math.max(0, Math.floor((lo - pad) / step) * step), max: Math.ceil((hi + pad) / step) * step };
}

export function kmTrack(bundle: VehicleBundle, now: Date): KmTrack {
  const unit = normalizeUnit(bundle.vehicle.usageUnit);
  const at = currentReading(bundle, unit);
  const slope = usageSlope(bundle, now);
  const day = today(now);
  const project = (iso: string | null): number | null => {
    if (!iso || at === null || slope === null) return null;
    const d = dayOf(iso);
    if (Number.isNaN(d)) return null;
    const value = Math.round(at + (d - day) * slope);
    return value >= 0 ? value : null;
  };

  const done: Tick[] = [];
  for (const r of bundle.records) {
    const types = r.items.map((i) => i.type);
    const label = r.label?.trim() ? r.label.trim() : null;
    const sameUnit = normalizeUnit(r.usageUnit ?? bundle.vehicle.usageUnit) === unit;
    if (r.usageValue != null) {
      if (sameUnit) done.push({ id: r.id, value: r.usageValue, state: 'done', types, label, date: r.performedAt, estimated: false });
      continue;
    }
    const value = project(r.performedAt);
    if (value !== null) done.push({ id: r.id, value, state: 'done', types, label, date: r.performedAt, estimated: true });
  }

  const planned: Tick[] = [];
  const unplaced: Tick[] = [];
  const forecastTypes = new Set<string>();
  for (const d of bundle.forecast.due) {
    forecastTypes.add(canonicalService(d.type));
    const base = { id: d.type, state: stateOfDue(d), types: [d.type], label: null };
    if (d.usage && normalizeUnit(d.usage.unit) === unit) {
      planned.push({ ...base, value: d.usage.due, date: d.projectedDueAt, estimated: false });
      continue;
    }
    const date = d.dueAt ?? d.projectedDueAt;
    const value = project(date);
    if (value !== null) planned.push({ ...base, value, date, estimated: true });
    else unplaced.push({ ...base, value: 0, date, estimated: true });
  }

  const tasks = (bundle.vehicle.plannedTasks ?? []) as PlannedTask[];
  for (const t of tasks) {
    if (t.status !== 'open' || forecastTypes.has(canonicalService(t.type))) continue;
    const base = { id: t.id, types: [t.type], label: t.label?.trim() ? t.label.trim() : null };
    if (t.target && normalizeUnit(t.target.unit ?? bundle.vehicle.usageUnit) === unit) {
      const value = t.target.value;
      let state: TickState = 'planned';
      if (at !== null && value <= at) state = 'over';
      else if (at !== null && slope !== null) state = stateOfDays((value - at) / slope);
      planned.push({ ...base, value, state, date: t.dueDate ?? null, estimated: false });
      continue;
    }
    if (t.dueDate) {
      const state = stateOfDays(dayOf(t.dueDate) - day);
      const value = project(t.dueDate);
      if (value !== null) planned.push({ ...base, value, state, date: t.dueDate, estimated: true });
      else unplaced.push({ ...base, value: 0, state, date: t.dueDate, estimated: true });
      continue;
    }
    if (at !== null) planned.push({ ...base, value: at, state: 'due', date: null, estimated: false });
    else unplaced.push({ ...base, value: 0, state: 'due', date: null, estimated: false });
  }

  done.sort((a, b) => a.value - b.value);
  planned.sort((a, b) => a.value - b.value);

  const values = [...done, ...planned].map((t) => t.value);
  if (at !== null) values.push(at);
  if (values.length === 0) return { unit, min: 0, max: 0, today: null, done, planned, unplaced, empty: true };
  return { unit, ...bounds(values, unit, at), today: at, done, planned, unplaced, empty: false };
}
