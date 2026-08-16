import { describe, expect, it } from 'vitest';
import { VOICE_CAP, VOICE_STALE_MS, cancel, enqueue, finish, next, type VoiceState } from './voice';
import type { Notice } from './state';

// The ear's own rules, pure and clock-injected. The hook around this file is untested by design
// (no jsdom in this repo) — which is exactly why the ordering, the cap, the stale drop and the
// dedup live HERE and not inside a useEffect.

const notice = (text: string, at: number, tone: Notice['tone'] = 'info'): Notice => ({
  id: `${tone}:${text}`,
  tone,
  text,
  at,
});

const empty: VoiceState = { queue: [], speaking: null, spoken: [] };

describe('voice queue', () => {
  it('speaks one line at a time, in arrival order', () => {
    let s = enqueue(empty, notice('un', 0), 0);
    s = enqueue(s, notice('deux', 1), 1);

    s = next(s, 2);
    expect(s.speaking?.text).toBe('un');
    expect(s.queue).toHaveLength(1);

    // still speaking → next() is a no-op, never an overlap
    const held = next(s, 3);
    expect(held.speaking?.text).toBe('un');
    expect(held.queue).toHaveLength(1);

    s = next(finish(s), 4);
    expect(s.speaking?.text).toBe('deux');
  });

  it('keeps the newest when a burst overruns the cap', () => {
    // A pilot firing four intents in 300ms must not produce a backlog narrated a minute late.
    let s = empty;
    for (const [i, t] of ['un', 'deux', 'trois', 'quatre'].entries()) s = enqueue(s, notice(t, i), i);

    expect(s.queue).toHaveLength(VOICE_CAP);
    expect(s.queue.map((u) => u.text)).toEqual(['trois', 'quatre']);
  });

  it('drops a line that went stale before it could be spoken', () => {
    let s = enqueue(empty, notice('vieux', 0), 0);
    s = enqueue(s, notice('frais', VOICE_STALE_MS + 1), VOICE_STALE_MS + 1);

    s = next(s, VOICE_STALE_MS + 2);

    expect(s.speaking?.text).toBe('frais');
    expect(s.queue).toHaveLength(0);
  });

  it('never speaks the same finding twice', () => {
    // Same dedup key as the notice stack: a repeating pilot is one card AND one utterance.
    let s = enqueue(empty, notice('vidange', 0), 0);
    s = next(s, 1);
    s = finish(s);
    s = enqueue(s, notice('vidange', 2), 2);

    expect(s.queue).toHaveLength(0);
  });

  it('does not re-enqueue the line it is speaking right now', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1);
    s = enqueue(s, notice('vidange', 2), 2);

    expect(s.queue).toHaveLength(0);
    expect(s.speaking?.text).toBe('vidange');
  });

  it('cancels the utterance whose card was dismissed', () => {
    // "vu" removes the card. Audio outliving its card would be audio-only, which the binding rule forbids.
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1);
    expect(s.speaking).not.toBeNull();

    s = cancel(s, 'info:vidange');
    expect(s.speaking).toBeNull();
  });

  it('drops a dismissed line that had not started yet', () => {
    let s = enqueue(empty, notice('un', 0), 0);
    s = enqueue(s, notice('deux', 1), 1);

    s = cancel(s, 'info:un');

    expect(s.queue.map((u) => u.text)).toEqual(['deux']);
  });

  it('leaves an unrelated utterance alone when a different card is dismissed', () => {
    let s = next(enqueue(empty, notice('un', 0), 0), 1);
    s = cancel(s, 'info:autre');

    expect(s.speaking?.text).toBe('un');
  });
});
