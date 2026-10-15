import { initialTurn, turnReducer } from './agent';
import { demoTurn, isDemoTurn } from './demo-turn';
import { partFamily } from './family';

describe('demo turn', () => {
  it('only answers the scripted request', () => {
    expect(isDemoTurn('Rotule axiale Peugeot 307')).toBe(true);
    expect(isDemoTurn('filtre à huile')).toBe(false);
  });

  it('plays through the real reducer to two parts told apart by build code, and a question', () => {
    const state = demoTurn().reduce((s, { frame }) => turnReducer(s, { type: 'frame', frame }), turnReducer(initialTurn, { type: 'start', instruction: 'rotule axiale peugeot 307' }));
    expect(state.phase).toBe('done');
    expect(state.vehicle).toMatchObject({ carId: 16182, carType: 'PC' });
    const part = state.parts[0]!;
    expect(part.kind === 'catalog' && part.narrowing.clusters.map((c) => c.applicability.length)).toEqual([1, 1]);
    expect(state.ask?.question).toMatch(/code de fabrication/i);
  });

  it('labels its references as illustrative, never as catalogue data', () => {
    const part = demoTurn().find(({ frame }) => frame.type === 'showParts')!.frame;
    expect(JSON.stringify(part)).toMatch(/DÉMO/);
    expect(partFamily({ path: 'Direction > Rotules', name: '' })).toEqual({ kind: 'generic' });
  });
});
