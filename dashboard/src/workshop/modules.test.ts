import { describe, expect, it } from 'vitest';
import type { ForecastDue, MaintenanceRecord, NextDue, PlannedTask, VehicleBundle, VehicleDocument, VehicleSummary } from '../types';
import { deriveModules, entretienLists, entretienNext, nextJobOf, parcDues, piecesLists, rankParc, urgentJob, vehicleNextJob } from './modules';

const NOW = new Date('2026-01-01T12:00:00Z');

function due(type: string, over: Partial<ForecastDue>): ForecastDue {
  return { type, action: 'change', component: null, rule: 'schedule', lastPerformedAt: null, dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: null, usage: null, ...over };
}

function task(id: string, type: string, over: Partial<PlannedTask> = {}): PlannedTask {
  return { id, type, label: null, target: null, dueDate: null, parts: [], status: 'open', ...over };
}

function record(id: string, performedAt: string, usageValue: number | null, types: string[], extra: Partial<MaintenanceRecord> = {}): MaintenanceRecord {
  return { id, performedAt, usageValue, usageUnit: 'km', items: types.map((type) => ({ type, parts: [] })), source: 'user', ...extra };
}

function document(id: string, addedAt: string, title: string): VehicleDocument {
  return {
    id,
    kind: 'manual',
    title,
    source: 'upload',
    sourceRef: null,
    plannedTaskId: null,
    filename: `${id}.pdf`,
    contentType: 'application/pdf',
    size: 1000,
    pageCount: 3,
    addedAt,
    url: `/garage/vehicles/01V/documents/${id}/file`,
    pagesUrl: `/garage/vehicles/01V/documents/${id}/pages`,
  };
}

function bundle(over: { due?: ForecastDue[]; tasks?: PlannedTask[]; records?: MaintenanceRecord[]; documents?: VehicleDocument[] | null }): VehicleBundle {
  return {
    vehicle: {
      '@id': '/garage/vehicles/01V',
      make: 'Renault',
      model: 'Espace IV',
      vehicleType: 'car',
      source: 'user',
      factCount: 0,
      addedAt: '2024-01-01',
      usageValue: 136500,
      usageUnit: 'km',
      plannedTasks: over.tasks ?? [],
    },
    forecast: { vehicle: '/garage/vehicles/01V', due: over.due ?? [], gaps: [] },
    records: over.records ?? [],
    documents: over.documents === undefined ? [] : over.documents,
  };
}

