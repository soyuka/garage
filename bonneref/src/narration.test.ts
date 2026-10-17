import { initialTurn, turnReducer } from './agent';
import { narration, sheetLine } from './narration';
import type { FinderFrame, TurnState } from './types';

const frame = (f: FinderFrame) => ({ type: 'frame' as const, frame: f });
const running = (): TurnState => turnReducer(turnReducer(initialTurn, { type: 'start', instruction: 'filtre à huile' }), frame({ type: 'turn', phase: 'start' }));
const said = (message: string): TurnState => turnReducer(running(), frame({ type: 'status', message }));

describe('narration', () => {
  it('is the agent’s latest sentence while the turn runs', () => {
    expect(narration(said('Lecture de la plaque.'))).toBe('Lecture de la plaque.');
    expect(narration(turnReducer(said('Lecture de la plaque.'), frame({ type: 'status', message: 'Recherche du filtre.' })))).toBe('Recherche du filtre.');
  });

  it('is nothing before the first sentence, once the turn is over, or when idle', () => {
    expect(narration(running())).toBeNull();
    expect(narration(initialTurn)).toBeNull();
    expect(narration(turnReducer(said('x'), frame({ type: 'turn', phase: 'end' })))).toBeNull();
  });
});

describe('sheetLine', () => {
  const fallback = 'Recherche dans le catalogue du véhicule.';

  it('shows the live sentence in place of the static searching line', () => {
    expect(sheetLine({ narration: 'Lecture de la plaque.', status: fallback, searching: true, hint: null })).toEqual({ kind: 'narration', text: 'Lecture de la plaque.' });
  });

  it('keeps the static line until the first sentence arrives', () => {
    expect(sheetLine({ narration: null, status: fallback, searching: true, hint: null })).toEqual({ kind: 'narration', text: fallback });
  });

  it('shows the live sentence over a part being refined, where no static line exists', () => {
    expect(sheetLine({ narration: 'Affinage par la cote.', status: null, searching: false, hint: 'x' })).toEqual({ kind: 'narration', text: 'Affinage par la cote.' });
  });

  it('gives the refine hint only when nothing is being narrated', () => {
    expect(sheetLine({ narration: null, status: null, searching: false, hint: '2 pièces possibles.' })).toEqual({ kind: 'hint', text: '2 pièces possibles.' });
    expect(sheetLine({ narration: null, status: fallback, searching: true, hint: '2 pièces possibles.' })).toEqual({ kind: 'narration', text: fallback });
  });

  it('is empty otherwise', () => {
    expect(sheetLine({ narration: null, status: null, searching: false, hint: null })).toBeNull();
    expect(sheetLine({ narration: null, status: 'Erreur', searching: false, hint: null })).toBeNull();
  });
});
