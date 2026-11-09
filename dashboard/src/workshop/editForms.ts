import { coerceInt, defaultUsageUnit, emptyToNull } from '../edit';
import type { MaintenanceRecordInput, ServiceRuleInput, TaskEdit, TaskPartInput, VehiclePatch } from '../garage';
import { canonicalService } from '../labels';
import { centsToEurosInput, parseEuros } from '../records';
import type { ForecastDue, MaintenanceRecord, PlannedPart, PlannedTask, ServiceAction, ServiceRule, UsageUnit, VehicleSummary } from '../types';
import { maintUnitFor } from '../units';
import { performer } from './shelf';

export type Checked<K extends string, T> = ({ ok: true } & { [P in K]: T }) | { ok: false; errors: string[] };

// ── Visits ──────────────────────────────────────────────────────────────────────

export interface PartDraft {
  ref: string;
  brand: string;
  label: string;
  qty: string;
  price: string;
}

export interface ItemDraft {
  type: string;
  label: string;
  parts: PartDraft[];
}

export interface VisitDraft {
  performedAt: string;
  reading: string;
  label: string;
  diy: boolean;
  performer: string;
  cost: string;
  notes: string;
  items: ItemDraft[];
}

export const blankPart = (): PartDraft => ({ ref: '', brand: '', label: '', qty: '', price: '' });
export const blankItem = (): ItemDraft => ({ type: '', label: '', parts: [] });

export function visitDraft(record: MaintenanceRecord | null, today: string): VisitDraft {
  if (!record) return { performedAt: today, reading: '', label: '', diy: false, performer: '', cost: '', notes: '', items: [blankItem()] };
  const by = performer(record.performedBy);
  return {
    performedAt: record.performedAt,
    reading: record.usageValue != null ? String(record.usageValue) : '',
    label: record.label ?? '',
    diy: by?.self === true,
    performer: by && !by.self ? by.name : '',
    cost: centsToEurosInput(record.costCents),
    notes: record.notes ?? '',
    items: record.items.length
      ? record.items.map((it) => ({
          type: it.type,
          label: it.label ?? '',
          parts: it.parts.map((p) => ({ ref: p.ref ?? '', brand: p.brand ?? '', label: p.label ?? '', qty: p.qty != null ? String(p.qty) : '', price: centsToEurosInput(p.priceCents) })),
        }))
      : [blankItem()],
  };
}

/** A typed amount: blank is no amount, anything else must read as euros. */
function euros(raw: string): { ok: boolean; value: number | null } {
  if (raw.trim() === '') return { ok: true, value: null };
  const value = parseEuros(raw);
  return { ok: value !== null, value };
}

export function visitInput(draft: VisitDraft, unit: string): Checked<'input', MaintenanceRecordInput> {
  const errors: string[] = [];
  if (draft.performedAt === '') errors.push('date');
  const items = draft.items.filter((it) => it.type.trim() !== '');
  if (items.length === 0) errors.push('items');
  const cost = euros(draft.cost);
  if (!cost.ok) errors.push('cost');
  let badPrice = false;
  const mapped = items.map((it) => ({
    type: it.type.trim(),
    label: emptyToNull(it.label),
    parts: it.parts
      .filter((p) => p.ref.trim() !== '' || p.label.trim() !== '')
      .map((p) => {
        const price = euros(p.price);
        if (!price.ok) badPrice = true;
        return { ref: p.ref.trim(), brand: emptyToNull(p.brand), label: emptyToNull(p.label), qty: coerceInt(p.qty), price: price.value };
      }),
  }));
  if (badPrice) errors.push('price');
  if (errors.length > 0) return { ok: false, errors };
  const usageValue = coerceInt(draft.reading);
  return {
    ok: true,
    input: {
      performedAt: draft.performedAt,
      label: emptyToNull(draft.label),
      usageValue,
      usageUnit: usageValue != null ? unit : null,
      cost: cost.value,
      performedBy: draft.diy ? 'self' : emptyToNull(draft.performer),
      notes: emptyToNull(draft.notes),
      items: mapped,
    },
  };
}

/** The reading axis of a visit: a logged visit keeps its own, a new one takes the vehicle's (maintenance spelling). */
export function visitUnit(record: MaintenanceRecord | null, vehicleUnit: string | null | undefined, vehicleType: string): string {
  if (record?.usageUnit) return record.usageUnit;
  return maintUnitFor(vehicleUnit ?? defaultUsageUnit(vehicleType));
}

