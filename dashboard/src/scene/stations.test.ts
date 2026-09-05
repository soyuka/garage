import { describe, it, expect } from 'vitest';
import { stationFor } from './stations';
import type { Focus } from '../focus';

// Precedence lives in ONE tested place (CHOREOGRAPHY § "Stations under both drivers", "Precedence"):
// legends (the full Carnet/Fiche drawers) win over any focus-driven projection, and a focus carrying
// `nodes` (fluids) is checked before the stored-facts/carnet cases.

describe('stationFor — station precedence', () => {
  it('no legend, no focus ⇒ no station', () => {
    expect(stationFor({ historyOpen: false, factsDrawerOpen: false, focus: null })).toBeNull();
  });

  it('historyOpen wins over everything, including a specs/carnet focus', () => {
    const focus: Focus = { panel: 'carnet', keys: [] };
    expect(stationFor({ historyOpen: true, factsDrawerOpen: false, focus })).toBe('history');
    expect(stationFor({ historyOpen: true, factsDrawerOpen: true, focus })).toBe('history');
  });

  it('factsDrawerOpen wins over a focus when history is not open', () => {
    const focus: Focus = { panel: 'specs', keys: [], nodes: [{ key: 'k', label: 'l', value: 'v', verified: true }] };
    expect(stationFor({ historyOpen: false, factsDrawerOpen: true, focus })).toBe('data');
  });

  it('a specs focus carrying nodes is the fluids station', () => {
    const focus: Focus = { panel: 'specs', keys: [], nodes: [{ key: 'k', label: 'l', value: 'v', verified: true }] };
    expect(stationFor({ historyOpen: false, factsDrawerOpen: false, focus })).toBe('fluids');
  });

  it('a carnet focus is the carnet station', () => {
    const focus: Focus = { panel: 'carnet', keys: ['oil_change'] };
    expect(stationFor({ historyOpen: false, factsDrawerOpen: false, focus })).toBe('carnet');
  });

  it('a specs focus with NO nodes is the specs station (stored facts)', () => {
    const focus: Focus = { panel: 'specs', keys: ['oil_type'] };
    expect(stationFor({ historyOpen: false, factsDrawerOpen: false, focus })).toBe('specs');
  });

  it('an unrelated panel (tasks/visits/parts) is no station yet', () => {
    const focus: Focus = { panel: 'tasks', keys: [] };
    expect(stationFor({ historyOpen: false, factsDrawerOpen: false, focus })).toBeNull();
  });
});
