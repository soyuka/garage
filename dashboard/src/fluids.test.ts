import { describe, it, expect } from 'vitest';
import { fluidNodes, type FillVolume } from './fluids';
import { NBSP } from './format';

// The "Capacités fluides" chip fetches GET /cars/{kType}/fill-volumes and maps each entry to a
// constellation node: a French label, a formatted value (capacity + unit and/or the raw spec info),
// and a verified (catalog) badge. Entries carrying neither a volume nor a spec info are dropped.

describe('fluidNodes — FillVolume[] → constellation nodes', () => {
  it('maps a capacity + unit + spec info into value ("4,3 L") + info', () => {
    const vols: FillVolume[] = [{ id: 1, label: 'huile moteur', volume: 4.3, unit: 'liter', info: 'ACEA E3|E4|E5' }];
    const [n] = fluidNodes(vols, 'fr');
    expect(n.label).toBe('huile moteur');
    expect(n.value).toBe(`4,3${NBSP}L`);
    expect(n.info).toBe('ACEA E3|E4|E5');
    expect(n.verified).toBe(true);
  });

  it('folds a spec-only entry (no volume) into the value, with no separate info', () => {
    const [n] = fluidNodes([{ label: 'liquide de frein', volume: null, unit: null, info: 'DOT 4' }], 'fr');
    expect(n.value).toBe('DOT 4');
    expect(n.info).toBeUndefined();
  });

  it('abbreviates units and parses a string volume', () => {
    const nodes = fluidNodes([
      { label: 'clim', volume: '470', unit: 'gram', info: 'R134a' },
      { label: 'refroidissement', volume: 5, unit: 'liter' },
    ], 'fr');
    expect(nodes[0].value).toBe(`470${NBSP}g`);
    expect(nodes[0].info).toBe('R134a');
    expect(nodes[1].value).toBe(`5${NBSP}L`);
  });

  it('falls back to originalTitle for the label, then a generic word', () => {
    const nodes = fluidNodes([
      { originalTitle: 'Gearbox oil', volume: 2, unit: 'liter' },
      { volume: 1, unit: 'liter' },
    ], 'fr');
    expect(nodes[0].label).toBe('Gearbox oil');
    expect(nodes[1].label).toBe('Fluide');
  });

  it('drops entries that carry neither a volume nor an info', () => {
    expect(fluidNodes([{ label: 'vide' }, { label: 'x', info: '  ' }], 'fr')).toEqual([]);
  });

  it('is empty for an empty response', () => {
    expect(fluidNodes([], 'fr')).toEqual([]);
  });
});

describe('fluidNodes — volumeLitres (the fluid station body seam)', () => {
  it('sets volumeLitres straight from a litre unit', () => {
    const [n] = fluidNodes([{ label: 'huile moteur', volume: 4.3, unit: 'liter' }], 'fr');
    expect(n.volumeLitres).toBe(4.3);
  });

  it('converts a milliliter unit down to litres', () => {
    const [n] = fluidNodes([{ label: 'lave-glace', volume: 250, unit: 'ml' }], 'fr');
    expect(n.volumeLitres).toBeCloseTo(0.25);
  });

  it('converts cm³/cm3/cc down to litres, same as ml', () => {
    const [a] = fluidNodes([{ label: 'clim', volume: 150, unit: 'cm³' }], 'fr');
    expect(a.volumeLitres).toBeCloseTo(0.15);
    const [b] = fluidNodes([{ label: 'clim', volume: 150, unit: 'cm3' }], 'fr');
    expect(b.volumeLitres).toBeCloseTo(0.15);
    const [c] = fluidNodes([{ label: 'clim', volume: 150, unit: 'cc' }], 'fr');
    expect(c.volumeLitres).toBeCloseTo(0.15);
  });

  it('converts a centilitre unit down to litres (÷100)', () => {
    const [n] = fluidNodes([{ label: 'liquide de direction', volume: 80, unit: 'cl' }], 'fr');
    expect(n.volumeLitres).toBeCloseTo(0.8);
  });

  it('sets volumeLitres to null for a mass unit (g/kg) — not a volume', () => {
    const [g] = fluidNodes([{ label: 'clim', volume: 470, unit: 'gram' }], 'fr');
    expect(g.volumeLitres).toBeNull();
    const [kg] = fluidNodes([{ label: 'clim', volume: 1.2, unit: 'kg' }], 'fr');
    expect(kg.volumeLitres).toBeNull();
  });

  it('leaves volumeLitres undefined for an info-only node (no volume at all)', () => {
    const [n] = fluidNodes([{ label: 'liquide de frein', volume: null, unit: null, info: 'DOT 4' }], 'fr');
    expect(n.volumeLitres).toBeUndefined();
  });
});
