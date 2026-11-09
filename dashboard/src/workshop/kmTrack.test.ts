import { describe, expect, it } from 'vitest';
import type { ForecastDue, MaintenanceRecord, PlannedTask, VehicleBundle } from '../types';
import { kmTrack, usageSlope } from './kmTrack';

const NOW = new Date('2026-01-01T12:00:00Z');

function record(id: string, performedAt: string, usageValue: number | null, types: string[], label?: string, usageUnit = 'km'): MaintenanceRecord {
  return { id, performedAt, usageValue, usageUnit, items: types.map((type) => ({ type, parts: [] })), label: label ?? null, source: 'user' };
}

function due(type: string, over: Partial<ForecastDue>): ForecastDue {
  return { type, action: 'change', component: null, rule: 'schedule', lastPerformedAt: null, dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: null, usage: null, ...over };
}

function task(id: string, type: string, over: Partial<PlannedTask> = {}): PlannedTask {
  return { id, type, label: null, target: null, dueDate: null, parts: [], status: 'open', ...over };
}

function bundle(over: { usageValue?: number | null; usageUnit?: string; records?: MaintenanceRecord[]; due?: ForecastDue[]; tasks?: PlannedTask[] }): VehicleBundle {
  return {
    vehicle: {
      '@id': '/garage/vehicles/01V',
      make: 'Renault',
      model: 'Espace IV',
      vehicleType: 'car',
      source: 'user',
      factCount: 0,
      addedAt: '2024-01-01',
      usageValue: over.usageValue === undefined ? 136500 : over.usageValue,
      usageUnit: over.usageUnit ?? 'km',
      plannedTasks: over.tasks ?? [],
    },
    forecast: { vehicle: '/garage/vehicles/01V', due: over.due ?? [], gaps: [] },
    records: over.records ?? [],
    documents: [],
  };
}

// 2025-01-01 → 2026-01-01 is 365 days; 100 000 → 136 500 km is 100 km/day.
const HISTORY = [record('/r/2', '2025-07-02', 118100, ['brake_pads']), record('/r/1', '2025-01-01', 100000, ['oil_change'], 'Vidange + filtre')];

describe('usageSlope', () => {
  it('reads km/day from the oldest reading to today', () => {
    expect(usageSlope(bundle({ records: HISTORY }), NOW)).toBeCloseTo(100, 5);
  });

  it('is null with a single reading', () => {
    expect(usageSlope(bundle({ records: [], usageValue: 136500 }), NOW)).toBeNull();
  });

  it('is null when usage never grew', () => {
    expect(usageSlope(bundle({ records: [record('/r', '2025-01-01', 136500, ['oil_change'])] }), NOW)).toBeNull();
  });
});

