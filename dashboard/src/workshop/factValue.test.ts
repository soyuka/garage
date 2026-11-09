import { describe, expect, it } from 'vitest';
import { NBSP } from '../format';
import { factValues } from './factValue';

describe('factValues', () => {
  it('formats a quantity with locale decimals and a unit symbol', () => {
    expect(factValues('5.4', 'liter', 'fr')).toEqual([`5,4${NBSP}L`]);
    expect(factValues('5.4', 'liter', 'en')).toEqual([`5.4${NBSP}L`]);
    expect(factValues('965', 'gramme', 'fr')).toEqual([`965${NBSP}g`]);
    expect(factValues('190', 'cm³', 'fr')).toEqual([`190${NBSP}cm³`]);
    expect(factValues('2.4', 'L', 'fr')).toEqual([`2,4${NBSP}L`]);
  });

  it('splits pipe-joined values into separate tags', () => {
    expect(factValues('ACEA A3/B3|ACEA A5/B5', null, 'fr')).toEqual(['ACEA A3/B3', 'ACEA A5/B5']);
  });

  it('leaves text values alone', () => {
    expect(factValues('SAE 75W-80', null, 'fr')).toEqual(['SAE 75W-80']);
    expect(factValues('F4R turbo (F4R 896/897)', null, 'fr')).toEqual(['F4R turbo (F4R 896/897)']);
  });

  it('keeps an unknown unit as given', () => {
    expect(factValues('3', 'pinte', 'fr')).toEqual([`3${NBSP}pinte`]);
  });

  it('leaves an identifier as written: no thousand grouping, no decimal rewrite', () => {
    expect(factValues('982488', null, 'fr', { identifier: true })).toEqual(['982488']);
    expect(factValues('09824', null, 'fr', { identifier: true })).toEqual(['09824']);
  });
});
