import { describe, expect, it } from 'vitest';
import { reduce, type Event } from './state';
import type { Cta, DashboardState, Vehicle } from './types';

function vehicle(id: string): Vehicle {
  return { id, make: 'X', model: 'Y' } as Vehicle;
}

const cta: Cta = { headline: 'h', finding: 'f', actions: [] };

function baseState(over: Partial<DashboardState> = {}): DashboardState {
  return {
    vehicles: [vehicle('/garage/vehicles/a'), vehicle('/garage/vehicles/b')],
    selectedId: '/garage/vehicles/a',
    factPanel: null,
    cta: null,
    toasts: [],
    live: [],
    recency: [],
    ...over,
  };
}

const select = (id: string): Event => ({ kind: 'action', action: { type: 'selectVehicle', id } });

describe('selectVehicle reducer', () => {
  it('keeps a CTA when the agent selects the vehicle it just proposed actions for', () => {
    // The agent emits propose_actions then select_vehicle in the same turn; the select frame must
    // not wipe the CTA it just raised (the fade-in-then-away regression).
    const state = baseState({ cta });
    const next = reduce(state, select('/garage/vehicles/a'));
    expect(next.cta).toBe(cta);
  });

  it('keeps a CTA when focus moves to another vehicle within the turn', () => {
    const state = baseState({ cta });
    const next = reduce(state, select('/garage/vehicles/b'));
    expect(next.selectedId).toBe('/garage/vehicles/b');
    expect(next.cta).toBe(cta);
  });
});
