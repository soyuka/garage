import { describe, expect, it } from 'vitest';
import type { ForecastDue, MaintenanceRecord, PlannedTask, ServiceRule, VehicleSummary } from '../types';
import {
  acceptsBill,
  blankPart,
  newVehiclePatch,
  ruleDraft,
  ruleInput,
  ruleSeedFor,
  rulesForType,
  taskDraft,
  taskInput,
  taskPartOps,
  vehicleDraft,
  vehiclePatch,
  visitDraft,
  visitInput,
  visitUnit,
} from './editForms';

const record: MaintenanceRecord = {
  id: '/garage/maintenance-records/01R',
  items: [{ type: 'brake_pads', label: 'Avant', parts: [{ ref: 'P 68 050', brand: 'Brembo', label: 'Plaquettes', qty: 2, priceCents: 4590 }] }],
  performedAt: '2025-11-12',
  usageValue: 139800,
  usageUnit: 'km',
  performedBy: 'Garage du Centre',
  costCents: 31240,
  notes: 'RAS',
  source: 'user',
};

describe('visitDraft', () => {
  it('seeds a blank visit dated today with one empty service line', () => {
    const d = visitDraft(null, '2026-11-09');
    expect(d.performedAt).toBe('2026-11-09');
    expect(d.items).toHaveLength(1);
    expect(d.items[0].type).toBe('');
    expect(d.items[0].parts).toEqual([]);
    expect(d.diy).toBe(false);
  });

  it('prefills an existing visit, cents shown as comma euros', () => {
    const d = visitDraft(record, '2026-11-09');
    expect(d.reading).toBe('139800');
    expect(d.cost).toBe('312,40');
    expect(d.performer).toBe('Garage du Centre');
    expect(d.items[0].parts[0]).toEqual({ ref: 'P 68 050', brand: 'Brembo', label: 'Plaquettes', qty: '2', price: '45,90' });
  });

  it('reads a self marker as "fait soi-même"', () => {
    const d = visitDraft({ ...record, performedBy: 'self' }, '2026-11-09');
    expect(d.diy).toBe(true);
    expect(d.performer).toBe('');
  });
});

describe('visitInput', () => {
  it('maps the form to the write shape: euros (major units) for cost and price, the vehicle unit', () => {
    const d = visitDraft(record, '2026-11-09');
    const out = visitInput(d, 'km');
    expect(out.ok).toBe(true);
    if (!out.ok) return;
    expect(out.input).toEqual({
      performedAt: '2025-11-12',
      label: null,
      usageValue: 139800,
      usageUnit: 'km',
      cost: 312.4,
      performedBy: 'Garage du Centre',
      notes: 'RAS',
      items: [{ type: 'brake_pads', label: 'Avant', parts: [{ ref: 'P 68 050', brand: 'Brembo', label: 'Plaquettes', qty: 2, price: 45.9 }] }],
    });
  });

  it('sends "self" when done by the owner, and clears the reading unit without a reading', () => {
    const d = { ...visitDraft(null, '2026-11-09'), diy: true, performer: 'ignored', items: [{ type: 'oil_change', label: '', parts: [] }] };
    const out = visitInput(d, 'hours');
    expect(out.ok && out.input.performedBy).toBe('self');
    expect(out.ok && out.input.usageValue).toBeNull();
    expect(out.ok && out.input.usageUnit).toBeNull();
  });

  it('accepts a thin-space grouped reading and a comma decimal', () => {
    const d = { ...visitDraft(null, '2026-11-09'), reading: '142 000', cost: '1 249,5', items: [{ type: 'oil_change', label: '', parts: [] }] };
    const out = visitInput(d, 'km');
    expect(out.ok && out.input.usageValue).toBe(142000);
    expect(out.ok && out.input.cost).toBe(1249.5);
  });

  it('drops blank part rows and blank service lines', () => {
    const d = {
      ...visitDraft(null, '2026-11-09'),
      items: [
        { type: 'oil_change', label: '', parts: [blankPart(), { ref: '', brand: '', label: 'Huile 5W30', qty: '5', price: '' }] },
        { type: '', label: '', parts: [] },
      ],
    };
    const out = visitInput(d, 'km');
    expect(out.ok && out.input.items).toEqual([{ type: 'oil_change', label: null, parts: [{ ref: '', brand: null, label: 'Huile 5W30', qty: 5, price: null }] }]);
  });

  it('refuses a visit without a date or without any service', () => {
    const noDate = visitInput({ ...visitDraft(null, ''), items: [{ type: 'oil_change', label: '', parts: [] }] }, 'km');
    expect(noDate).toEqual({ ok: false, errors: ['date'] });
    const noItem = visitInput(visitDraft(null, '2026-11-09'), 'km');
    expect(noItem).toEqual({ ok: false, errors: ['items'] });
  });

  it('refuses money it cannot read rather than sending null', () => {
    const d = { ...visitDraft(null, '2026-11-09'), cost: 'beaucoup', items: [{ type: 'oil_change', label: '', parts: [{ ref: 'X', brand: '', label: '', qty: '', price: 'abc' }] }] };
    expect(visitInput(d, 'km')).toEqual({ ok: false, errors: ['cost', 'price'] });
  });
});

