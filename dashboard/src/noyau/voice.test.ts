import { describe, expect, it } from 'vitest';
import {
  NARRATION_ID,
  VOICE_CAP,
  VOICE_STALE_MS,
  cancel,
  cardLine,
  enqueue,
  finish,
  follow,
  mediaOutcome,
  narrate,
  next,
  rearm,
  refused,
  waiting,
  type VoiceState,
} from './voice';
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

const empty: VoiceState = { queue: [], narration: null, speaking: null, spoken: [], blocked: false };

describe('voice queue', () => {
  it('speaks one line at a time, in arrival order', () => {
    let s = enqueue(empty, notice('un', 0), 0);
    s = enqueue(s, notice('deux', 1), 1);

    s = next(s, 2, true);
    expect(s.speaking?.text).toBe('un');
    expect(s.queue).toHaveLength(1);

    // still speaking → next() is a no-op, never an overlap
    const held = next(s, 3, true);
    expect(held.speaking?.text).toBe('un');
    expect(held.queue).toHaveLength(1);

    s = next(finish(s), 4, true);
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

    s = next(s, VOICE_STALE_MS + 2, true);

    expect(s.speaking?.text).toBe('frais');
    expect(s.queue).toHaveLength(0);
  });

  it('never speaks the same finding twice', () => {
    // Same dedup key as the notice stack: a repeating pilot is one card AND one utterance.
    let s = enqueue(empty, notice('vidange', 0), 0);
    s = next(s, 1, true);
    s = finish(s);
    s = enqueue(s, notice('vidange', 2), 2);

    expect(s.queue).toHaveLength(0);
  });

  it('does not re-enqueue the line it is speaking right now', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, true);
    s = enqueue(s, notice('vidange', 2), 2);

    expect(s.queue).toHaveLength(0);
    expect(s.speaking?.text).toBe('vidange');
  });

  it('cancels the utterance whose card was dismissed', () => {
    // "vu" removes the card. Audio outliving its card would be audio-only, which the binding rule forbids.
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, true);
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
    let s = next(enqueue(empty, notice('un', 0), 0), 1, true);
    s = cancel(s, 'info:autre');

    expect(s.speaking?.text).toBe('un');
  });
});

// Autoplay: a browser will not play audio until the page has had a real interaction, and a PILOTED
// session produces none in this tab. The gate used to live at the impure edge, AFTER next() had
// already moved the line into `spoken` — so the first answer of a session was marked spoken and
// silently thrown away, and unlocking later never brought it back. Holding it in the queue is the
// fix: the line is not consumed until it can actually be heard.
describe('the autoplay gate', () => {
  it('does not promote — or consume — a line while autoplay is locked', () => {
    const s = next(enqueue(empty, notice('vidange', 0), 0), 1, false);

    expect(s.speaking).toBeNull();
    expect(s.queue).toHaveLength(1);
    expect(s.spoken).toEqual([]);
  });

  it('speaks that same line once the gesture arrives', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, false);
    s = next(s, 2, true);

    expect(s.speaking?.text).toBe('vidange');
    expect(s.queue).toHaveLength(0);
  });

  it('still drops a line that went stale while waiting for the gesture', () => {
    // Waiting on a gesture does not make old news new — the staleness rule is time, not permission.
    let s = enqueue(empty, notice('vieux', 0), 0);
    s = next(s, VOICE_STALE_MS + 2, false);

    expect(s.speaking).toBeNull();
    expect(s.queue).toHaveLength(0);
  });

  it('is idempotent while locked — repeated ticks neither drop nor duplicate the line', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, false);
    s = next(s, 2, false);
    s = next(s, 3, false);

    expect(s.queue).toHaveLength(1);
    expect(s.spoken).toEqual([]);
  });
});

