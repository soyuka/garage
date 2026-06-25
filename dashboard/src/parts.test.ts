import { describe, expect, it } from 'vitest';
import { partSearchQuery, partShopLinks } from './parts';
import type { PlannedPart } from './types';

describe('partSearchQuery', () => {
  it('combines ref, brand and label for a catalog part', () => {
    const part: PlannedPart = { id: '1', ref: 'OC90', brand: 'MANN', label: 'filtre à huile' };
    expect(partSearchQuery(part)).toBe('OC90 MANN filtre à huile');
  });

  it('builds a fluid query from viscosity, label and standard', () => {
    const part: PlannedPart = {
      ref: '',
      label: 'huile moteur',
      spec: { verified: true, viscosity: '5W-30', standard: 'ACEA A5/B5' },
    };
    expect(partSearchQuery(part)).toBe('5W-30 huile moteur ACEA A5/B5');
  });

  it('flattens alternative standards separated by "|"', () => {
    const part: PlannedPart = {
      ref: '',
      label: 'huile moteur',
      spec: { verified: false, viscosity: '5W-40', standard: 'ACEA A3/B3|ACEA A5/B5' },
    };
    expect(partSearchQuery(part)).toBe('5W-40 huile moteur ACEA A3/B3 ACEA A5/B5');
  });

  it('returns an empty string when nothing is searchable', () => {
    expect(partSearchQuery({ ref: '' })).toBe('');
  });
});

describe('partShopLinks', () => {
  it('produces Mister-Auto and Auto-Doc search URLs for a fluid', () => {
    const part: PlannedPart = {
      ref: '',
      label: 'huile moteur',
      spec: { verified: true, viscosity: '5W-30', standard: 'ACEA A5/B5' },
    };
    expect(partShopLinks(part)).toEqual([
      { name: 'Mister-Auto', url: 'https://www.mister-auto.com/rechercher/?q=5W-30%20huile%20moteur%20ACEA%20A5%2FB5' },
      { name: 'Auto-Doc', url: 'https://www.auto-doc.fr/search?keyword=5W-30+huile+moteur+ACEA+A5%2FB5' },
    ]);
  });

  it('returns no links when the part has nothing searchable', () => {
    expect(partShopLinks({ ref: '' })).toEqual([]);
  });
});
