import { describe, it, expect } from 'vitest';
import { NBSP } from './format';
import { BURST_MS, PILOT_QUIET_MS } from './tokens/motion';
import {
  ABANDON_MS,
  BURST_INIT,
  burstReduce,
  formatSilence,
  isQuiet,
  silenceMs,
  STAGE_INIT,
  stageReduce,
  type StageState,
} from './pilot';
import type { UIAction } from './types';

const CLIO = '/garage/vehicles/clio';
const KANGOO = '/garage/vehicles/kangoo';

const select = (id: string): UIAction => ({ type: 'selectVehicle', id });
const fact: UIAction = { type: 'showFact', keys: ['oil_capacity'], title: 'Huile' };
const tasks: UIAction = { type: 'focusView', panel: 'tasks', keys: ['oil_change'] };

/** Feed a sequence of frames, returning the final state + everything that was emitted. */
function run(events: Parameters<typeof stageReduce>[1][], from: StageState = STAGE_INIT) {
  let state = from;
  const emitted: UIAction[] = [];
  for (const ev of events) {
    const out = stageReduce(state, ev);
    state = out.state;
    emitted.push(...out.emit);
  }
  return { state, emitted };
}

describe('the render-intent staging gate', () => {
  it('passes an intent straight through when no car is driving in', () => {
    const { state, emitted } = run([{ type: 'frame', action: fact, shownIri: CLIO, at: 0 }]);
    expect(emitted).toEqual([fact]);
    expect(state.awaiting).toBeNull();
  });

  it('holds the intents of a burst until the car they are about has arrived, then replays them in order', () => {
    // The pilot's burst: drive the Kangoo in, then answer about it — 300ms apart, arriving long
    // before the bundle. Without the gate the nav clears the focus and the answer never lands.
    const { state, emitted } = run([
      { type: 'frame', action: select(KANGOO), shownIri: CLIO, at: 0 },
      { type: 'frame', action: tasks, shownIri: CLIO, at: 120 },
      { type: 'frame', action: fact, shownIri: CLIO, at: 300 },
    ]);
    expect(emitted).toEqual([select(KANGOO)]); // only the drive-forward so far
    expect(state.awaiting).toBe(KANGOO);

    const arrival = stageReduce(state, { type: 'arrived', iri: KANGOO, at: 1500 });
    expect(arrival.emit).toEqual([tasks, fact]);
    expect(arrival.state.awaiting).toBeNull();
    expect(arrival.state.held).toEqual([]);
  });

  it('never holds the heartbeat — narration and toasts land while the car is still driving in', () => {
    const status: UIAction = { type: 'status', message: 'Je relis le carnet…' };
    const { emitted } = run([
      { type: 'frame', action: select(KANGOO), shownIri: CLIO, at: 0 },
      { type: 'frame', action: status, shownIri: CLIO, at: 10 },
      { type: 'frame', action: fact, shownIri: CLIO, at: 20 },
    ]);
    expect(emitted).toEqual([select(KANGOO), status]);
  });

  it('holds a document the pilot opens while its car is still driving in — it is that car\'s paper', () => {
    const doc: UIAction = { type: 'showDocument', url: '/garage/vehicles/kangoo/documents/01D/file', title: 'MR 361', contentType: 'application/pdf', filename: 'mr361.pdf', page: 2, highlight: 'huile' };
    const { state, emitted } = run([
      { type: 'frame', action: select(KANGOO), shownIri: CLIO, at: 0 },
      { type: 'frame', action: doc, shownIri: CLIO, at: 200 },
    ]);
    expect(emitted).toEqual([select(KANGOO)]);
    expect(stageReduce(state, { type: 'arrived', iri: KANGOO, at: 900 }).emit).toEqual([doc]);
  });

  it('does not stage anything when the pilot re-selects the car already on screen', () => {
    const { state, emitted } = run([
      { type: 'frame', action: select(CLIO), shownIri: CLIO, at: 0 },
      { type: 'frame', action: fact, shownIri: CLIO, at: 40 },
    ]);
    expect(emitted).toEqual([select(CLIO), fact]);
    expect(state.awaiting).toBeNull();
  });

  it('drops the held intents when a second selectVehicle supersedes the first — they were about the other car', () => {
    const first = run([
      { type: 'frame', action: select(KANGOO), shownIri: CLIO, at: 0 },
      { type: 'frame', action: fact, shownIri: CLIO, at: 50 },
    ]);
    const out = stageReduce(first.state, { type: 'frame', action: select(CLIO), shownIri: CLIO, at: 100 });
    expect(out.emit).toEqual([select(CLIO)]);
    expect(out.dropped).toBe(1);
    expect(out.state.held).toEqual([]);
    expect(out.state.awaiting).toBeNull(); // the Clio is already on screen
  });

  it('abandons a stage whose car never arrives, and says how many intents it dropped', () => {
    const { state } = run([
      { type: 'frame', action: select('/garage/vehicles/ghost'), shownIri: CLIO, at: 0 },
      { type: 'frame', action: fact, shownIri: CLIO, at: 50 },
    ]);
    expect(stageReduce(state, { type: 'expire', at: ABANDON_MS - 1 }).state).toBe(state); // not yet
    const out = stageReduce(state, { type: 'expire', at: ABANDON_MS });
    expect(out.dropped).toBe(1);
    expect(out.emit).toEqual([]);
    expect(out.state.awaiting).toBeNull();
  });

  it('resets on navigation — the operator leaving takes the staged intents with them', () => {
    const { state } = run([
      { type: 'frame', action: select(KANGOO), shownIri: CLIO, at: 0 },
      { type: 'frame', action: fact, shownIri: CLIO, at: 50 },
    ]);
    expect(stageReduce(state, { type: 'reset' }).state).toEqual(STAGE_INIT);
  });
});