describe('kmTrack', () => {
  it('places done visits by their usage, oldest first, with today at the vehicle reading', () => {
    const track = kmTrack(bundle({ records: HISTORY }), NOW);
    expect(track.unit).toBe('km');
    expect(track.today).toBe(136500);
    expect(track.done.map((t) => [t.id, t.value, t.state, t.types, t.label, t.estimated])).toEqual([
      ['/r/1', 100000, 'done', ['oil_change'], 'Vidange + filtre', false],
      ['/r/2', 118100, 'done', ['brake_pads'], null, false],
    ]);
  });

  it('places usage-axis forecast items at their due reading, state from the due signal', () => {
    const track = kmTrack(
      bundle({
        records: HISTORY,
        due: [
          due('oil_change', { overdue: true, usage: { last: 120000, due: 135000, remaining: -1500, unit: 'km' } }),
          due('air_filter', { usage: { last: 120000, due: 137000, remaining: 500, unit: 'km' } }),
          due('timing_belt', { usage: { last: 60000, due: 180000, remaining: 43500, unit: 'km' } }),
        ],
      }),
      NOW,
    );
    expect(track.planned.map((t) => [t.types[0], t.value, t.state, t.estimated])).toEqual([
      ['oil_change', 135000, 'over', false],
      ['air_filter', 137000, 'due', false],
      ['timing_belt', 180000, 'planned', false],
    ]);
  });

  it('projects a date-only item onto the axis from the usage slope and flags it estimated', () => {
    const track = kmTrack(bundle({ records: HISTORY, due: [due('brake_fluid', { dueAt: '2026-01-31', daysUntilDue: 30 })] }), NOW);
    expect(track.planned).toHaveLength(1);
    expect(track.planned[0]).toMatchObject({ types: ['brake_fluid'], value: 139500, state: 'due', estimated: true, date: '2026-01-31' });
  });

  it('leaves a date-only item off the axis when no slope can be read', () => {
    const track = kmTrack(bundle({ records: [], due: [due('brake_fluid', { dueAt: '2026-03-01', daysUntilDue: 59 })] }), NOW);
    expect(track.planned).toEqual([]);
    expect(track.unplaced.map((t) => t.types[0])).toEqual(['brake_fluid']);
  });

  it('estimates a visit logged without a reading', () => {
    const track = kmTrack(bundle({ records: [...HISTORY, record('/r/3', '2025-12-02', null, ['tyres'])] }), NOW);
    const tyres = track.done.find((t) => t.id === '/r/3');
    expect(tyres).toMatchObject({ value: 133500, estimated: true });
  });

  it('adds open tasks whose type the forecast does not already carry', () => {
    const track = kmTrack(
      bundle({
        records: HISTORY,
        due: [due('oil_change', { usage: { last: 130000, due: 145000, remaining: 8500, unit: 'km' } })],
        tasks: [
          task('/t/oil', 'oil_change', { target: { value: 145000, unit: 'km' } }),
          task('/t/clutch', 'clutch', { label: 'Kit + volant bi-masse' }),
          task('/t/belt', 'timing_belt', { target: { value: 150000, unit: 'km' } }),
          task('/t/done', 'battery', { status: 'done', target: { value: 140000, unit: 'km' } }),
        ],
      }),
      NOW,
    );
    expect(track.planned.map((t) => [t.id, t.value, t.state])).toEqual([
      ['/t/clutch', 136500, 'due'],
      ['oil_change', 145000, 'planned'],
      ['/t/belt', 150000, 'planned'],
    ]);
    expect(track.planned[0].label).toBe('Kit + volant bi-masse');
  });

  it('marks a task past its target as overdue', () => {
    const track = kmTrack(bundle({ records: HISTORY, tasks: [task('/t', 'brake_discs', { target: { value: 130000, unit: 'km' } })] }), NOW);
    expect(track.planned[0]).toMatchObject({ value: 130000, state: 'over' });
  });

  it('reads an hour-metered vehicle on its own unit', () => {
    const track = kmTrack(
      bundle({
        usageValue: 312,
        usageUnit: 'hours',
        records: [record('/r/m', '2025-04-01', 250, ['oil_change'], undefined, 'h')],
        due: [due('blades', { usage: { last: 250, due: 325, remaining: 13, unit: 'hours' } })],
      }),
      NOW,
    );
    expect(track.unit).toBe('h');
    expect(track.done.map((t) => t.value)).toEqual([250]);
    expect(track.planned.map((t) => t.value)).toEqual([325]);
  });

  it('ignores readings on another unit', () => {
    const track = kmTrack(bundle({ records: [record('/r/x', '2025-04-01', 900, ['oil_change'], undefined, 'h')] }), NOW);
    expect(track.done).toEqual([]);
  });

  it('frames every tick and today inside rounded bounds', () => {
    const track = kmTrack(
      bundle({ records: HISTORY, due: [due('timing_belt', { usage: { last: 60000, due: 180000, remaining: 43500, unit: 'km' } })] }),
      NOW,
    );
    expect(track.min).toBeLessThanOrEqual(100000);
    expect(track.max).toBeGreaterThanOrEqual(180000);
    expect(track.min % 10000).toBe(0);
    expect(track.max % 10000).toBe(0);
    expect(track.min).toBeGreaterThanOrEqual(0);
  });

  it('is empty when nothing carries a reading', () => {
    const track = kmTrack(bundle({ usageValue: null, records: [] }), NOW);
    expect(track.empty).toBe(true);
    expect(track.today).toBeNull();
  });

  it('pads a thin history to a readable span around today', () => {
    const track = kmTrack(bundle({ usageValue: 247388, records: [], tasks: [task('/t/clutch', 'clutch')] }), NOW);
    expect(track.max - track.min).toBeGreaterThanOrEqual(10000);
    expect(track.today).toBeGreaterThan(track.min);
    expect(track.today).toBeLessThan(track.max);
  });

  it('pads an hour meter on its own scale', () => {
    const track = kmTrack(bundle({ usageValue: 3, usageUnit: 'hours', records: [] , due: [due('blades', { usage: { last: 0, due: 25, remaining: 22, unit: 'hours' } })] }), NOW);
    expect(track.max - track.min).toBeGreaterThanOrEqual(40);
    expect(track.max).toBeGreaterThanOrEqual(25);
  });
});
