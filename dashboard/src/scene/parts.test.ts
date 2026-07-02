import { describe, it, expect } from 'vitest';
import { manifestFromCta, manifestTotal, partsCtaFor, pickPartsTask } from './parts';
import type { Cta } from './CtaCard';
import type { PlannedTask, VehicleBundle } from '../types';

// The parts manifest (option 2a, contract-free "option a"): it renders the parts a `proposeActions`
// CTA already carries in its deterministic `patchTask` payload — real refs/prices from the agent's parts
// tool, NOT a faked catalog. So the manifest is the rich, itemised twin of the parts-bearing CTA; a plain
// (partsless) proposal still renders as the ordinary CtaCard. `manifestTotal` sums unit price × qty.

const parts = [
  { ref: 'W 712/75', brand: 'MANN', label: 'Filtre à huile', priceCents: 890, qty: 1 },
  { ref: '0W-20', brand: 'TotalEnergies', label: 'Huile', priceCents: 2990, qty: 1 },
];

describe('manifestFromCta — derive a parts manifest from a parts-bearing proposal', () => {
  it('returns the parts + task target + headline when an action carries patchTask.parts', () => {
    const cta: Cta = {
      headline: 'Vidange à prévoir',
      finding: 'Filtre + huile disponibles',
      actions: [{ label: 'Planifier', patchTask: { id: '/planned-tasks/oil', parts } }],
    };
    const m = manifestFromCta(cta);
    expect(m).not.toBeNull();
    expect(m!.taskId).toBe('/planned-tasks/oil');
    expect(m!.parts).toHaveLength(2);
    expect(m!.service).toBe('Vidange à prévoir');
  });

  it('is null for a proposal whose actions carry only instructions (no parts)', () => {
    const cta: Cta = { headline: 'Voulez-vous continuer ?', actions: [{ label: 'Oui', instruction: 'continue' }] };
    expect(manifestFromCta(cta)).toBeNull();
  });

  it('is null when a patchTask carries an empty parts list', () => {
    const cta: Cta = { headline: 'x', actions: [{ label: 'y', patchTask: { id: '/t', parts: [] } }] };
    expect(manifestFromCta(cta)).toBeNull();
  });

  it('is null for no cta', () => {
    expect(manifestFromCta(null)).toBeNull();
  });

  it('picks the FIRST parts-bearing action when several exist', () => {
    const cta: Cta = {
      headline: 'x',
      actions: [
        { label: 'a', instruction: 'no' },
        { label: 'b', patchTask: { id: '/first', parts } },
        { label: 'c', patchTask: { id: '/second', parts } },
      ],
    };
    expect(manifestFromCta(cta)!.taskId).toBe('/first');
  });
});

describe('manifestTotal — unit price × qty, in cents', () => {
  it('sums the lines (qty defaults to 1)', () => {
    expect(manifestTotal(parts)).toBe(890 + 2990);
  });

  it('multiplies by qty', () => {
    expect(manifestTotal([{ ref: 'x', priceCents: 500, qty: 3 }])).toBe(1500);
  });

  it('treats a missing price as zero (never NaN)', () => {
    expect(manifestTotal([{ ref: 'x' }, { ref: 'y', priceCents: 100 }])).toBe(100);
  });

  it('is zero for an empty list', () => {
    expect(manifestTotal([])).toBe(0);
  });
});

// pickPartsTask backs the deterministic "Pièces dispo" wayfinder (option a — the manifest itemises a
// task's OWN planned parts, no catalog GET). It picks the most-urgent OPEN task that carries parts, where
// "urgent" = its service type sits earliest in the severity-sorted forecast.due. Null → the chip hides.

const task = (over: Partial<PlannedTask>): PlannedTask => ({ id: '/planned-tasks/x', type: 'oil_change', parts: [], status: 'open', ...over });

const bundle = (tasks: PlannedTask[], dueTypes: string[]): VehicleBundle =>
  ({
    vehicle: { plannedTasks: tasks } as VehicleBundle['vehicle'],
    forecast: { vehicle: '/v/1', gaps: [], due: dueTypes.map((type) => ({ type })) } as unknown as VehicleBundle['forecast'],
    records: [],
  }) as VehicleBundle;

describe('pickPartsTask — most-urgent open task carrying parts', () => {
  it('picks the open task whose type is earliest in the forecast order', () => {
    const tasks = [
      task({ id: '/t/brakes', type: 'brake_pads', parts: [{ ref: 'B1' }] }),
      task({ id: '/t/oil', type: 'oil_change', parts: [{ ref: 'O1' }] }),
    ];
    expect(pickPartsTask(bundle(tasks, ['oil_change', 'brake_pads']))!.id).toBe('/t/oil');
  });

  it('ignores tasks with no parts and non-open tasks', () => {
    const tasks = [
      task({ id: '/t/empty', type: 'oil_change', parts: [] }),
      task({ id: '/t/done', type: 'brake_pads', parts: [{ ref: 'B' }], status: 'done' }),
      task({ id: '/t/keep', type: 'coolant', parts: [{ ref: 'C' }] }),
    ];
    expect(pickPartsTask(bundle(tasks, ['oil_change']))!.id).toBe('/t/keep');
  });

  it('sorts an unforecast type last but still returns it when it is the only candidate', () => {
    const tasks = [task({ id: '/t/x', type: 'wiper', parts: [{ ref: 'W' }] })];
    expect(pickPartsTask(bundle(tasks, ['oil_change']))!.id).toBe('/t/x');
  });

  it('is null when no open task carries parts', () => {
    expect(pickPartsTask(bundle([task({ parts: [] })], []))).toBeNull();
    expect(pickPartsTask(bundle([], []))).toBeNull();
  });
});

describe('partsCtaFor — a deterministic parts-manifest CTA (patchTask, no agent turn)', () => {
  it('wraps the parts as a single patchTask action on the task', () => {
    const parts = [{ ref: 'W 712/75', priceCents: 890, qty: 1 }];
    const cta = partsCtaFor('Vidange', '/planned-tasks/oil', parts);
    expect(cta.headline).toBe('Vidange');
    expect(cta.actions).toHaveLength(1);
    expect(cta.actions[0].patchTask).toEqual({ id: '/planned-tasks/oil', parts });
    expect(cta.actions[0].instruction).toBeUndefined();
    // it round-trips through manifestFromCta into a renderable manifest
    expect(manifestFromCta(cta)!.parts).toEqual(parts);
  });
});
