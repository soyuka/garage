import { HOME } from './home';
import type { Route } from './route';
import type { TurnState } from './types';

// Typing adds to the search in progress: a result is up, or the agent waits for an answer.
export const refining = (route: Route, turn: TurnState): boolean => route.view === 'result' || turn.parts.length > 0 || turn.ask !== null;

export const submitLabel = (route: Route, turn: TurnState): string => (refining(route, turn) ? 'Envoyer' : 'Chercher');

export const REFINE_PLACEHOLDER = 'Précisez : année, moteur…';

export function composerPlaceholder(route: Route, turn: TurnState, hasVehicle: boolean): string {
  if (refining(route, turn)) return REFINE_PLACEHOLDER;
  return hasVehicle ? 'filtre à huile, W 712/73…' : HOME.placeholder;
}
