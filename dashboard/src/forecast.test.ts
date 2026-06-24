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
    usage: null,
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

describe('formatDue — usage axis (binding)', () => {
  it('leads with the remaining usage and a health bar measuring life left against the cadence', () => {
    const v = formatDue(due({ usage: { last: 250, due: 300, remaining: 50, unit: 'hours' } }));
    expect(v.axis).toBe('usage');
    expect(v.lead).toBe('50 h restantes');
    // health = remaining / cadence = 50 / 50 = 1 → full, green.
    expect(v.fill).toBe(100);
    expect(v.color).toBe('rgb(52, 211, 153)');
  });

  it('a big absolute buffer reads green even at a third of the cadence (fuel filter: 9 000 / 40 000 km)', () => {
    const v = formatDue(due({ type: 'fuel_filter', usage: { last: 219000, due: 259000, remaining: 9000, unit: 'km' } }));
    // fraction 0.225, but 9 000 km > the 8 000 km comfort buffer → green, full bar.
    expect(v.lead).toBe('9 000 km restantes');
    expect(v.fill).toBe(100);
    expect(v.color).toBe('rgb(52, 211, 153)');
  });

  it('reddens only once both the fraction and the absolute buffer are small', () => {
    const v = formatDue(due({ usage: { last: 0, due: 20000, remaining: 2000, unit: 'km' } }));
    // fraction 0.1 and 2 000 / 8 000 = 0.25 → max 0.25 → amber-red, not green.
    expect(v.fill).toBe(25);
    expect(v.color).not.toBe('rgb(52, 211, 153)');
  });

  it('keeps km/mi units verbatim and groups the reading', () => {
    const v = formatDue(due({ usage: { last: 138000, due: 153000, remaining: 15000, unit: 'km' } }));
    expect(v.lead).toBe('15 000 km restantes');
  });

  it('overdue usage shows a red near-empty bar and a "de retard" lead', () => {
    const v = formatDue(due({ overdue: true, usage: { last: 300, due: 320, remaining: -20, unit: 'hours' } }));
    expect(v.status).toBe('overdue');
    expect(v.lead).toBe('20 h de retard');
    expect(v.color).toBe('rgb(244, 63, 94)');
    expect(v.fill).toBeLessThanOrEqual(4);
    expect(v.tags).toContain('en retard');
  });
});

describe('formatDue — time axis (binding)', () => {
  it('shows a firm due date and a full green bar when comfortably ahead', () => {
    // Spark plug: done 2025-06-08, due 2030-06-08, 1445 days out — well past the 365-day comfort.
    const v = formatDue(due({
      usage: null,
      lastPerformedAt: '2025-06-08',
      dueAt: '2030-06-08',
      daysUntilDue: 1445,
    }));
    expect(v.axis).toBe('time');
    expect(v.lead).toBe('prévu le 8 juin 2030');
    expect(v.status).toBe('soon');
    expect(v.fill).toBe(100);
    expect(v.color).toBe('rgb(52, 211, 153)');
  });

  it('a year-plus of calendar buffer reads green even at a third of a long cadence (coolant ~2028)', () => {
    // Coolant: 60-mo cadence (~1826 d), 676 d left → fraction 0.37, but 676 > 365 comfort → green.
    const v = formatDue(due({
      type: 'coolant',
      usage: null,
      lastPerformedAt: '2023-04-30',
      dueAt: '2028-04-30',
      daysUntilDue: 676,
    }));
    expect(v.lead).toBe('prévu le 30 avr. 2028');
    expect(v.fill).toBe(100);
    expect(v.color).toBe('rgb(52, 211, 153)');
  });

  it('overdue on time shows "en retard de N j" with a red bar', () => {
    const v = formatDue(due({
      overdue: true,
      lastPerformedAt: '2025-03-08',
      dueAt: '2026-03-08',
      daysUntilDue: -107,
      usage: null,
    }));
    expect(v.axis).toBe('time');
    expect(v.lead).toBe('en retard de 107 j');
    expect(v.status).toBe('overdue');
    expect(v.color).toBe('rgb(244, 63, 94)');
  });

  it('shows a softer estimate when only projectedDueAt exists (no measurable axis)', () => {
    const v = formatDue(due({ usage: null, dueAt: null, projectedDueAt: '2026-12-15' }));
    expect(v.estimate).toBe(true);
    expect(v.lead).toBe('~ déc. 2026');
    expect(v.fill).toBeNull();
  });

  it('falls back to a dash with no bar when nothing is known', () => {
    const v = formatDue(due({ usage: null, dueAt: null, projectedDueAt: null }));
    expect(v.lead).toBe('—');
    expect(v.fill).toBeNull();
  });
});

describe('formatDue — binding axis selection', () => {
  it('binds the worst-health axis (Mazda vidange: time over while km still has range)', () => {
    const v = formatDue(due({
      type: 'oil_change',
      overdue: true,
      lastPerformedAt: '2025-03-08',
      dueAt: '2026-03-08',
      daysUntilDue: -107,
      usage: { last: 236548, due: 256548, remaining: 6548, unit: 'km' },
    }));
    // time health ≈ -0.29 < km health ≈ 0.33 → time binds; km is NOT shown.
    expect(v.axis).toBe('time');
    expect(v.lead).toBe('en retard de 107 j');
    expect(v.lead).not.toContain('km');
  });

  it('binds km when km has the worse health though neither axis is overdue', () => {
    const v = formatDue(due({
      lastPerformedAt: '2026-01-01',
      dueAt: '2027-01-01', // ~1 year cadence
      daysUntilDue: 219, // ~0.6 of the year left
      usage: { last: 100000, due: 110000, remaining: 3000, unit: 'km' }, // 0.3 left
    }));
    expect(v.axis).toBe('usage');
    expect(v.lead).toBe('3 000 km restantes');
  });

  it('prefers the time axis on a health tie', () => {
    const v = formatDue(due({
      lastPerformedAt: '2026-01-01',
      dueAt: '2026-03-02', // 60-day cadence
      daysUntilDue: 30, // health 0.5
      usage: { last: 0, due: 1000, remaining: 500, unit: 'km' }, // health 0.5
    }));
    expect(v.axis).toBe('time');
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
