import { describe, expect, it } from 'vitest';
import { NBSP, THIN } from './format';
import { actionVerb, formatCadence, ruleTitle } from './schedule';
import type { ServiceRule } from './types';

// Pure formatting for the service-schedule editor (#11). The recurring rules behind the forecast:
// a title (service · verb — component) and a cadence line. French via format.ts. Rebuilt fresh.

const rule = (over: Partial<ServiceRule>): ServiceRule => ({ id: '01R', vehicleId: '/garage/vehicles/x', type: 'oil_change', action: 'change', source: 'user', verified: false, ...over });

describe('actionVerb', () => {
  it('maps the service actions to French verbs', () => {
    expect(actionVerb('change')).toBe('changer');
    expect(actionVerb('replace')).toBe('remplacer');
    expect(actionVerb('clean')).toBe('nettoyer');
    expect(actionVerb('inspect')).toBe('inspecter');
  });
  it('null action → empty', () => {
    expect(actionVerb(null)).toBe('');
  });
});

describe('ruleTitle', () => {
  it('is "<service> · <verb>"', () => {
    expect(ruleTitle(rule({ type: 'oil_change', action: 'change' }))).toBe('Vidange · changer');
  });
  it('appends a component after an em-dash', () => {
    expect(ruleTitle(rule({ type: 'brake_pads', action: 'replace', component: 'avant' }))).toBe('Plaquettes de frein · remplacer — avant');
  });
});

describe('formatCadence', () => {
  it('a usage cadence reads "tous les <reading>"', () => {
    expect(formatCadence(rule({ everyUsage: { value: 10000, unit: 'km' } }))).toBe(`tous les 10${THIN}000${NBSP}km`);
  });
  it('engine-hours cadence shows the short "h" (not the maintenance-side "hours")', () => {
    expect(formatCadence(rule({ everyUsage: { value: 50, unit: 'hours' } }))).toBe(`tous les 50${NBSP}h`);
  });
  it('a month cadence reads "tous les N mois"', () => {
    expect(formatCadence(rule({ everyMonths: 12 }))).toBe(`tous les 12${NBSP}mois`);
  });
  it('combines usage + months with a middot', () => {
    expect(formatCadence(rule({ everyUsage: { value: 10000, unit: 'km' }, everyMonths: 12 }))).toBe(`tous les 10${THIN}000${NBSP}km · tous les 12${NBSP}mois`);
  });
  it('a break-in first interval is appended', () => {
    expect(formatCadence(rule({ everyUsage: { value: 50, unit: 'hours' }, firstUsageValue: 5 }))).toBe(`tous les 50${NBSP}h · d’abord à 5${NBSP}h`);
  });
  it('no cadence → an honest placeholder', () => {
    expect(formatCadence(rule({}))).toBe('cadence non définie');
  });
});
