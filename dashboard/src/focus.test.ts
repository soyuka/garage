import { describe, it, expect } from 'vitest';
import { focusForAction, slotPanel } from './focus';
import type { UIAction } from './types';

describe('slotPanel', () => {
  it('defaults to the specs panel when nothing is focused', () => {
    expect(slotPanel(null)).toBe('specs');
  });

  it('follows the focused panel', () => {
    expect(slotPanel({ panel: 'tasks', keys: [] })).toBe('tasks');
    expect(slotPanel({ panel: 'specs', keys: ['oil_capacity'] })).toBe('specs');
  });
});

describe('focusForAction', () => {
  it('maps focusView to the unified focus state, keys default to empty', () => {
    expect(focusForAction({ type: 'focusView', panel: 'tasks', keys: ['oil_change'] })).toEqual({
      panel: 'tasks',
      keys: ['oil_change'],
    });
    expect(focusForAction({ type: 'focusView', panel: 'specs' })).toEqual({ panel: 'specs', keys: [] });
  });

  it('maps the legacy showFact onto the SAME focus state (specs panel)', () => {
    expect(focusForAction({ type: 'showFact', keys: ['oil_capacity'], title: 'Huile' })).toEqual({
      panel: 'specs',
      keys: ['oil_capacity'],
      title: 'Huile',
    });
  });

  it('returns null for any other action (no focus change)', () => {
    const others: UIAction[] = [
      { type: 'toast', tone: 'info', message: 'x' },
      { type: 'turn', phase: 'start' },
      { type: 'selectVehicle', id: '/garage/vehicles/01J' },
    ];
    for (const a of others) expect(focusForAction(a)).toBeNull();
  });
});
