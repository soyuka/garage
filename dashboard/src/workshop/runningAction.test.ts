import { describe, expect, it } from 'vitest';
import { initialNoyau, reduceNoyau, type NoyauEvent, type NoyauState } from '../noyau/state';
import { runningAction } from './runningAction';

function run(events: Array<[NoyauEvent, number]>, from: NoyauState = initialNoyau): NoyauState {
  return events.reduce((s, [ev, at]) => reduceNoyau(s, ev, at, 'fr'), from);
}

describe('runningAction', () => {
  it('is at rest with nothing running', () => {
    expect(runningAction(initialNoyau)).toEqual({ running: false, canCancel: false });
  });

  it('a running turn can be cancelled', () => {
    const s = run([
      [{ type: 'turn', phase: 'start' }, 1000],
      [{ type: 'status', message: 'Je cherche le kit embrayage', tool: 'get_service_parts' }, 1500],
    ]);
    expect(runningAction(s)).toEqual({ running: true, canCancel: true });
  });

  it('a pilot run cannot be cancelled from this tab', () => {
    const s = run([[{ type: 'status', message: 'pilot step', tool: 'list_vehicles', driver: 'pilot' }, 0]]);
    expect(runningAction(s)).toEqual({ running: true, canCancel: false });
  });
});