describe('deriveModules — À faire / Bientôt', () => {
  it('puts overdue work and undated open tasks in À faire, the rest in Bientôt soonest first', () => {
    const m = deriveModules(
      bundle({
        records: [record('/r/1', '2025-01-01', 100000, ['oil_change'])],
        due: [
          due('timing_belt', { usage: { last: 60000, due: 180000, remaining: 43500, unit: 'km' }, daysUntilDue: 435 }),
          due('oil_change', { overdue: true, usage: { last: 120000, due: 135000, remaining: -1500, unit: 'km' }, daysUntilDue: -15 }),
          due('brake_fluid', { dueAt: '2026-01-21', daysUntilDue: 20 }),
        ],
        tasks: [task('/t/clutch', 'clutch', { label: 'Kit + volant bi-masse', parts: [{ ref: 'VALEO 826317' }] })],
      }),
      NOW,
    );
    expect(m.aFaire.map((j) => [j.id, j.state, j.immediate])).toEqual([
      ['oil_change', 'over', false],
      ['/t/clutch', 'due', true],
    ]);
    expect(m.aFaire[1]).toMatchObject({ label: 'Kit + volant bi-masse', taskId: '/t/clutch', parts: [{ ref: 'VALEO 826317' }] });
    expect(m.bientot.map((j) => [j.id, j.state])).toEqual([
      ['brake_fluid', 'due'],
      ['timing_belt', 'planned'],
    ]);
  });

  it('carries the remaining usage, negative when overdue', () => {
    const m = deriveModules(bundle({ due: [due('oil_change', { overdue: true, usage: { last: 120000, due: 135000, remaining: -1500, unit: 'km' } })] }), NOW);
    expect(m.aFaire[0].remaining).toEqual({ value: -1500, unit: 'km' });
    expect(m.aFaire[0].dueReading).toBe(135000);
  });

  it('merges an open task onto the forecast item of the same service', () => {
    const m = deriveModules(
      bundle({
        due: [due('oil_change', { usage: { last: 130000, due: 145000, remaining: 8500, unit: 'km' }, daysUntilDue: 85 })],
        tasks: [task('/t/oil', 'oil_change', { target: { value: 145000, unit: 'km' }, parts: [{ ref: 'MANN W 712/75' }] })],
      }),
      NOW,
    );
    expect(m.bientot).toHaveLength(1);
    expect(m.bientot[0]).toMatchObject({ id: 'oil_change', taskId: '/t/oil', parts: [{ ref: 'MANN W 712/75' }] });
  });

  it('places a task with a target by its remaining usage', () => {
    const m = deriveModules(bundle({ tasks: [task('/t/pads', 'brake_pads', { target: { value: 140000, unit: 'km' } })] }), NOW);
    expect(m.bientot[0]).toMatchObject({ id: '/t/pads', remaining: { value: 3500, unit: 'km' }, state: 'planned', dueReading: 140000 });
  });

  it('puts a task past its target in À faire', () => {
    const m = deriveModules(bundle({ tasks: [task('/t/pads', 'brake_pads', { target: { value: 130000, unit: 'km' } })] }), NOW);
    expect(m.aFaire[0]).toMatchObject({ id: '/t/pads', state: 'over', remaining: { value: -6500, unit: 'km' } });
  });

  it('ignores closed tasks', () => {
    const m = deriveModules(bundle({ tasks: [task('/t/x', 'battery', { status: 'done' })] }), NOW);
    expect(m.aFaire).toEqual([]);
    expect(m.bientot).toEqual([]);
  });

  it('keeps the inspection out of Bientôt — it has its own module', () => {
    const m = deriveModules(bundle({ due: [due('inspection', { dueAt: '2026-01-20', daysUntilDue: 19 })] }), NOW);
    expect(m.bientot).toEqual([]);
    expect(m.controleTechnique).toEqual({ dueAt: '2026-01-20', days: 19, state: 'due', lastPerformedAt: null });
  });

  it('an overdue inspection also lands in À faire', () => {
    const m = deriveModules(bundle({ due: [due('technical_inspection', { overdue: true, dueAt: '2025-12-01', daysUntilDue: -31 })] }), NOW);
    expect(m.aFaire.map((j) => j.type)).toEqual(['technical_inspection']);
    expect(m.controleTechnique?.state).toBe('over');
  });
});

describe('deriveModules — Contrôle technique', () => {
  it('falls back to the last logged inspection when the forecast has none', () => {
    const m = deriveModules(bundle({ records: [record('/r/ct', '2025-03-14', 120000, ['inspection'])] }), NOW);
    expect(m.controleTechnique).toEqual({ dueAt: null, days: null, state: 'unknown', lastPerformedAt: '2025-03-14' });
  });

  it('is null with nothing known', () => {
    expect(deriveModules(bundle({}), NOW).controleTechnique).toBeNull();
  });
});

describe('deriveModules — Dernière visite', () => {
  it('is the newest visit', () => {
    const m = deriveModules(
      bundle({ records: [record('/r/old', '2024-06-01', 90000, ['oil_change']), record('/r/new', '2025-06-12', 120000, ['brake_pads'])] }),
      NOW,
    );
    expect(m.derniereVisite?.id).toBe('/r/new');
  });

  it('is null with no visits', () => {
    expect(deriveModules(bundle({}), NOW).derniereVisite).toBeNull();
  });
});