export const BILL_TYPES = ['application/pdf', 'image/jpeg', 'image/png'] as const;

export function acceptsBill(contentType: string): boolean {
  return (BILL_TYPES as readonly string[]).includes(contentType);
}

// ── Service rules ───────────────────────────────────────────────────────────────

export interface RuleDraft {
  type: string;
  action: ServiceAction;
  component: string | null;
  usage: string;
  months: string;
  first: string;
}

export interface RuleSeed {
  type: string;
  action?: ServiceAction | null;
  component?: string | null;
}

export function rulesForType(rules: ServiceRule[], type: string): ServiceRule[] {
  const want = canonicalService(type);
  return rules.filter((r) => canonicalService(r.type) === want);
}

export function ruleSeedFor(due: ForecastDue[], type: string): RuleSeed {
  const want = canonicalService(type);
  const hit = due.find((d) => canonicalService(d.type) === want);
  return { type, action: hit?.action ?? null, component: hit?.component ?? null };
}

export function ruleDraft(rule: ServiceRule | null, seed: RuleSeed | null): RuleDraft {
  if (rule) {
    return {
      type: rule.type,
      action: rule.action,
      component: rule.component ?? null,
      usage: rule.everyUsage ? String(rule.everyUsage.value) : '',
      months: rule.everyMonths != null ? String(rule.everyMonths) : '',
      first: rule.firstUsageValue != null ? String(rule.firstUsageValue) : '',
    };
  }
  return { type: seed?.type ?? '', action: seed?.action ?? 'change', component: seed?.component ?? null, usage: '', months: '', first: '' };
}

export function ruleInput(draft: RuleDraft, unit: string): Checked<'input', ServiceRuleInput> {
  const usage = coerceInt(draft.usage);
  const months = coerceInt(draft.months);
  const first = coerceInt(draft.first);
  const errors: string[] = [];
  if (draft.type.trim() === '') errors.push('type');
  else if (usage === null && months === null && first === null) errors.push('cadence');
  if (errors.length > 0) return { ok: false, errors };
  return {
    ok: true,
    input: {
      type: draft.type.trim(),
      action: draft.action,
      component: draft.component,
      everyUsageValue: usage,
      everyUsageUnit: usage !== null ? (unit as UsageUnit) : null,
      everyMonths: months,
      firstUsageValue: first,
    },
  };
}

/** The schedule is keyed on (type, action, component): changing one of them writes a new rule. */
export function ruleIdentityChanged(rule: ServiceRule, input: ServiceRuleInput): boolean {
  return rule.type !== input.type || rule.action !== input.action || (rule.component ?? null) !== (input.component ?? null);
}

// ── Planned tasks ───────────────────────────────────────────────────────────────

export interface TaskPartDraft extends PartDraft {
  id: string | null;
}

export interface TaskDraft {
  type: string;
  label: string;
  target: string;
  targetUnit: string | null;
  dueDate: string;
  parts: TaskPartDraft[];
}

export function taskDraft(task: PlannedTask | null): TaskDraft {
  if (!task) return { type: '', label: '', target: '', targetUnit: null, dueDate: '', parts: [] };
  return {
    type: task.type,
    label: task.label ?? '',
    target: task.target ? String(task.target.value) : '',
    targetUnit: task.target?.unit ?? null,
    dueDate: task.dueDate ?? '',
    parts: task.parts.flatMap((p) =>
      p.id ? [{ id: p.id, ref: p.ref ?? '', brand: p.brand ?? '', label: p.label ?? '', qty: p.qty != null ? String(p.qty) : '', price: centsToEurosInput(p.priceCents) }] : [],
    ),
  };
}

export function taskInput(draft: TaskDraft, unit: string): Checked<'fields', TaskEdit & { type: string }> {
  if (draft.type.trim() === '') return { ok: false, errors: ['type'] };
  const n = coerceInt(draft.target);
  const target = n !== null ? { value: n, unit: draft.targetUnit ?? unit } : null;
  return { ok: true, fields: { type: draft.type.trim(), label: emptyToNull(draft.label), target, dueDate: target ? null : draft.dueDate || null } };
}