// The ticker line (NoyauState.narration) is the orb's commentary — "je relis l'historique". It never
// stacks on screen, so it never stacks in the ear: ONE slot, latest wins.
describe('the ticker line', () => {
  it('speaks the line the orb is showing', () => {
    const s = next(narrate(empty, 'je relis l’historique', 0), 1, true);

    expect(s.speaking?.text).toBe('je relis l’historique');
    expect(s.narration).toBeNull();
  });

  it('replaces a pending line instead of queueing it', () => {
    // A burst of steps 300ms apart must not become a backlog of sentences read after the run ended.
    let s = narrate(empty, 'je relis l’historique', 0);
    s = narrate(s, 'je vérifie les intervalles', 1);
    s = narrate(s, 'je compare les marques', 2);

    expect(s.narration?.text).toBe('je compare les marques');
    expect(s.queue).toHaveLength(0);
  });

  it('lets the line being spoken finish, and holds the newest for after it', () => {
    let s = next(narrate(empty, 'je relis l’historique', 0), 1, true);
    s = narrate(s, 'je compare les marques', 2);

    expect(s.speaking?.text).toBe('je relis l’historique');
    expect(s.narration?.text).toBe('je compare les marques');

    s = next(finish(s), 3, true);
    expect(s.speaking?.text).toBe('je compare les marques');
  });

  it('drops a ticker line that went stale waiting its turn', () => {
    let s = next(narrate(empty, 'je relis l’historique', 0), 1, true);
    s = narrate(s, 'je compare les marques', 2);
    s = next(finish(s), VOICE_STALE_MS + 3, true);

    expect(s.speaking).toBeNull();
    expect(s.narration).toBeNull();
  });

  it('speaks a card before the ticker — the card is the answer, the ticker is a heartbeat', () => {
    let s = narrate(empty, 'je relis l’historique', 0);
    s = enqueue(s, notice('vidange enregistrée', 1), 1);

    s = next(s, 2, true);
    expect(s.speaking?.text).toBe('vidange enregistrée');

    s = next(finish(s), 3, true);
    expect(s.speaking?.text).toBe('je relis l’historique');
  });

  it('says a sentence once even though a toast writes both a card and the ticker', () => {
    // state.ts:351-357 — every non-alarm toast sets the notice AND the narration to the same string.
    let s = enqueue(empty, notice('vidange enregistrée', 0), 0);
    s = narrate(s, 'vidange enregistrée', 0);

    expect(s.narration).toBeNull();

    s = next(s, 1, true);
    s = finish(s);
    s = narrate(s, 'vidange enregistrée', 2);

    expect(s.narration).toBeNull();
    expect(s.speaking).toBeNull();
  });

  it('ignores an empty ticker line', () => {
    expect(narrate(empty, '   ', 0)).toBe(empty);
  });

  it('is cancelled with the run — mute empties the slot like any other pending line', () => {
    const s = narrate(empty, 'je relis l’historique', 0);

    expect(cancel(s, NARRATION_ID).narration).toBeNull();
  });
});

// Following the printed stack is a RULE, not plumbing: it is what makes a dismissal a cancellation, and
// it is where a ticker line would be killed by a card arriving if the sweep were naive about ids.
describe('following the printed cards', () => {
  it('queues every card on screen', () => {
    const s = follow(empty, [notice('un', 0), notice('deux', 0)], 0);

    expect(s.queue.map((u) => u.text)).toEqual(['un', 'deux']);
  });

  it('cancels the line of a card that vanished', () => {
    let s = next(follow(empty, [notice('un', 0)], 0), 1, true);
    expect(s.speaking?.text).toBe('un');

    s = follow(s, [], 2);
    expect(s.speaking).toBeNull();
  });

  it('leaves a ticker line alone — it has no card, so the sweep must not sweep it', () => {
    let s = next(narrate(empty, 'je relis l’historique', 0), 1, true);

    s = follow(s, [notice('vidange enregistrée', 2)], 2);

    expect(s.speaking?.text).toBe('je relis l’historique'); // not cut mid-word by the arriving card
    expect(s.queue.map((u) => u.text)).toEqual(['vidange enregistrée']);
  });
});

// The proposeActions card is often THE answer — a measured turn ("what tires should I get for front
// wheels", 2026-08-17) published show_fact + propose_actions and NO toast at all, so the audition had
// nothing to read and the operator heard only the turn's done label.
describe('the answer card', () => {
  it('reads the headline then the finding as one line', () => {
    expect(cardLine('Front tires for Wheel Horse 246-H', 'Reference from your history: CA 23x1050x12 VD')).toBe(
      'Front tires for Wheel Horse 246-H. Reference from your history: CA 23x1050x12 VD',
    );
  });

  it('does not double the punctuation a headline already carries', () => {
    expect(cardLine('Which tyres?', 'The history says CA 23x1050x12 VD')).toBe(
      'Which tyres? The history says CA 23x1050x12 VD',
    );
  });

  it('is just the headline when the card carries no finding', () => {
    expect(cardLine('Front tires for Wheel Horse 246-H', null)).toBe('Front tires for Wheel Horse 246-H');
  });

  it('speaks the card before a ticker line, and only once', () => {
    const card = notice(cardLine('Front tires', 'CA 23x1050x12 VD'), 0);
    let s = narrate(empty, 'je compare les marques', 0);
    s = enqueue(s, card, 0);

    s = next(s, 1, true);
    expect(s.speaking?.text).toBe('Front tires. CA 23x1050x12 VD');

    s = enqueue(finish(s), card, 2);
    expect(s.queue).toHaveLength(0);
  });
});