describe('deriveModules — Documents récents', () => {
  it('merges filed documents with visit bills, newest first, capped at five', () => {
    const bill = { id: 'b1', filename: 'facture-centre.pdf', contentType: 'application/pdf', size: 10, url: '/garage/maintenance-records/01R/attachments/b1' };
    const m = deriveModules(
      bundle({
        records: [record('/r/1', '2025-06-12', 120000, ['oil_change'], { attachments: [bill] })],
        documents: [
          document('d1', '2025-01-01T10:00:00+00:00', 'Manuel'),
          document('d2', '2025-09-01T10:00:00+00:00', 'Note technique'),
          document('d3', '2024-01-01T10:00:00+00:00', 'Devis'),
          document('d4', '2023-01-01T10:00:00+00:00', 'Vieux'),
          document('d5', '2022-01-01T10:00:00+00:00', 'Très vieux'),
        ],
      }),
      NOW,
    );
    expect(m.documentsRecents.map((d) => [d.id, d.kind, d.date])).toEqual([
      ['d2', 'manual', '2025-09-01'],
      ['b1', 'bill', '2025-06-12'],
      ['d1', 'manual', '2025-01-01'],
      ['d3', 'manual', '2024-01-01'],
      ['d4', 'manual', '2023-01-01'],
    ]);
    expect(m.documentsRecents[1]).toMatchObject({ title: 'facture-centre.pdf', url: bill.url, filename: 'facture-centre.pdf', size: 10 });
    expect(m.documentsRecents[0]).toMatchObject({ size: 1000, pageCount: 3 });
    expect(m.documentsUnavailable).toBe(false);
  });

  it('says the documents read failed rather than showing none', () => {
    const m = deriveModules(bundle({ documents: null }), NOW);
    expect(m.documentsUnavailable).toBe(true);
    expect(m.documentsRecents).toEqual([]);
  });
});

describe('nextJobOf', () => {
  const base: VehicleSummary = { '@id': '/v', make: 'Peugeot', model: '206', vehicleType: 'car', source: 'user', factCount: 0, addedAt: '2024-01-01', usageUnit: 'km' };
  const next = (over: Partial<NextDue>): VehicleSummary => ({
    ...base,
    nextDue: { type: 'brake_pads', action: 'replace', label: 'Plaquettes', rule: 'schedule', overdue: false, dueAt: null, projectedDueAt: null, daysUntilDue: null, usageRemaining: null, usageUnit: null, ...over },
  });

  it('is null when nothing is predictable', () => {
    expect(nextJobOf(base)).toBeNull();
  });

  it('reads overdue, soon and later from the listing summary', () => {
    expect(nextJobOf(next({ overdue: true, usageRemaining: -300, usageUnit: 'km' }))).toEqual({ type: 'brake_pads', label: 'Plaquettes', state: 'over', immediate: false, remaining: { value: -300, unit: 'km' }, days: null, dueDate: null });
    expect(nextJobOf(next({ daysUntilDue: 12, dueAt: '2026-01-13' }))?.state).toBe('due');
    expect(nextJobOf(next({ daysUntilDue: 120, usageRemaining: 800, usageUnit: 'km' }))?.state).toBe('planned');
  });

  it('reads an hour-metered summary on hours', () => {
    expect(nextJobOf(next({ usageRemaining: 13, usageUnit: 'hours' }))?.remaining).toEqual({ value: 13, unit: 'h' });
  });
});

describe('rankParc', () => {
  const v = (id: string, nextDue: Partial<NextDue> | null): VehicleSummary => ({
    '@id': id,
    make: 'X',
    model: id,
    vehicleType: 'car',
    source: 'user',
    factCount: 0,
    addedAt: '2024-01-01',
    nextDue: nextDue
      ? { type: 't', action: null, label: 'L', rule: 'schedule', overdue: false, dueAt: null, projectedDueAt: null, daysUntilDue: null, usageRemaining: null, usageUnit: null, ...nextDue }
      : null,
  });

  it('puts overdue first, then soonest, untracked last, stable otherwise', () => {
    const ranked = rankParc([v('/later', { daysUntilDue: 200 }), v('/none', null), v('/over', { overdue: true, daysUntilDue: -3 }), v('/soon', { daysUntilDue: 9 }), v('/later2', { daysUntilDue: 200 })]);
    expect(ranked.map((x) => x['@id'])).toEqual(['/over', '/soon', '/later', '/later2', '/none']);
  });
});

describe('urgentJob', () => {
  it('is the top of À faire, else the top of Bientôt, else null', () => {
    const over = deriveModules(bundle({ due: [due('oil_change', { overdue: true, daysUntilDue: -3 }), due('air_filter', { daysUntilDue: 10 })] }), NOW);
    expect(urgentJob(over)?.id).toBe('oil_change');
    const soon = deriveModules(bundle({ due: [due('air_filter', { daysUntilDue: 10 })] }), NOW);
    expect(urgentJob(soon)?.id).toBe('air_filter');
    expect(urgentJob(deriveModules(bundle({}), NOW))).toBeNull();
  });
});