describe('visitUnit', () => {
  it('keeps a logged visit on its own unit, else the vehicle axis in maintenance spelling', () => {
    expect(visitUnit(record, 'km', 'car')).toBe('km');
    expect(visitUnit(null, 'h', 'groundscare')).toBe('hours');
    expect(visitUnit(null, null, 'groundscare')).toBe('hours');
    expect(visitUnit(null, null, 'car')).toBe('km');
  });
});

describe('acceptsBill', () => {
  it('takes PDF, JPEG and PNG only', () => {
    expect(acceptsBill('application/pdf')).toBe(true);
    expect(acceptsBill('image/jpeg')).toBe(true);
    expect(acceptsBill('image/png')).toBe(true);
    expect(acceptsBill('image/gif')).toBe(false);
    expect(acceptsBill('')).toBe(false);
  });
});

const rule: ServiceRule = {
  id: '01RULE',
  vehicleId: '/garage/vehicles/01V',
  type: 'oil_change',
  action: 'change',
  component: null,
  everyUsage: { value: 15000, unit: 'km' },
  everyMonths: 12,
  source: 'user',
  verified: false,
};

describe('rulesForType', () => {
  it('matches through the service aliases', () => {
    const plugs = { ...rule, id: '02', type: 'spark_plugs' };
    expect(rulesForType([rule, plugs], 'spark_plug')).toEqual([plugs]);
  });
});

describe('ruleDraft / ruleInput', () => {
  it('prefills a rule and writes it back unchanged', () => {
    const d = ruleDraft(rule, null);
    expect(d).toMatchObject({ type: 'oil_change', action: 'change', usage: '15000', months: '12' });
    expect(ruleInput(d, 'km')).toEqual({
      ok: true,
      input: { type: 'oil_change', action: 'change', component: null, everyUsageValue: 15000, everyUsageUnit: 'km', everyMonths: 12, firstUsageValue: null },
    });
  });

  it('seeds a new rule from the job it was opened on', () => {
    expect(ruleDraft(null, { type: 'timing_belt', action: 'replace', component: 'timing_belt' })).toMatchObject({ type: 'timing_belt', action: 'replace', component: 'timing_belt', usage: '', months: '' });
  });

  it('needs a type and at least one cadence', () => {
    expect(ruleInput({ ...ruleDraft(null, null), type: 'coolant' }, 'km')).toEqual({ ok: false, errors: ['cadence'] });
    expect(ruleInput({ ...ruleDraft(null, null), months: '24' }, 'km')).toEqual({ ok: false, errors: ['type'] });
  });

  it('writes engine-hours in the maintenance spelling and drops the unit without a usage cadence', () => {
    const out = ruleInput({ ...ruleDraft(null, null), type: 'oil_change', usage: '100' }, 'hours');
    expect(out.ok && out.input.everyUsageUnit).toBe('hours');
    const monthsOnly = ruleInput({ ...ruleDraft(null, null), type: 'inspection', months: '24' }, 'km');
    expect(monthsOnly.ok && monthsOnly.input.everyUsageUnit).toBeNull();
  });
});

const task: PlannedTask = {
  id: '/garage/planned-tasks/01T',
  type: 'clutch',
  label: 'Kit embrayage',
  target: { value: 160000, unit: 'km' },
  dueDate: null,
  status: 'open',
  parts: [
    { id: 'p1', ref: '826317', brand: 'Valeo', label: 'Kit embrayage', qty: 1, priceCents: 28900 },
    { id: 'p2', ref: '415 0180 10', brand: 'LuK', label: 'Volant', qty: 1 },
    { ref: '', label: 'Huile de boîte', qty: 1 },
  ],
};

describe('taskDraft / taskInput', () => {
  it('a usage target wins over a date, which is cleared', () => {
    const d = { ...taskDraft(task), dueDate: '2027-01-01' };
    expect(taskInput(d, 'km')).toEqual({ ok: true, fields: { type: 'clutch', label: 'Kit embrayage', target: { value: 160000, unit: 'km' }, dueDate: null } });
  });

  it('a date alone is kept, the target cleared', () => {
    const d = { ...taskDraft(task), target: '', dueDate: '2027-01-01' };
    expect(taskInput(d, 'km')).toEqual({ ok: true, fields: { type: 'clutch', label: 'Kit embrayage', target: null, dueDate: '2027-01-01' } });
  });

  it('keeps the unit the task was pegged on', () => {
    const d = taskDraft({ ...task, target: { value: 300, unit: 'hours' } });
    const out = taskInput(d, 'km');
    expect(out.ok && out.fields.target).toEqual({ value: 300, unit: 'hours' });
  });

  it('needs a type', () => {
    expect(taskInput({ ...taskDraft(null), type: '' }, 'km')).toEqual({ ok: false, errors: ['type'] });
  });

  it('only stored lines are editable: derived lines (no id) stay out of the draft', () => {
    expect(taskDraft(task).parts.map((p) => p.id)).toEqual(['p1', 'p2']);
  });
});

