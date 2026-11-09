import { describe, expect, it } from 'vitest';
import { tickDwell, type DwellClock } from './noticeDwell';
import type { Notice } from './state';

// The auto-close decision. Pure and clock-injected, mirroring voice.ts: the impure edge (a polling
// effect in Atelier.tsx) owns nothing but the timer and the dispatch.

const notice = (id: string, at: number): Notice => ({ id, tone: 'info', text: id, at });

const empty: DwellClock = { anchors: {} };
const DWELL = 1000;

describe('the dwell anchor', () => {
  it('latches the moment a not-currently-speaking card is first observed', () => {
    const { clock } = tickDwell(empty, [notice('a', 0)], null, 500, DWELL);
    expect(clock.anchors.a).toBe(500);
  });

  it('does not latch, and never closes, while the card is the one being spoken', () => {
    const { clock, toClose } = tickDwell(empty, [notice('a', 0)], 'a', 5000, DWELL);
    expect(clock.anchors.a).toBeUndefined();
    expect(toClose).toEqual([]);
  });

  it('starts the dwell fresh the instant speech ends, not from the card’s arrival', () => {
    // Card arrives at t=0; its own line speaks for a long time, ending at t=5000.
    let clock = tickDwell(empty, [notice('a', 0)], 'a', 100, DWELL).clock;
    ({ clock } = tickDwell(clock, [notice('a', 0)], 'a', 4000, DWELL));
    // Speech just ended (no longer the speakingId) — anchor latches HERE, not at t=0.
    ({ clock } = tickDwell(clock, [notice('a', 0)], null, 5000, DWELL));
    expect(clock.anchors.a).toBe(5000);

    // Barely past the arrival+DWELL mark, but well short of anchor(5000)+DWELL — must not close yet.
    const mid = tickDwell(clock, [notice('a', 0)], null, 5500, DWELL);
    expect(mid.toClose).toEqual([]);

    const late = tickDwell(mid.clock, [notice('a', 0)], null, 5000 + DWELL, DWELL);
    expect(late.toClose).toEqual(['a']);
  });

  it('closes a card whose line was never spoken at all — muted and unmuted behave the same', () => {
    let clock = tickDwell(empty, [notice('a', 0)], null, 0, DWELL).clock;
    const { toClose } = tickDwell(clock, [notice('a', 0)], null, DWELL, DWELL);
    expect(toClose).toEqual(['a']);
  });

  it('does not close before the dwell elapses', () => {
    let clock = tickDwell(empty, [notice('a', 0)], null, 0, DWELL).clock;
    const { toClose } = tickDwell(clock, [notice('a', 0)], null, DWELL - 1, DWELL);
    expect(toClose).toEqual([]);
  });

  it('drops the anchor for a card no longer printed (vu, cap eviction) — no leak, no resurrection', () => {
    const clock = tickDwell(empty, [notice('a', 0)], null, 0, DWELL).clock;
    const { clock: next } = tickDwell(clock, [], null, 1, DWELL);
    expect(next.anchors).toEqual({});
  });

  it('tracks several cards independently', () => {
    let clock = tickDwell(empty, [notice('a', 0), notice('b', 0)], 'b', 0, DWELL).clock;
    expect(clock.anchors.a).toBe(0);
    expect(clock.anchors.b).toBeUndefined();

    const result = tickDwell(clock, [notice('a', 0), notice('b', 0)], null, DWELL, DWELL);
    // 'a' has dwelled the full window; 'b' only just stopped speaking this tick.
    expect(result.toClose).toEqual(['a']);
  });
});