describe('parcDues', () => {
  const withDues = (iri: string, dues: ForecastDue[], tasks: PlannedTask[] = []): VehicleBundle => {
    const b = bundle({ due: dues, tasks });
    return { ...b, vehicle: { ...b.vehicle, '@id': iri }, forecast: { ...b.forecast, vehicle: iri } };
  };

  it('lists every due and overdue item across the parc, most urgent first, with its vehicle — planned work stays off', () => {
    const rows = parcDues(
      [
        withDues('/v/a', [due('air_filter', { daysUntilDue: 40 }), due('brake_fluid', { daysUntilDue: 5 })]),
        withDues('/v/b', [due('oil_change', { overdue: true, daysUntilDue: -10 })], [task('/t/clutch', 'clutch')]),
      ],
      NOW,
    );
    expect(rows.map((r) => [r.vehicleIri, r.job.id])).toEqual([
      ['/v/b', 'oil_change'],
      ['/v/b', '/t/clutch'],
      ['/v/a', 'brake_fluid'],
    ]);
  });

  it('includes the inspection when it is overdue or due, not when it is far', () => {
    const rows = parcDues([withDues('/v/a', [due('inspection', { daysUntilDue: 12, dueAt: '2026-01-13' }), due('technical_inspection', { daysUntilDue: 400 })])], NOW);
    expect(rows.map((r) => r.job.type)).toEqual(['inspection']);
  });
});

describe('vehicleNextJob — the home row and the car page agree', () => {
  it('reads the open checklist when the bundle is loaded: an undated task is the next job, now', () => {
    const b = bundle({ tasks: [task('/t/clutch', 'clutch', { label: 'embrayage' })] });
    const listed: VehicleSummary = { ...b.vehicle, nextDue: null };
    expect(vehicleNextJob(listed, b, NOW)).toMatchObject({ type: 'clutch', label: 'embrayage', state: 'due', immediate: true });
  });

  it('puts an overdue forecast ahead of an undated task, like the car page', () => {
    const b = bundle({
      due: [due('oil_change', { overdue: true, usage: { last: 120000, due: 135000, remaining: -1500, unit: 'km' } })],
      tasks: [task('/t/clutch', 'clutch', { label: 'embrayage' })],
    });
    expect(vehicleNextJob(b.vehicle, b, NOW)).toMatchObject({ type: 'oil_change', state: 'over', remaining: { value: -1500, unit: 'km' } });
  });

  it('falls back to the listing summary until the bundle arrives', () => {
    const b = bundle({});
    const listed: VehicleSummary = {
      ...b.vehicle,
      nextDue: { type: 'brake_pads', action: 'replace', label: 'Plaquettes', rule: 'schedule', overdue: false, dueAt: null, projectedDueAt: null, daysUntilDue: 12, usageRemaining: null, usageUnit: null },
    };
    expect(vehicleNextJob(listed, undefined, NOW)).toMatchObject({ label: 'Plaquettes', state: 'due' });
  });

  it('is null when neither knows anything', () => {
    const b = bundle({});
    expect(vehicleNextJob({ ...b.vehicle, nextDue: null }, b, NOW)).toBeNull();
  });
});

describe('rankParc with bundles', () => {
  it('ranks by the same next job the rows show', () => {
    const espace = bundle({ tasks: [task('/t/clutch', 'clutch', { label: 'embrayage' })] });
    const quiet = { ...bundle({}), vehicle: { ...bundle({}).vehicle, '@id': '/v/quiet', nextDue: null } };
    const ranked = rankParc([quiet.vehicle, { ...espace.vehicle, nextDue: null }], { '/v/quiet': quiet, [espace.vehicle['@id']]: espace }, NOW);
    expect(ranked.map((v) => v['@id'])).toEqual([espace.vehicle['@id'], '/v/quiet']);
  });
});

