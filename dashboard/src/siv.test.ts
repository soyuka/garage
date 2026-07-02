import { describe, it, expect } from 'vitest';
import { carteGriseNodes, type SivVehicle } from './siv';
import { NBSP, THIN } from './format';

// The "Fiche technique" chip fetches GET /vehicles/{plate} (SIV) and maps the rich registration identity
// to constellation nodes — power (kW · ch), displacement (cm³), gearbox, weight… — French-formatted,
// all verified (siv catalog). Absent fields are simply omitted (no empty node).

describe('carteGriseNodes — SIV vehicle → carte grise nodes', () => {
  const full: SivVehicle = {
    energy: 'GO',
    powerKw: 75,
    powerHp: 102,
    engineCapacityCcm: 1598,
    cylinders: 4,
    gearbox: 'M',
    seats: 5,
    weightKg: 1205,
    firstRegistration: '2018-04-15',
    fiscalPower: 6,
  };

  const byKey = (v: SivVehicle) => Object.fromEntries(carteGriseNodes(v).map((n) => [n.key, n]));

  it('formats power as "75 kW · 102 ch"', () => {
    expect(byKey(full).power.value).toBe(`75${NBSP}kW · 102${NBSP}ch`);
  });

  it('formats displacement as grouped cm³', () => {
    expect(byKey(full).displacement.value).toBe(`1${THIN}598${NBSP}cm³`);
  });

  it('formats weight in kg and fiscal power in CV', () => {
    const m = byKey(full);
    expect(m.weight.value).toBe(`1${THIN}205${NBSP}kg`);
    expect(m.fiscal_power.value).toBe(`6${NBSP}CV`);
  });

  it('maps the gearbox and energy codes to French words', () => {
    const m = byKey(full);
    expect(m.gearbox.value).toBe('Manuelle');
    expect(m.energy.value).toBe('Diesel');
  });

  it('formats the first registration as a French date', () => {
    expect(byKey(full).first_registration.value).toBe(`15${NBSP}avr.${NBSP}2018`);
  });

  it('marks every node verified (siv catalog)', () => {
    expect(carteGriseNodes(full).every((n) => n.verified)).toBe(true);
  });

  it('omits nodes for absent fields (no empty rows)', () => {
    const keys = carteGriseNodes({ powerKw: 60, powerHp: 82 }).map((n) => n.key);
    expect(keys).toEqual(['power']);
  });

  it('is empty for a bare response', () => {
    expect(carteGriseNodes({})).toEqual([]);
  });
});
