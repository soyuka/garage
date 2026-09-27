import { describe, it, expect } from 'vitest';
import { focusForAction, sameFocus } from './focus';
import type { UIAction } from './types';

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

  it('maps showHistory onto the carnet panel focus (the "Carnet" wayfinder surface)', () => {
    expect(focusForAction({ type: 'showHistory' })).toEqual({ panel: 'carnet', keys: [] });
  });

  it('raises the carnet from a SERVER focusView — the same surface, on the wire', () => {
    expect(focusForAction({ type: 'focusView', panel: 'carnet' })).toEqual({ panel: 'carnet', keys: [] });
  });

  it('carries a showFact projection (fetched nodes) into the focus', () => {
    const nodes = [{ key: 'oil_capacity', label: 'Huile moteur', value: '4,3 l', verified: true, info: 'ACEA E3' }];
    expect(focusForAction({ type: 'showFact', keys: [], title: 'Capacités fluides', nodes })).toEqual({
      panel: 'specs',
      keys: [],
      title: 'Capacités fluides',
      nodes,
    });
  });

  it('keeps the spotlight keys alongside nodes — nodes are the CONTENT, keys light the car', () => {
    const nodes = [{ key: 'oil_capacity', label: 'Huile moteur', value: '4,3 l', verified: false }];
    expect(focusForAction({ type: 'showFact', keys: ['oil_change'], nodes })).toEqual({
      panel: 'specs',
      keys: ['oil_change'],
      title: undefined,
      nodes,
    });
  });

  it('leaves nodes unset when the frame carries none (the stored-facts path is unchanged)', () => {
    expect(focusForAction({ type: 'showFact', keys: ['oil_capacity'] })).not.toHaveProperty('nodes');
  });

  it('returns null for showParts (it drives the parts manifest, not the slot focus)', () => {
    expect(focusForAction({ type: 'showParts', service: 'Vidange', taskId: '/planned-tasks/1' })).toBeNull();
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

describe('sameFocus', () => {
  it('suppresses only a repeated visible panel, not changed projected content', () => {
    const first = focusForAction({ type: 'showFact', keys: [], title: 'Fluides', nodes: [{ key: 'oil', label: 'Huile', value: '4 l', verified: true }] })!;
    expect(sameFocus(first, { ...first })).toBe(true);
    expect(sameFocus(first, { ...first, title: 'Capacités' })).toBe(false);
    expect(sameFocus(first, { ...first, nodes: [{ key: 'oil', label: 'Huile', value: '4,3 l', verified: true }] })).toBe(false);
  });
});