describe('entretienNext — Bientôt on the one-job screen', () => {
  it('lists the next three after the most urgent, with the inspection folded in by date, and counts the rest', () => {
    const m = deriveModules(
      bundle({
        due: [
          due('oil_change', { overdue: true, daysUntilDue: -15 }),
          due('brake_fluid', { daysUntilDue: 20 }),
          due('inspection', { dueAt: '2026-02-10', daysUntilDue: 40 }),
          due('air_filter', { daysUntilDue: 60 }),
          due('timing_belt', { daysUntilDue: 400 }),
        ],
      }),
      NOW,
    );
    const next = entretienNext(m, 3);
    expect(next.jobs.map((j) => j.type)).toEqual(['brake_fluid', 'inspection', 'air_filter']);
    expect(next.more).toBe(1);
  });

  it('keeps the urgent job in the list when the slot is showing something else', () => {
    const m = deriveModules(bundle({ due: [due('oil_change', { overdue: true }), due('air_filter', { daysUntilDue: 60 })] }), NOW);
    expect(entretienNext(m, 3, false).jobs.map((j) => j.type)).toEqual(['oil_change', 'air_filter']);
  });

  it('is empty when the urgent job is the only one', () => {
    const m = deriveModules(bundle({ due: [due('oil_change', { overdue: true })] }), NOW);
    expect(entretienNext(m, 3)).toEqual({ jobs: [], more: 0 });
  });
});

describe('piecesLists', () => {
  it('groups parts to order by job (due or overdue jobs only) and fitted parts by visit, newest first', () => {
    const b = bundle({
      due: [due('timing_belt', { daysUntilDue: 400 })],
      tasks: [
        task('/t/clutch', 'clutch', { label: 'embrayage', parts: [{ ref: 'A' }, { ref: 'B' }] }),
        task('/t/belt', 'timing_belt', { target: { value: 180000, unit: 'km' }, parts: [{ ref: 'C' }] }),
      ],
      records: [
        record('/r/old', '2024-01-01', 100000, [], { items: [{ type: 'oil_change', parts: [{ ref: 'F1' }] }] }),
        record('/r/new', '2025-06-01', 120000, [], { items: [{ type: 'brake_pads', parts: [{ ref: 'P1' }, { ref: 'P2' }] }, { type: 'wipers', parts: [] }] }),
        record('/r/none', '2025-07-01', 121000, ['inspection']),
      ],
    });
    const lists = piecesLists(b, deriveModules(b, NOW));
    expect(lists.toOrder.map((g) => [g.job.id, g.parts.map((p) => p.ref)])).toEqual([['/t/clutch', ['A', 'B']]]);
    expect(lists.fitted.map((g) => [g.record.id, g.parts.map((p) => p.ref)])).toEqual([
      ['/r/new', ['P1', 'P2']],
      ['/r/old', ['F1']],
    ]);
  });
});

describe('entretienLists — honest À faire beside a summoned slot', () => {
  const m = () =>
    deriveModules(
      bundle({
        due: [due('oil_change', { overdue: true, daysUntilDue: -15 }), due('air_filter', { daysUntilDue: 60 })],
        tasks: [task('/t/clutch', 'clutch', { label: 'embrayage' })],
      }),
      NOW,
    );

  it('with the urgent job in the slot, lists the other overdue/now jobs under À faire, the rest under Bientôt', () => {
    const l = entretienLists(m(), 3, true);
    expect(l.aFaire.jobs.map((j) => j.id)).toEqual(['/t/clutch']);
    expect(l.bientot.jobs.map((j) => j.id)).toEqual(['air_filter']);
  });

  it('with something else in the slot, every overdue/now job is under À faire, never under Bientôt', () => {
    const l = entretienLists(m(), 3, false);
    expect(l.aFaire.jobs.map((j) => j.id)).toEqual(['oil_change', '/t/clutch']);
    expect(l.bientot.jobs.map((j) => j.id)).toEqual(['air_filter']);
  });
});

describe('parcDues — beside the rows', () => {
  it('leaves out each vehicle’s headline job, which its own row already shows', () => {
    const b = bundle({ due: [due('oil_change', { overdue: true, daysUntilDue: -10 }), due('brake_fluid', { daysUntilDue: 5 })] });
    expect(parcDues([b], NOW, { excludeHeadline: true }).map((r) => r.job.id)).toEqual(['brake_fluid']);
  });
});