export interface TaskPartOps {
  add: TaskPartInput[];
  update: Array<{ id: string; fields: TaskPartInput }>;
  remove: string[];
}

function samePart(p: PlannedPart, f: TaskPartInput): boolean {
  return (p.ref ?? '') === f.ref && (p.brand ?? null) === f.brand && (p.label ?? null) === f.label && (p.qty ?? null) === f.qty && (p.priceCents ?? null) === f.priceCents;
}

export function taskPartOps(original: PlannedPart[], drafts: TaskPartDraft[]): Checked<'ops', TaskPartOps> {
  const ops: TaskPartOps = { add: [], update: [], remove: [] };
  let badPrice = false;
  const kept = new Set<string>();
  for (const d of drafts) {
    if (d.ref.trim() === '' && d.label.trim() === '') continue;
    const price = euros(d.price);
    if (!price.ok) badPrice = true;
    const fields: TaskPartInput = {
      ref: d.ref.trim(),
      brand: emptyToNull(d.brand),
      label: emptyToNull(d.label),
      qty: coerceInt(d.qty),
      priceCents: price.value !== null ? Math.round(price.value * 100) : null,
    };
    if (d.id === null) {
      ops.add.push(fields);
      continue;
    }
    kept.add(d.id);
    const before = original.find((p) => p.id === d.id);
    if (before && !samePart(before, fields)) ops.update.push({ id: d.id, fields });
  }
  if (badPrice) return { ok: false, errors: ['price'] };
  ops.remove = original.flatMap((p) => (p.id && !kept.has(p.id) ? [p.id] : []));
  return { ok: true, ops };
}

// ── Vehicle ─────────────────────────────────────────────────────────────────────

export const VEHICLE_TYPES = ['car', 'moto', 'cv', 'groundscare'] as const;

export interface VehicleDraft {
  make: string;
  model: string;
  year: string;
  engineVariant: string;
  engineCode: string;
  kType: string;
  plate: string;
  usage: string;
  vehicleType: string;
}

export function vehicleDraft(v: VehicleSummary): VehicleDraft {
  return {
    make: v.make ?? '',
    model: v.model ?? '',
    year: v.year != null ? String(v.year) : '',
    engineVariant: v.engineVariant ?? '',
    engineCode: v.engineCode ?? '',
    kType: v.kType != null ? String(v.kType) : '',
    plate: v.plate ?? '',
    usage: v.usageValue != null ? String(v.usageValue) : '',
    vehicleType: v.vehicleType,
  };
}

export function vehiclePatch(v: VehicleSummary, d: VehicleDraft): Checked<'patch', VehiclePatch> {
  const errors: string[] = [];
  const patch: VehiclePatch = {};
  for (const key of ['make', 'model'] as const) {
    const next = d[key].trim();
    if (next === (v[key] ?? '')) continue;
    if (next === '') errors.push(key);
    else patch[key] = next;
  }
  for (const key of ['engineVariant', 'engineCode', 'plate'] as const) {
    const next = emptyToNull(d[key]);
    if (next !== (v[key] ?? null)) patch[key] = next;
  }
  for (const key of ['year', 'kType'] as const) {
    const next = coerceInt(d[key]);
    if (next !== (v[key] ?? null)) patch[key] = next;
  }
  if (d.vehicleType !== v.vehicleType) patch.vehicleType = d.vehicleType;
  const usage = coerceInt(d.usage);
  if (usage === null && v.usageValue != null) errors.push('usage');
  else if (usage !== null && usage !== v.usageValue) {
    patch.usageValue = usage;
    if (!v.usageUnit) patch.usageUnit = defaultUsageUnit(d.vehicleType);
  }
  if (errors.length > 0) return { ok: false, errors };
  return { ok: true, patch };
}

export interface NewVehicleDraft {
  make: string;
  model: string;
  plate: string;
  vehicleType: string;
}

export function newVehiclePatch(d: NewVehicleDraft): Checked<'patch', VehiclePatch> {
  const errors = (['make', 'model'] as const).filter((k) => d[k].trim() === '');
  if (errors.length > 0) return { ok: false, errors };
  const patch: VehiclePatch = { make: d.make.trim(), model: d.model.trim(), vehicleType: d.vehicleType };
  const plate = emptyToNull(d.plate);
  if (plate) patch.plate = plate;
  return { ok: true, patch };
}