describe('taskPartOps', () => {
  it('diffs the edited lines into add / update / remove, prices in integer cents', () => {
    const d = taskDraft(task);
    const parts = [
      { ...d.parts[0], price: '279,90' },
      { id: null, ref: 'NEW-1', brand: '', label: 'Butée', qty: '1', price: '42' },
      { id: null, ref: '', brand: '', label: '', qty: '', price: '' },
    ];
    const out = taskPartOps(task.parts, parts);
    expect(out).toEqual({
      ok: true,
      ops: {
        add: [{ ref: 'NEW-1', brand: null, label: 'Butée', qty: 1, priceCents: 4200 }],
        update: [{ id: 'p1', fields: { ref: '826317', brand: 'Valeo', label: 'Kit embrayage', qty: 1, priceCents: 27990 } }],
        remove: ['p2'],
      },
    });
  });

  it('leaves an untouched line alone', () => {
    const d = taskDraft(task);
    expect(taskPartOps(task.parts, d.parts)).toEqual({ ok: true, ops: { add: [], update: [], remove: [] } });
  });

  it('refuses an unreadable price', () => {
    const d = taskDraft(task);
    expect(taskPartOps(task.parts, [{ ...d.parts[0], price: 'cher' }, d.parts[1]])).toEqual({ ok: false, errors: ['price'] });
  });
});

const vehicle: VehicleSummary = {
  '@id': '/garage/vehicles/01V',
  make: 'Renault',
  model: 'Espace IV',
  year: 2006,
  engineVariant: '2.0 dCi',
  engineCode: 'M9R',
  kType: null,
  vehicleType: 'car',
  plate: 'AB-123-CD',
  usageValue: 247388,
  usageUnit: 'km',
  source: 'user',
  factCount: 0,
  addedAt: '2025-01-01T00:00:00+00:00',
};

describe('vehicleDraft / vehiclePatch', () => {
  it('sends only what changed', () => {
    const d = { ...vehicleDraft(vehicle), plate: 'EF-456-GH', usage: '248 000' };
    expect(vehiclePatch(vehicle, d)).toEqual({ ok: true, patch: { plate: 'EF-456-GH', usageValue: 248000 } });
  });

  it('clears an optional field with null and sends nothing for an unchanged form', () => {
    expect(vehiclePatch(vehicle, { ...vehicleDraft(vehicle), engineCode: ' ' })).toEqual({ ok: true, patch: { engineCode: null } });
    expect(vehiclePatch(vehicle, vehicleDraft(vehicle))).toEqual({ ok: true, patch: {} });
  });

  it('writes the garage-side unit spelling and pins the unit with a first reading', () => {
    const blank = { ...vehicle, usageValue: null, usageUnit: null, vehicleType: 'groundscare' };
    expect(vehiclePatch(blank, { ...vehicleDraft(blank), usage: '120' })).toEqual({ ok: true, patch: { usageValue: 120, usageUnit: 'h' } });
  });

  it('refuses to blank the make or the model, which the API requires', () => {
    expect(vehiclePatch(vehicle, { ...vehicleDraft(vehicle), make: '' })).toEqual({ ok: false, errors: ['make'] });
  });

  it('cannot clear a reading (the API keeps it), so an emptied reading is refused', () => {
    expect(vehiclePatch(vehicle, { ...vehicleDraft(vehicle), usage: '' })).toEqual({ ok: false, errors: ['usage'] });
  });
});

describe('newVehiclePatch', () => {
  it('needs a make and a model, plate optional', () => {
    expect(newVehiclePatch({ make: 'Peugeot', model: '206', plate: '', vehicleType: 'car' })).toEqual({ ok: true, patch: { make: 'Peugeot', model: '206', vehicleType: 'car' } });
    expect(newVehiclePatch({ make: '', model: '', plate: 'X', vehicleType: 'car' })).toEqual({ ok: false, errors: ['make', 'model'] });
  });
});

describe('ruleSeedFor', () => {
  const due = (type: string, action: ForecastDue['action'], component: string | null) =>
    ({ type, action, component, rule: 'schedule', lastPerformedAt: null, dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: null, usage: null }) as ForecastDue;

  it('takes the identity of the forecast item the job comes from', () => {
    expect(ruleSeedFor([due('timing_belt', 'replace', 'timing_belt')], 'timing_belt')).toEqual({ type: 'timing_belt', action: 'replace', component: 'timing_belt' });
  });

  it('falls back to the bare type for a job the forecast does not carry', () => {
    expect(ruleSeedFor([due('oil_change', 'change', null)], 'clutch')).toEqual({ type: 'clutch', action: null, component: null });
  });
});