describe('the burst coalescer (one dolly per gesture)', () => {
  it('fires nothing until the burst window has closed', () => {
    const a = burstReduce(BURST_INIT, { type: 'target', region: 'hood', at: 0 });
    expect(a.fire).toBeNull();
    expect(burstReduce(a.state, { type: 'flush', at: BURST_MS - 1 }).fire).toBeNull();
  });

  it('dollies ONCE, to where the burst ended — the conclusion, not the path', () => {
    let s = burstReduce(BURST_INIT, { type: 'target', region: 'hood', at: 0 }).state;
    s = burstReduce(s, { type: 'target', region: 'frontAxle', at: 40 }).state;
    const out = burstReduce(s, { type: 'target', region: 'rearAxle', at: 90 });
    expect(out.fire).toBeNull();
    const flushed = burstReduce(out.state, { type: 'flush', at: 90 + BURST_MS });
    expect(flushed.fire).toBe('rearAxle');
    expect(flushed.state).toEqual(BURST_INIT);
  });

  it('an isolated intent still dollies — one target is a burst of one', () => {
    const s = burstReduce(BURST_INIT, { type: 'target', region: 'bay', at: 1000 }).state;
    expect(burstReduce(s, { type: 'flush', at: 1000 + BURST_MS }).fire).toBe('bay');
  });

  it('a flush with nothing pending fires nothing (a stale timer is inert)', () => {
    expect(burstReduce(BURST_INIT, { type: 'flush', at: 5000 }).fire).toBeNull();
  });
});

describe('the silence read (what the veille pilotée caption says)', () => {
  it('measures the gap since the last pilot frame', () => {
    expect(silenceMs(1000, 4200)).toBe(3200);
    expect(silenceMs(null, 4200)).toBeNull();
  });

  it('goes quiet only past the threshold — a live burst never trips it', () => {
    expect(isQuiet(0, PILOT_QUIET_MS - 1)).toBe(false);
    expect(isQuiet(0, PILOT_QUIET_MS)).toBe(true);
    expect(isQuiet(null, 10_000)).toBe(false);
  });

  it('reads the gap coarsely — seconds, then minutes, then hours', () => {
    expect(formatSilence(4200)).toBe(`4${NBSP}s`);
    expect(formatSilence(59_400)).toBe(`59${NBSP}s`);
    expect(formatSilence(60_000)).toBe(`1${NBSP}min`);
    expect(formatSilence(9 * 60_000 + 30_000)).toBe(`9${NBSP}min`);
    expect(formatSilence(3 * 3_600_000 + 4 * 60_000)).toBe(`3${NBSP}h${NBSP}04`);
  });
});
