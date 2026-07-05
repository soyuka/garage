import { describe, expect, it } from 'vitest';
import { centsToEurosInput, parseEuros, visitTitle } from './records';
import type { MaintenanceRecord } from './types';

// Pure helpers for the maintenance-records surface (#11). Money is integer minor units on the wire
// (costCents/priceCents); the form takes euros, so the client converts both ways. Rebuilt fresh.

const rec = (over: Partial<MaintenanceRecord>): MaintenanceRecord => ({ id: '/garage/maintenance-records/x', items: [], performedAt: '2025-06-08', source: 'user', ...over });

describe('parseEuros', () => {
  it('parses a comma-decimal euro string (fr-FR) → euros', () => {
    expect(parseEuros('12,50')).toBe(12.5);
  });
  it('parses a dot-decimal euro string', () => {
    expect(parseEuros('12.5')).toBe(12.5);
  });
  it('parses an integer euro string', () => {
    expect(parseEuros('189')).toBe(189);
  });
  it('strips grouping spaces and a currency symbol', () => {
    expect(parseEuros('1 234,50 €')).toBe(1234.5); // U+202F + €
  });
  it('rounds to the nearest cent', () => {
    expect(parseEuros('10,999')).toBe(11);
  });
  it('zero is a real value, not absent', () => {
    expect(parseEuros('0')).toBe(0);
  });
  it('empty / blank / non-numeric → null', () => {
    expect(parseEuros('')).toBeNull();
    expect(parseEuros('   ')).toBeNull();
    expect(parseEuros('abc')).toBeNull();
  });
});

describe('centsToEurosInput', () => {
  it('formats the read-model cents to an editable comma-decimal euro string', () => {
    expect(centsToEurosInput(1250)).toBe('12,50');
  });
  it('null / undefined → empty string', () => {
    expect(centsToEurosInput(null)).toBe('');
    expect(centsToEurosInput(undefined)).toBe('');
  });
  it('round-trips a stored amount back to euros via parseEuros', () => {
    expect(parseEuros(centsToEurosInput(18900))).toBe(189);
  });
});

describe('visitTitle', () => {
  it('prefers a non-blank visit label', () => {
    expect(visitTitle(rec({ label: 'Révision annuelle', items: [{ type: 'oil_change', parts: [] }] }))).toBe('Révision annuelle');
  });
  it('falls back to the single service label', () => {
    expect(visitTitle(rec({ items: [{ type: 'oil_change', parts: [] }] }))).toBe('Vidange');
  });
  it('a multi-service visit with no label reads "Entretien"', () => {
    expect(visitTitle(rec({ items: [{ type: 'oil_change', parts: [] }, { type: 'brake_pads', parts: [] }] }))).toBe('Entretien');
  });
  it('no items and no label → "—"', () => {
    expect(visitTitle(rec({ items: [] }))).toBe('—');
  });
});
