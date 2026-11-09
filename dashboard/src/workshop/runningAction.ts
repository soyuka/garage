import type { NoyauState } from '../noyau/state';

export interface CommandStatus {
  running: boolean;
  canCancel: boolean;
}

export function runningAction(state: NoyauState): CommandStatus {
  const running = state.phase === 'working';
  return { running, canCancel: running && state.driver !== 'pilot' };
}
