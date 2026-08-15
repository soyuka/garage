import { describe, expect, it } from 'vitest';
import { NBSP, THIN } from './format';
import { groupByMilestone, milestoneOf, taskTitle } from './tasks';
import type { PlannedTask } from './types';

// Pure derivations for the open-checklist surface (#10). Rebuilt fresh from the contract — the title,
// the milestone a task is pegged to (usage target → date → none), and the bucketing that groups the
// checklist by milestone preserving backend order.

const task = (over: Partial<PlannedTask>): PlannedTask => ({ id: '/garage/planned-tasks/x', type: 'oil_change', parts: [], status: 'open', ...over });

describe('taskTitle', () => {
  it('is the French service label for a bare task', () => {
    expect(taskTitle(task({ type: 'oil_change' }))).toBe('Vidange');
  });
  it('appends a custom label after an em-dash', () => {
    expect(taskTitle(task({ type: 'brake_pads', label: 'avant' }))).toBe('Plaquettes de frein — avant');
  });

  // A label that already names the service must not be prefixed with it. On the live Mazda the rail
  // read "Sonde de température — Sonde température liquide refroidissement" and wrapped to four lines
  // to say one thing twice. The label is the operator's own words and always wins when it covers the
  // service; the prefix exists only to name a service the label leaves implicit.
  it('drops the prefix when the label already carries the service words', () => {
    expect(taskTitle(task({ type: 'coolant_temperature_sensor', label: 'Sonde température liquide refroidissement' })))
      .toBe('Sonde température liquide refroidissement');
  });

  it('ignores accents and case when deciding', () => {
    expect(taskTitle(task({ type: 'engine_mount', label: 'support moteur arrière (derrière le moteur)' })))
      .toBe('support moteur arrière (derrière le moteur)');
  });

  it('ignores the French articles inside a service label', () => {
    // "Plaquettes de frein" vs "plaquettes frein avant" — `de` must not be what forces the prefix back.
    expect(taskTitle(task({ type: 'brake_pads', label: 'plaquettes frein avant' }))).toBe('plaquettes frein avant');
  });

  it('keeps the prefix when the label names something else entirely', () => {
    expect(taskTitle(task({ type: 'oil_change', label: "avant l'hiver" }))).toBe("Vidange — avant l'hiver");
  });

  it('keeps the prefix when the label only partly covers the service', () => {
    expect(taskTitle(task({ type: 'brake_discs', label: 'plaquettes avant' }))).toBe('Disques de frein — plaquettes avant');
  });
});

describe('milestoneOf', () => {
  it('a usage target reads "À <grouped reading>" (French thin-space + NBSP unit)', () => {
    const m = milestoneOf(task({ target: { value: 250000, unit: 'km' } }));
    expect(m.label).toBe(`À 250${THIN}000${NBSP}km`); // U+202F thousands, U+00A0 before unit
    expect(m.key).toBe('u:250000:km');
  });
  it('a usage target with no unit omits the unit', () => {
    const m = milestoneOf(task({ target: { value: 500, unit: null } }));
    expect(m.label).toBe('À 500');
  });
  it('a due date reads "Avant le <French date>"', () => {
    const m = milestoneOf(task({ target: null, dueDate: '2026-01-15' }));
    expect(m.label).toBe(`Avant le 15${NBSP}janv.${NBSP}2026`);
    expect(m.key).toBe('d:2026-01-15');
  });
  it('neither → "Sans échéance"', () => {
    expect(milestoneOf(task({ target: null, dueDate: null })).label).toBe('Sans échéance');
  });
  it('a usage target wins over a due date', () => {
    const m = milestoneOf(task({ target: { value: 100000, unit: 'km' }, dueDate: '2026-01-15' }));
    expect(m.key).toBe('u:100000:km');
  });
});

describe('groupByMilestone', () => {
  it('buckets tasks by milestone, preserving first-appearance order', () => {
    const tasks = [
      task({ id: 'a', type: 'oil_change', target: { value: 250000, unit: 'km' } }),
      task({ id: 'b', type: 'oil_filter', target: { value: 250000, unit: 'km' } }),
      task({ id: 'c', type: 'brake_fluid', target: null, dueDate: '2026-03-01' }),
    ];
    const groups = groupByMilestone(tasks);
    expect(groups.map((g) => g.key)).toEqual(['u:250000:km', 'd:2026-03-01']);
    expect(groups[0].tasks.map((t) => t.id)).toEqual(['a', 'b']);
    expect(groups[1].tasks.map((t) => t.id)).toEqual(['c']);
  });
  it('empty in → empty out', () => {
    expect(groupByMilestone([])).toEqual([]);
  });
});
