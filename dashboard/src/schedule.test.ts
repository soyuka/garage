import { describe, expect, it } from 'vitest';
import { formatCadence, ruleTitle } from './schedule';
import type { ServiceRule } from './types';

function rule(over: Partial<ServiceRule> = {}): ServiceRule {
  return {
    id: '01J',
    vehicleId: 'v1',
    type: 'oil_change',
    action: 'change',
    component: null,
    everyUsage: null,
    everyMonths: null,
    firstUsageValue: null,
    source: 'user',
    verified: false,
    url: null,
    ...over,
  };
}

describe('ruleTitle', () => {
  it('joins the service label with its action verb', () => {
    expect(ruleTitle(rule({ type: 'oil_change', action: 'change' }))).toBe('Vidange · changer');
  });

  it('appends the component when present', () => {
    expect(ruleTitle(rule({ type: 'air_filter', action: 'replace', component: 'paper cartridge' }))).toBe(
      'Filtre à air · remplacer — paper cartridge',
    );
  });

  it('falls back to the raw type for unknown services', () => {
    expect(ruleTitle(rule({ type: 'widget', action: 'inspect' }))).toBe('widget · contrôler');
  });
});

describe('formatCadence', () => {
  it('formats a usage cadence with the French "h" abbreviation', () => {
    expect(formatCadence(rule({ everyUsage: { value: 50, unit: 'hours' } }))).toBe('tous les 50 h');
  });

  it('keeps km verbatim and groups the reading', () => {
    expect(formatCadence(rule({ everyUsage: { value: 15000, unit: 'km' } }))).toBe('tous les 15 000 km');
  });

  it('formats a time cadence in months', () => {
    expect(formatCadence(rule({ everyMonths: 12 }))).toBe('tous les 12 mois');
  });

  it('formats a single month without an "s"', () => {
    expect(formatCadence(rule({ everyMonths: 1 }))).toBe('tous les 1 mois');
  });

  it('joins a usage and time cadence with a middot', () => {
    expect(formatCadence(rule({ everyUsage: { value: 15000, unit: 'km' }, everyMonths: 12 }))).toBe(
      'tous les 15 000 km · tous les 12 mois',
    );
  });

  it('appends the break-in first interval', () => {
    expect(formatCadence(rule({ everyUsage: { value: 50, unit: 'hours' }, firstUsageValue: 5 }))).toBe(
      'tous les 50 h · 1ʳᵉ à 5',
    );
  });

  it('shows the first interval alone when it is the only cadence', () => {
    expect(formatCadence(rule({ firstUsageValue: 5 }))).toBe('1ʳᵉ à 5');
  });

  it('falls back to a dash when no cadence is set', () => {
    expect(formatCadence(rule())).toBe('—');
  });
});
