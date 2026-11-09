import { describe, it, expect } from 'vitest';
import { CUES, SIGNAL } from '../tokens/audition';
import { agentAnswered, CUE_BURST_INIT, cueBurstReduce, cueRepeats, loopScale, loopSounds } from './cues';
import { SPATIAL_PALETTE, WORKSHOP_PALETTE } from './palettes';

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

describe('the pointing tick', () => {
  it('outranks the paper slide that follows it in the same burst', () => {
    const s = cueBurstReduce(CUE_BURST_INIT, { type: 'want', cue: 'point', at: 10 }).state;
    expect(cueBurstReduce(s, { type: 'want', cue: 'select', at: 40 }).fire).toBeNull();
  });

  it('does not repeat inside its own window: a panel and the ring it moves are one point', () => {
    expect(cueRepeats(undefined, 1000, 1200)).toBe(true);
    expect(cueRepeats(1000, 1800, 1200)).toBe(false);
    expect(cueRepeats(1000, 2200, 1200)).toBe(true);
    expect(cueRepeats(1000, 1001, undefined)).toBe(true);
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

  it('falls silent for the rest of the turn once the agent has answered', () => {
    expect(loopSounds(work({ answered: true }))).toBe(false);
    expect(loopSounds(work({ answered: false }))).toBe(true);
  });

  it('honours its palette\'s own delay', () => {
    expect(loopSounds(work({ sinceMs: 1300 }), 1200)).toBe(true);
    expect(loopSounds(work({ sinceMs: 1100 }), 1200)).toBe(false);
  });

  it('thins to the palette\'s thin gain after a long wait', () => {
    expect(loopScale(29_000, 30_000, 0.5)).toBe(1);
    expect(loopScale(30_000, 30_000, 0.5)).toBe(0.5);
    expect(loopScale(600_000, Infinity, 0.5)).toBe(1);
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
   * over a workshop. The assets are mastered to -12 dBFS and quiet recordings are not boosted,
   * which makes unity the honest ceiling: the loudest cue now plays its
   * file untouched rather than attenuated, and every other gain keeps its ratio to it.
   */
  it('plays the loudest cue at unity instead of attenuating an already-mastered file', () => {
    expect(Math.max(...Object.values(CUES.gain))).toBe(1);
  });
});

describe('palettes', () => {
  it('keeps the hologram-era set for the legacy Atelier', () => {
    for (const cue of CUES.names.filter((c) => c !== 'point')) expect(SPATIAL_PALETTE.url[cue], cue).toBe(`/audio/spatial-2026-09-11/${cue}.wav`);
    expect(SPATIAL_PALETTE.url.point).toBeUndefined();
  });

  it('maps the workshop events onto the pen & workshop files, and leaves dismiss, mic and opening silent', () => {
    expect(WORKSHOP_PALETTE.url).toEqual({
      select: '/audio/workshop-2026-11/select.mp3',
      point: '/audio/workshop-2026-11/point.mp3',
      card: '/audio/workshop-2026-11/proposal.mp3',
      blocked: '/audio/workshop-2026-11/error.mp3',
      complete: '/audio/workshop-2026-11/settle.mp3',
      working: '/audio/workshop-2026-11/waiting.mp3',
    });
  });

  it('delays the waiting bed 1.2 s, fades it in over 600 ms and out over 300 ms, halves it after 30 s', () => {
    expect(WORKSHOP_PALETTE.loop).toEqual({ afterMs: 1200, fadeInMs: 600, fadeOutMs: 300, thinAfterMs: 30_000, thinGain: 0.5 });
  });

  it('keeps the legacy loop as it was', () => {
    expect(SPATIAL_PALETTE.loop.afterMs).toBe(CUES.loopAfterMs);
    expect(SPATIAL_PALETTE.loop.fadeInMs).toBe(CUES.loopFadeSeconds * 1000);
    expect(SPATIAL_PALETTE.loop.thinAfterMs).toBe(Infinity);
  });
});

describe('agentAnswered', () => {
  const start = { narration: 'Je regarde.', notices: 1, proposal: false, scans: 3 };

  it('does not count the click-time acknowledgement as the agent speaking', () => {
    expect(agentAnswered(start, start)).toBe(false);
  });

  it('keeps waiting through silent tool steps: a step chip is not the agent speaking', () => {
    expect(agentAnswered(start, { ...start, steps: ['acknowledge', 'get_service_parts'] } as typeof start)).toBe(false);
  });

  it('counts a narrated line, a new notice or a proposal', () => {
    expect(agentAnswered(start, { ...start, narration: 'Vidange dans 2 000 km.' })).toBe(true);
    expect(agentAnswered(start, { ...start, notices: 2 })).toBe(true);
    expect(agentAnswered(start, { ...start, proposal: true })).toBe(true);
  });

  it('counts a documentation scan on the viewer: a visible step, so the bed does not play over it', () => {
    expect(agentAnswered(start, { ...start, scans: 4 })).toBe(true);
  });

  it('ignores a notice going away', () => {
    expect(agentAnswered(start, { ...start, notices: 0 })).toBe(false);
  });
});
