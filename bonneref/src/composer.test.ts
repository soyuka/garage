import { initialTurn } from './agent';
import { composerPlaceholder, instructionError, refining, submitLabel } from './composer';
import { MAX_INSTRUCTION_LENGTH } from './limits';
import type { Route } from './route';
import type { TurnState } from './types';

const entry: Route = { view: 'entry' };
const vehicle: Route = { view: 'vehicle', ref: 'k16182-PC' };
const result: Route = { view: 'result', ref: 'k16182-PC', categoryId: 100259, productGroupId: null, cluster: null };
const asking: TurnState = { ...initialTurn, phase: 'done', ask: { question: 'Avant ou arrière ?', options: [], context: 'c' } };
const webPart = { ...initialTurn, parts: [{ kind: 'web' }] } as unknown as TurnState;

describe('composer wording', () => {
  it('searches on the home and on a vehicle’s empty sheet', () => {
    expect(submitLabel(entry, initialTurn)).toBe('Chercher');
    expect(submitLabel(vehicle, initialTurn)).toBe('Chercher');
    expect(composerPlaceholder(vehicle, initialTurn, true)).toBe('filtre à huile, W 712/73…');
  });

  it('sends on a result: typing adds to the search', () => {
    expect(submitLabel(result, initialTurn)).toBe('Envoyer');
    expect(composerPlaceholder(result, initialTurn, true)).toBe('Précisez : année, moteur…');
  });

  it('sends while the agent waits for an answer, and when a part is shown from a turn', () => {
    expect(submitLabel(vehicle, asking)).toBe('Envoyer');
    expect(submitLabel(vehicle, webPart)).toBe('Envoyer');
    expect(refining(entry, asking)).toBe(true);
  });
});

describe('instructionError', () => {
  it('accepts up to the server cap and refuses beyond, counting characters', () => {
    expect(instructionError('a'.repeat(MAX_INSTRUCTION_LENGTH))).toBeNull();
    expect(instructionError('é'.repeat(MAX_INSTRUCTION_LENGTH + 1))).toBe('500 caractères au plus.');
    expect(instructionError('😀'.repeat(MAX_INSTRUCTION_LENGTH))).toBeNull();
  });
});