// The prefetch targets (CHOREOGRAPHY § "The voice starts before the browser asks"): every line
// waiting behind the one being spoken, read without promoting any of them, so `useVoice.ts` can fetch
// each one's audio in parallel instead of waiting for it to reach the lips.
describe('waiting — the prefetch targets', () => {
  it('returns nothing while nothing is speaking — next() promotes immediately, nothing to get ahead of', () => {
    const s = enqueue(empty, notice('un', 0), 0);
    expect(waiting(s, 1)).toEqual([]);
  });

  it('returns the line waiting behind the one being spoken', () => {
    let s = next(enqueue(empty, notice('un', 0), 0), 1, true);
    s = enqueue(s, notice('deux', 2), 2);

    expect(waiting(s, 3).map((u) => u.text)).toEqual(['deux']);
  });

  it('returns BOTH capped queue slots at once — one synthesis per waiting line, not one at a time', () => {
    let s = next(enqueue(empty, notice('un', 0), 0), 1, true);
    s = enqueue(s, notice('deux', 2), 2);
    s = enqueue(s, notice('trois', 3), 3);

    expect(waiting(s, 4).map((u) => u.text)).toEqual(['deux', 'trois']);
  });

  it('falls back to the ticker line when the queue is empty', () => {
    let s = next(enqueue(empty, notice('un', 0), 0), 1, true);
    s = narrate(s, 'je relis l’historique', 2);

    expect(waiting(s, 3).map((u) => u.text)).toEqual(['je relis l’historique']);
  });

  it('ignores a queued line that has already gone stale', () => {
    let s = next(enqueue(empty, notice('un', 0), 0), 1, true);
    s = enqueue(s, notice('vieux', 2), 2);

    expect(waiting(s, VOICE_STALE_MS + 3)).toEqual([]);
  });

  it('does not consume the lines it returns — a peek is not a promotion', () => {
    let s = next(enqueue(empty, notice('un', 0), 0), 1, true);
    s = enqueue(s, notice('deux', 2), 2);

    waiting(s, 3);
    expect(s.queue).toHaveLength(1); // 'deux' still queued, not moved into `spoken`
    expect(s.spoken).toEqual(['un']);
  });
});

// A browser that refuses play() is refusing THE PAGE, not the sentence: the same line plays fine one
// gesture later. Measured 2026-08-17 — Firefox with autoplay off for the origin reports it as an abort
// of the media load, not as NotAllowedError, so the rule cannot depend on which error came back.
describe('a refused play()', () => {
  it('puts the line back and stops promoting until a gesture re-arms it', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, true);
    s = refused(s);

    expect(s.speaking).toBeNull();
    expect(s.queue.map((u) => u.text)).toEqual(['vidange']);
    expect(s.spoken).toEqual([]); // NOT burned — the operator can still grant the permission
    expect(s.blocked).toBe(true);

    expect(next(s, 2, true).speaking).toBeNull(); // latched: no retry loop against a policy

    s = next(rearm(s), 3, true);
    expect(s.speaking?.text).toBe('vidange');
  });

  it('puts a refused ticker line back in its own slot, not in the card queue', () => {
    let s = next(narrate(empty, 'je relis l’historique', 0), 1, true);
    s = refused(s);

    expect(s.narration?.text).toBe('je relis l’historique');
    expect(s.queue).toHaveLength(0);
  });

  // The refusal does not always arrive as a rejected play(): Firefox fires it at the ELEMENT as an
  // aborted media load. Routing every media error to finish() burned the line AND lost the latch, so
  // `blocked` never turned true and the whole queue drained silently — the Firefox symptom.
  it('reads an aborted media load as a refusal, not as a spent line', () => {
    expect(mediaOutcome(1)).toBe('refused');
  });

  it('reads a network/decode/unsupported error as a spent line — one bad body must not latch the queue closed', () => {
    expect(mediaOutcome(2)).toBe('failed');
    expect(mediaOutcome(3)).toBe('failed');
    expect(mediaOutcome(4)).toBe('failed');
  });

  it('reads a missing code as a spent line — never latch the audition closed on a guess', () => {
    expect(mediaOutcome(null)).toBe('failed');
    expect(mediaOutcome(undefined)).toBe('failed');
  });

  it('still lets a latched line go stale — a permission granted a minute later is too late', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, true);
    s = rearm(refused(s));

    expect(next(s, VOICE_STALE_MS + 2, true).speaking).toBeNull();
  });
});
