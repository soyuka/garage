import { describe, it, expect } from 'vitest';
import { partCategory } from './PartsShelf';
import type { PlannedPart } from '../types';

const part = (p: Partial<PlannedPart>): PlannedPart => ({ ref: '', ...p });

describe('partCategory', () => {
  it('reads filtre/filter BEFORE oil (an oil filter is a filter)', () => {
    expect(partCategory(part({ ref: 'MANN W 712/75', label: "Filtre à huile" }))).toBe('filter');
    expect(partCategory(part({ ref: 'PURFLUX LS218', label: 'Oil filter' }))).toBe('filter');
    expect(partCategory(part({ ref: 'BOSCH 0451103316', brand: 'Filter Co' }))).toBe('filter');
  });

  it('maps huile/oil to oil', () => {
    expect(partCategory(part({ ref: 'MOTUL 5W-30', label: "Huile moteur" }))).toBe('oil');
    expect(partCategory(part({ ref: 'CASTROL EDGE', label: 'Engine oil 5W30' }))).toBe('oil');
  });

  it('maps frein/brake/disque/plaquette to brake', () => {
    expect(partCategory(part({ ref: 'X', label: 'Plaquettes de frein' }))).toBe('brake');
    expect(partCategory(part({ ref: 'X', label: 'Disque de frein' }))).toBe('brake');
    expect(partCategory(part({ ref: 'X', label: 'Brake pads' }))).toBe('brake');
  });

  it('maps pneu/tyre/tire to tyre', () => {
    expect(partCategory(part({ ref: '205/55 R16', label: 'Pneu été' }))).toBe('tyre');
    expect(partCategory(part({ ref: 'X', label: 'Summer tyre' }))).toBe('tyre');
    expect(partCategory(part({ ref: 'X', label: 'Front tire' }))).toBe('tyre');
  });

  it('maps batterie/battery to battery', () => {
    expect(partCategory(part({ ref: 'VARTA E11', label: 'Batterie' }))).toBe('battery');
    expect(partCategory(part({ ref: 'X', label: 'AGM battery' }))).toBe('battery');
  });

  it('falls back to generic when nothing matches', () => {
    expect(partCategory(part({ ref: 'BOSCH 0986', label: 'Bougie' }))).toBe('generic');
    expect(partCategory(part({ ref: 'XYZ-001' }))).toBe('generic');
  });

  it('is case-insensitive across ref + label + brand', () => {
    expect(partCategory(part({ ref: 'FILTRE-001' }))).toBe('filter');
    expect(partCategory(part({ ref: '', brand: 'MOTUL OIL' }))).toBe('oil');
    expect(partCategory(part({ ref: '', label: 'FREIN AV' }))).toBe('brake');
  });
});
