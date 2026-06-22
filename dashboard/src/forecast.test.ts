import { describe, expect, it } from 'vitest';
import { formatDue, formatNextDue } from './forecast';
import type { ForecastDue, NextDue } from './types';

function due(over: Partial<ForecastDue> = {}): ForecastDue {
  return {
    type: 'oil_change',
    action: 'change',
    component: null,
    rule: 'schedule',
    lastPerformedAt: '2024-01-10',
    dueAt: null,
    projectedDueAt: null,
    overdue: false,
    daysUntilDue: null,
    intervalMonths: null,
    usage: null,
    usageInterval: null,
    ...over,
  };
}

function nextDue(over: Partial<NextDue> = {}): NextDue {
  return {
    type: 'oil_change',
    action: 'change',
    label: 'oil_change',
    rule: 'schedule',
    overdue: false,
    dueAt: null,
    projectedDueAt: null,
    daysUntilDue: null,
    usageRemaining: null,
    usageUnit: null,
    ...over,
  };
}

describe('formatDue — title', () => {
  it('joins the service label with its action', () => {
    expect(formatDue(due({ type: 'oil_change', action: 'change' })).title).toBe('Vidange · changer');
  });

  it('appends the component when present', () => {
    const v = formatDue(due({ type: 'air_filter', action: 'replace', component: 'paper cartridge' }));
    expect(v.title).toBe('Filtre à air · remplacer — paper cartridge');
  });

  it('omits the action when null', () => {
    expect(formatDue(due({ type: 'brakes', action: null })).title).toBe('Freins');
  });
});

describe('formatDue — usage axis', () => {
  it('leads with the remaining usage and exposes the progress against the cadence', () => {
    const v = formatDue(due({ usage: { last: 250, due: 300, remaining: 50, unit: 'hours' } }));
    expect(v.axis).toBe('usage');
    expect(v.lead).toBe('50 h restantes');
    expect(v.sub).toBe('dernière : 250 h');
    // cadence = due - last = 50; consumed = cadence - remaining = 0 → 0%.
    expect(v.progress).toBe(0);
  });

  it('translates the hours unit to the French "h" abbreviation', () => {
    const v = formatDue(due({ usage: { last: 100, due: 600, remaining: 200, unit: 'hours' } }));
    // cadence 500, consumed 300 → 60%.
    expect(v.progress).toBe(60);
    expect(v.lead).toBe('200 h restantes');
  });

  it('keeps km/mi units verbatim and groups the reading', () => {
    const v = formatDue(due({ usage: { last: 138000, due: 153000, remaining: 15000, unit: 'km' } }));
    expect(v.lead).toBe('15 000 km restantes');
    expect(v.sub).toBe('dernière : 138 000 km');
  });
});

describe('formatDue — time axis', () => {
  it('shows the firm due date when dueAt is present', () => {
    const v = formatDue(due({ usage: null, dueAt: '2026-12-15' }));
    expect(v.axis).toBe('time');
    expect(v.estimate).toBe(false);
    expect(v.lead).toBe('prévu le 15 déc. 2026');
  });

  it('shows a softer estimate when only projectedDueAt exists', () => {
    const v = formatDue(due({ usage: null, dueAt: null, projectedDueAt: '2026-12-15' }));
    expect(v.axis).toBe('time');
    expect(v.estimate).toBe(true);
    expect(v.lead).toBe('~ déc. 2026');
  });

  it('falls back to a dash when neither date nor usage is known', () => {
    const v = formatDue(due({ usage: null, dueAt: null, projectedDueAt: null }));
    expect(v.lead).toBe('—');
  });
});

describe('formatDue — overdue + rule tags', () => {
  it('flags overdue with a red treatment and an "en retard" tag', () => {
    const v = formatDue(due({ overdue: true, usage: { last: 300, due: 300, remaining: -20, unit: 'hours' } }));
    expect(v.status).toBe('overdue');
    expect(v.lead).toBe('20 h de retard');
    expect(v.tags).toContain('en retard');
  });

  it('tags a default rule as "générique"', () => {
    const v = formatDue(due({ rule: 'default' }));
    expect(v.tags).toContain('générique');
  });

  it('does not tag a schedule rule as générique', () => {
    const v = formatDue(due({ rule: 'schedule' }));
    expect(v.tags).not.toContain('générique');
  });

  it('a non-overdue usage item is soon, not overdue', () => {
    const v = formatDue(due({ overdue: false, usage: { last: 250, due: 300, remaining: 50, unit: 'hours' } }));
    expect(v.status).toBe('soon');
  });
});

describe('formatNextDue — rail line', () => {
  it('returns null when there is no next-due', () => {
    expect(formatNextDue(null)).toBeNull();
    expect(formatNextDue(undefined)).toBeNull();
  });

  it('renders a usage line: label · remaining', () => {
    const line = formatNextDue(nextDue({ type: 'oil_change', usageRemaining: 50, usageUnit: 'hours' }));
    expect(line).toEqual({ text: 'Vidange · 50 h', overdue: false });
  });

  it('renders an overdue line', () => {
    const line = formatNextDue(nextDue({ type: 'inspection', overdue: true }));
    expect(line).toEqual({ text: 'Contrôle technique · en retard', overdue: true });
  });

  it('renders a date line when only a due date is known', () => {
    const line = formatNextDue(nextDue({ type: 'timing_belt', dueAt: '2026-12-15' }));
    expect(line).toEqual({ text: 'Courroie de distribution · déc. 2026', overdue: false });
  });

  it('renders a softer projected date as an estimate', () => {
    const line = formatNextDue(nextDue({ type: 'oil_change', projectedDueAt: '2026-12-15' }));
    expect(line).toEqual({ text: 'Vidange · ~ déc. 2026', overdue: false });
  });
});
