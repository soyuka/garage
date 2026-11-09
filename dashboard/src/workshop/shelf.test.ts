import { describe, expect, it } from 'vitest';
import type { MaintenanceRecord, PlannedPart } from '../types';
import type { Fitted, JobItem, ToOrder } from './modules';
import { defaultPart, movePart, partDisplay, partForProjection, partShelves, performer, visitShelfId } from './shelf';

const part = (ref: string, label?: string): PlannedPart => ({ ref, label });

function job(id: string, taskId: string | null, parts: PlannedPart[]): JobItem {
  return { id, type: id, label: null, state: 'due', immediate: true, remaining: null, dueReading: null, dueDate: null, days: null, taskId, parts };
}

function record(id: string, performedAt: string): MaintenanceRecord {
  return { id, items: [], performedAt, source: 'user' };
}

const toOrder: ToOrder[] = [
  { job: job('clutch', '/t/clutch', [part('826317', 'Kit embrayage'), part('415', 'Volant bi-masse')]), parts: [part('826317', 'Kit embrayage'), part('415', 'Volant bi-masse')] },
  { job: job('oil_change', null, [part('W75', 'Filtre à huile')]), parts: [part('W75', 'Filtre à huile')] },
];
const fitted: Fitted[] = [{ record: record('/r/pads', '2025-11-12'), parts: [part('P68', 'Plaquettes'), part('K015', 'Kit distribution'), part('WP', 'Pompe à eau')] }];

describe('partShelves', () => {
  it('lays the parts to order shelf by shelf, then the fitted ones visit by visit, each part with a stable key', () => {
    const shelves = partShelves(toOrder, fitted);
    expect(shelves.map((s) => [s.id, s.kind, s.items.map((i) => i.key)])).toEqual([
      ['job:clutch', 'toOrder', ['job:clutch#0', 'job:clutch#1']],
      ['job:oil_change', 'toOrder', ['job:oil_change#0']],
      ['rec:/r/pads', 'fitted', ['rec:/r/pads#0', 'rec:/r/pads#1', 'rec:/r/pads#2']],
    ]);
  });
});

describe('defaultPart', () => {
  it('is the first part to order, else the most recent fitted part, else nothing', () => {
    expect(defaultPart(partShelves(toOrder, fitted))).toBe('job:clutch#0');
    expect(defaultPart(partShelves([], fitted))).toBe('rec:/r/pads#0');
    expect(defaultPart([])).toBeNull();
  });
});

describe('partForProjection', () => {
  const shelves = partShelves(toOrder, fitted);

  it('selects the summoned task’s part, the named one when the frame names it', () => {
    expect(partForProjection(shelves, { kind: 'parts', service: 'clutch', taskId: '/t/clutch', parts: null })).toBe('job:clutch#0');
    expect(partForProjection(shelves, { kind: 'parts', service: 'clutch', taskId: '/t/clutch', parts: [part('415')] })).toBe('job:clutch#1');
  });

  it('selects the first part of an assembly the agent revealed', () => {
    expect(partForProjection(shelves, { kind: 'assembly', region: 'hood', partKeys: ['water_pump', 'timing_belt'] })).toBe('rec:/r/pads#1');
  });

  it('selects nothing for an unrelated projection or an unknown task', () => {
    expect(partForProjection(shelves, { kind: 'history' })).toBeNull();
    expect(partForProjection(shelves, { kind: 'parts', service: 'x', taskId: '/t/none', parts: null })).toBeNull();
  });
});

describe('movePart', () => {
  const shelves = partShelves(toOrder, fitted);

  it('moves along the reading order with left and right, stopping at the ends', () => {
    expect(movePart(shelves, 'job:clutch#1', 'right')).toBe('job:oil_change#0');
    expect(movePart(shelves, 'job:clutch#0', 'left')).toBe('job:clutch#0');
    expect(movePart(shelves, 'rec:/r/pads#2', 'right')).toBe('rec:/r/pads#2');
  });

  it('moves between shelves with up and down, keeping the column when it exists', () => {
    expect(movePart(shelves, 'job:clutch#1', 'down')).toBe('job:oil_change#0');
    expect(movePart(shelves, 'job:oil_change#0', 'down')).toBe('rec:/r/pads#0');
    expect(movePart(shelves, 'rec:/r/pads#2', 'up')).toBe('job:oil_change#0');
    expect(movePart(shelves, 'job:clutch#0', 'up')).toBe('job:clutch#0');
  });
});

describe('performer', () => {
  it('reads a do-it-yourself marker as the owner, never echoing the raw token', () => {
    for (const by of ['self', 'Self', ' moi ', 'diy', 'me', 'soi-même']) expect(performer(by)).toEqual({ self: true });
  });

  it('keeps a garage name and drops blanks', () => {
    expect(performer('Garage du Centre')).toEqual({ self: false, name: 'Garage du Centre' });
    expect(performer('  ')).toBeNull();
    expect(performer(null)).toBeNull();
  });
});

describe('visitShelfId', () => {
  it('names the shelf a cost bar opens', () => {
    expect(visitShelfId('/r/pads')).toBe('rec:/r/pads');
  });
});

describe('partDisplay', () => {
  it('splits the billing note off the name', () => {
    const p = { ref: 'x', brand: 'Hella', label: "Sonde température liquide de refroidissement (facturée « température d'huile »)" };
    expect(partDisplay(p, 'temperature_sensor', 'fr')).toEqual({ name: 'Hella Sonde température liquide de refroidissement', note: "facturée « température d'huile »" });
  });

  it('names a brand-only part after its service', () => {
    expect(partDisplay({ ref: 'J1323055', brand: 'HERTH+BUSS JAKOPARTS' }, 'air_filter', 'fr')).toEqual({ name: 'HERTH+BUSS JAKOPARTS Filtre à air', note: null });
    expect(partDisplay({ ref: 'J1323055', brand: 'HERTH', label: '  ' }, 'air_filter', 'en')).toEqual({ name: 'HERTH Air filter', note: null });
  });

  it('falls back on the ref, then keeps a plain label as is', () => {
    expect(partDisplay({ ref: 'R1' }, 'air_filter', 'fr')).toEqual({ name: 'R1', note: null });
    expect(partDisplay({ ref: 'R1', label: 'Kit embrayage' }, null, 'fr')).toEqual({ name: 'Kit embrayage', note: null });
  });
});

describe('shelf item type', () => {
  it('carries the job type, and each fitted part its visit item type', () => {
    const rec: MaintenanceRecord = {
      id: '/r/v',
      items: [
        { type: 'air_filter', label: null, parts: [part('A')] },
        { type: 'oil_change', label: null, parts: [part('B'), part('C')] },
      ],
      performedAt: '2025-01-01',
      source: 'user',
    };
    const shelves = partShelves(toOrder, [{ record: rec, parts: [part('A'), part('B'), part('C')] }]);
    expect(shelves.map((s) => s.items.map((i) => i.type))).toEqual([['clutch', 'clutch'], ['oil_change'], ['air_filter', 'oil_change', 'oil_change']]);
  });
});
