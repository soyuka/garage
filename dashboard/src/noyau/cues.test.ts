import { describe, it, expect } from 'vitest';
import { CUES, SIGNAL } from '../tokens/audition';
import { CUE_BURST_INIT, cueBurstReduce, loopSounds } from './cues';

describe('the burst coalescer', () => {
  it('fires the leading cue immediately and suppresses repeats inside 120ms', () => {
    let s = CUE_BURST_INIT;
    const first = cueBurstReduce(s, { type: 'want', cue: 'panel', at: 10 });
    expect(first.fire).toBe('panel');
    s = first.state;
    expect(cueBurstReduce(s, { type: 'want', cue: 'panel', at: 80 }).fire).toBeNull();
    expect(CUES.burstMs).toBe(120);
  });

  /**
   * The one deliberate difference from the camera's coalescer (`pilot.ts` `burstReduce`), which takes
   * the region the burst ENDED on: to the ear a burst that ghosts the shell and then raises a panel is
   * a reveal, so the biggest event wins regardless of arrival order.
   */
  it('allows one larger visual event to overtake the leading cue', () => {
    let s = CUE_BURST_INIT;
    s = cueBurstReduce(s, { type: 'want', cue: 'panel', at: 10 }).state;
    const reveal = cueBurstReduce(s, { type: 'want', cue: 'reveal', at: 40 });
    expect(reveal.fire).toBe('reveal');
    expect(cueBurstReduce(reveal.state, { type: 'want', cue: 'select', at: 80 }).fire).toBeNull();
  });

  it('opens a fresh burst after the bounded window', () => {
    let s = CUE_BURST_INIT;
    s = cueBurstReduce(s, { type: 'want', cue: 'select', at: 10 }).state;
    expect(cueBurstReduce(s, { type: 'want', cue: 'select', at: 140 }).fire).toBe('select');
  });
});

describe('the working loop', () => {
  const work = (over: Partial<Parameters<typeof loopSounds>[0]> = {}) => ({
    busy: true,
    speaking: false,
    sinceMs: CUES.loopAfterMs + 1,
    ...over,
  });

  it('sounds only once the wait is long enough to be a wait', () => {
    expect(loopSounds(work())).toBe(true);
    expect(loopSounds(work({ sinceMs: CUES.loopAfterMs - 1 }))).toBe(false);
  });

  it('continues under the voice — the mix ducks it instead of resetting real work', () => {
    expect(loopSounds(work({ speaking: true }))).toBe(true);
  });

  it('never sounds when no work is in flight, which is what keeps it from being theatre', () => {
    expect(loopSounds(work({ busy: false }))).toBe(false);
    expect(loopSounds(work({ busy: false, sinceMs: 60_000 }))).toBe(false);
  });
});

describe('levels', () => {
  /** Nothing the Atelier plays may reach the one signal, or the thing that must cut through stops doing so. */
  it('keeps every cue, and the loop, under signal level', () => {
    const masteredPeak = 10 ** (-12 / 20);
    for (const [name, gain] of Object.entries(CUES.gain)) expect(gain * masteredPeak, name).toBeLessThan(SIGNAL.peakGain);
    expect(CUES.loopGain).toBeLessThan(Math.min(...Object.values(CUES.gain)));
  });

  /**
   * Raised 2026-09-17: the palette was mixed a full 6 dB under its own ceiling, so the room was inaudible
   * over a workshop. The assets are mastered to -12 dBFS and quiet recordings are not boosted
   * (CHOREOGRAPHY, transition cues), which makes unity the honest ceiling: the loudest cue now plays its
   * file untouched rather than attenuated, and every other gain keeps its ratio to it.
   */
  it('plays the loudest cue at unity instead of attenuating an already-mastered file', () => {
    expect(Math.max(...Object.values(CUES.gain))).toBe(1);
  });
});
