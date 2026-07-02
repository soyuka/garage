import { describe, it, expect } from 'vitest';
import { fluidNodes, type FillVolume } from './fluids';
import { NBSP } from './format';

// The "Capacités fluides" chip fetches GET /cars/{kType}/fill-volumes and maps each entry to a
// constellation node: a French label, a formatted value (capacity + unit and/or the raw spec info),
// and a verified (catalog) badge. Entries carrying neither a volume nor a spec info are dropped.

describe('fluidNodes — FillVolume[] → constellation nodes', () => {
  it('maps a capacity + unit + spec info into value ("4,3 L") + info', () => {
    const vols: FillVolume[] = [{ id: 1, label: 'huile moteur', volume: 4.3, unit: 'liter', info: 'ACEA E3|E4|E5' }];
    const [n] = fluidNodes(vols);
    expect(n.label).toBe('huile moteur');
    expect(n.value).toBe(`4,3${NBSP}L`);
    expect(n.info).toBe('ACEA E3|E4|E5');
    expect(n.verified).toBe(true);
  });

  it('folds a spec-only entry (no volume) into the value, with no separate info', () => {
    const [n] = fluidNodes([{ label: 'liquide de frein', volume: null, unit: null, info: 'DOT 4' }]);
    expect(n.value).toBe('DOT 4');
    expect(n.info).toBeUndefined();
  });

  it('abbreviates units and parses a string volume', () => {
    const nodes = fluidNodes([
      { label: 'clim', volume: '470', unit: 'gram', info: 'R134a' },
      { label: 'refroidissement', volume: 5, unit: 'liter' },
    ]);
    expect(nodes[0].value).toBe(`470${NBSP}g`);
    expect(nodes[0].info).toBe('R134a');
    expect(nodes[1].value).toBe(`5${NBSP}L`);
  });

  it('falls back to originalTitle for the label, then a generic word', () => {
    const nodes = fluidNodes([
      { originalTitle: 'Gearbox oil', volume: 2, unit: 'liter' },
      { volume: 1, unit: 'liter' },
    ]);
    expect(nodes[0].label).toBe('Gearbox oil');
    expect(nodes[1].label).toBe('Fluide');
  });

  it('drops entries that carry neither a volume nor an info', () => {
    expect(fluidNodes([{ label: 'vide' }, { label: 'x', info: '  ' }])).toEqual([]);
  });

  it('is empty for an empty response', () => {
    expect(fluidNodes([])).toEqual([]);
  });
});
