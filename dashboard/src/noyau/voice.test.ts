import { describe, expect, it } from 'vitest';
import {
  NARRATION_ID,
  VOICE_CAP,
  VOICE_STALE_MS,
  cancel,
  cardLine,
  emptyVoice,
  heard,
  enqueue,
  finish,
  follow,
  narrate,
  next,
  rearm,
  refused,
  silence,
  supersedeQueued,
  utteranceKey,
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

const empty = emptyVoice;

describe('voice queue', () => {
  it('speaks one line at a time, in arrival order', () => {
    let s = enqueue(empty, notice('un', 0), 0);
    s = enqueue(s, notice('deux', 1), 1);

    s = next(s, 2, true);
    expect(heard(s)?.text).toBe('un');
    expect(s.queue).toHaveLength(1);

    // still audible and NOT yet fully placed on the clock → next() holds. `canChain` is what lifts this
    // (the gapless seam below); without it a second line would overlap the first.
    const held = next(s, 3, true);
    expect(heard(held)?.text).toBe('un');
    expect(held.queue).toHaveLength(1);

    s = next(finish(s), 4, true);
    expect(heard(s)?.text).toBe('deux');
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

    expect(heard(s)?.text).toBe('frais');
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
    expect(heard(s)?.text).toBe('vidange');
  });

  it('cancels the utterance whose card was dismissed', () => {
    // "vu" removes the card. Audio outliving its card would be audio-only, which is forbidden.
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, true);
    expect(heard(s)).not.toBeNull();

    s = cancel(s, 'info:vidange');
    expect(heard(s)).toBeNull();
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

    expect(heard(s)?.text).toBe('un');
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

    expect(heard(s)).toBeNull();
    expect(s.queue).toHaveLength(1);
    expect(s.spoken).toEqual([]);
  });

  it('speaks that same line once the gesture arrives', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, false);
    s = next(s, 2, true);

    expect(heard(s)?.text).toBe('vidange');
    expect(s.queue).toHaveLength(0);
  });

  it('still drops a line that went stale while waiting for the gesture', () => {
    // Waiting on a gesture does not make old news new — the staleness rule is time, not permission.
    let s = enqueue(empty, notice('vieux', 0), 0);
    s = next(s, VOICE_STALE_MS + 2, false);

    expect(heard(s)).toBeNull();
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

    expect(heard(s)?.text).toBe('je relis l’historique');
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

    expect(heard(s)?.text).toBe('je relis l’historique');
    expect(s.narration?.text).toBe('je compare les marques');

    s = next(finish(s), 3, true);
    expect(heard(s)?.text).toBe('je compare les marques');
  });

  it('drops a ticker line that went stale waiting its turn', () => {
    let s = next(narrate(empty, 'je relis l’historique', 0), 1, true);
    s = narrate(s, 'je compare les marques', 2);
    s = next(finish(s), VOICE_STALE_MS + 3, true);

    expect(heard(s)).toBeNull();
    expect(s.narration).toBeNull();
  });

  it('speaks a card before the ticker — the card is the answer, the ticker is a heartbeat', () => {
    let s = narrate(empty, 'je relis l’historique', 0);
    s = enqueue(s, notice('vidange enregistrée', 1), 1);

    s = next(s, 2, true);
    expect(heard(s)?.text).toBe('vidange enregistrée');

    s = next(finish(s), 3, true);
    expect(heard(s)?.text).toBe('je relis l’historique');
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
    expect(heard(s)).toBeNull();
  });

  it('ignores an empty ticker line', () => {
    expect(narrate(empty, '   ', 0)).toBe(empty);
  });

  it('is cancelled with the run — mute empties the slot like any other pending line', () => {
    const s = narrate(empty, 'je relis l’historique', 0);

    expect(cancel(s, NARRATION_ID).narration).toBeNull();
  });

  // Unlike a card, a ticker utterance never joins `spoken` (2026-09-05): it is dedup'd only while
  // speaking or pending, never once it is off the lips — a line the ticker shows again is news again.
  it('speaks a ticker line again once it has finished, even though the exact text already played', () => {
    let s = next(narrate(empty, 'un', 0), 1, true);
    s = finish(s);
    s = narrate(s, 'deux', 2);
    s = next(s, 3, true);
    s = finish(s);

    s = narrate(s, 'un', 4);
    expect(s.narration?.text).toBe('un');

    s = next(s, 5, true);
    expect(heard(s)?.text).toBe('un');
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
    expect(heard(s)?.text).toBe('un');

    s = follow(s, [], 2);
    expect(heard(s)).toBeNull();
  });

  it('leaves a ticker line alone — it has no card, so the sweep must not sweep it', () => {
    let s = next(narrate(empty, 'je relis l’historique', 0), 1, true);

    s = follow(s, [notice('vidange enregistrée', 2)], 2);

    expect(heard(s)?.text).toBe('je relis l’historique'); // not cut mid-word by the arriving card
    expect(s.queue.map((u) => u.text)).toEqual(['vidange enregistrée']);
  });

  // `spoken` used to live for the whole tab, so a chip's second click was silent forever (2026-09-05).
  // The rule now: a text counts as said only while its card is still on screen.
  it('forgets a spoken text once its card is gone, so an identical text under a NEW id is queued again', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, true);
    s = finish(s);
    expect(s.spoken).toEqual(['vidange']);

    s = follow(s, [], 2); // the card is no longer printed

    s = enqueue(s, { id: 'other:vidange', tone: 'warn', text: 'vidange', at: 3 }, 3);
    expect(s.queue.map((u) => u.text)).toEqual(['vidange']);
  });

  it('still refuses a spoken text while its card is STILL on screen', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, true);
    s = finish(s);

    s = follow(s, [notice('vidange', 0)], 2); // same card, still printed

    s = enqueue(s, { id: 'other:vidange', tone: 'warn', text: 'vidange', at: 3 }, 3);
    expect(s.queue).toHaveLength(0);
  });
});

// The proposeActions card is often THE answer — a measured turn ("what tires should I get for front
// wheels", 2026-08-17) published show_fact + propose_actions and NO toast at all, so the audition had
// nothing to read and the operator heard only the turn's done label.
describe('the answer card', () => {
  // The headline routinely restates the finding in fewer words ("Aucun entretien dans le carnet" atop
  // "Aucun entretien n'a encore été enregistré…", turn 21:58:34) — reading both said the answer twice.
  it('reads the finding alone, not the headline in front of it', () => {
    expect(cardLine('Front tires for Wheel Horse 246-H', 'Reference from your history: CA 23x1050x12 VD')).toBe(
      'Reference from your history: CA 23x1050x12 VD',
    );
  });

  it('trims the finding it reads', () => {
    expect(cardLine('Which tyres?', '  The history says CA 23x1050x12 VD  ')).toBe('The history says CA 23x1050x12 VD');
  });

  it('falls back to the headline when the card carries no finding', () => {
    expect(cardLine('Front tires for Wheel Horse 246-H', null)).toBe('Front tires for Wheel Horse 246-H');
    expect(cardLine('Front tires for Wheel Horse 246-H', undefined)).toBe('Front tires for Wheel Horse 246-H');
    expect(cardLine('Front tires for Wheel Horse 246-H', '   ')).toBe('Front tires for Wheel Horse 246-H');
  });

  it('speaks the card before a ticker line, and only once', () => {
    const card = notice(cardLine('Front tires', 'CA 23x1050x12 VD'), 0);
    let s = narrate(empty, 'je compare les marques', 0);
    s = enqueue(s, card, 0);

    s = next(s, 1, true);
    expect(heard(s)?.text).toBe('CA 23x1050x12 VD');

    s = enqueue(finish(s), card, 2);
    expect(s.queue).toHaveLength(0);
  });
});

// The prefetch targets: every line
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

// A browser that refuses to play is refusing THE PAGE, not the sentence: the same line plays fine one
// gesture later. The signal is the AudioContext staying suspended (speaker.ts, 2026-09-05) — before
// that it was a media error whose code Firefox and Chrome disagreed on (measured 2026-08-17).
describe('a refused play', () => {
  it('puts the line back and stops promoting until a gesture re-arms it', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, true);
    s = refused(s);

    expect(heard(s)).toBeNull();
    expect(s.queue.map((u) => u.text)).toEqual(['vidange']);
    expect(s.spoken).toEqual([]); // NOT burned — the operator can still grant the permission
    expect(s.blocked).toBe(true);

    expect(heard(next(s, 2, true))).toBeNull(); // latched: no retry loop against a policy

    s = next(rearm(s), 3, true);
    expect(heard(s)?.text).toBe('vidange');
  });

  it('puts a refused ticker line back in its own slot, not in the card queue', () => {
    let s = next(narrate(empty, 'je relis l’historique', 0), 1, true);
    s = refused(s);

    expect(s.narration?.text).toBe('je relis l’historique');
    expect(s.queue).toHaveLength(0);
  });

  it('still lets a latched line go stale — a permission granted a minute later is too late', () => {
    let s = next(enqueue(empty, notice('vidange', 0), 0), 1, true);
    s = rearm(refused(s));

    expect(heard(next(s, VOICE_STALE_MS + 2, true))).toBeNull();
  });
});

// A `proposeActions` card is often ITSELF the answer a toast already tried to give — the operator
// heard the toast, then heard the card say the same thing 5s later (turn 21:58:34). The card arriving
// is the deterministic guard: whatever notice lines are still waiting behind it are dropped unspoken.
describe('a card supersedes queued notice lines', () => {
  it('keeps only the card line — the waiting notice lines are dropped', () => {
    let s = enqueue(empty, notice('carnet vide', 0), 0);
    s = enqueue(s, { id: 'review:Aucun entretien', tone: 'info', text: 'Aucun entretien', at: 0 }, 0);

    s = supersedeQueued(s, 'review:Aucun entretien');

    expect(s.queue.map((u) => u.id)).toEqual(['review:Aucun entretien']);
  });

  it('leaves the utterance already on the lips untouched', () => {
    let s = next(enqueue(empty, notice('carnet vide', 0), 0), 1, true);
    s = enqueue(s, { id: 'review:Aucun entretien', tone: 'info', text: 'Aucun entretien', at: 1 }, 1);

    s = supersedeQueued(s, 'review:Aucun entretien');

    expect(heard(s)?.text).toBe('carnet vide'); // finishes — never cut mid-word for a supersession
    expect(s.queue.map((u) => u.id)).toEqual(['review:Aucun entretien']);
  });

  it('leaves the ticker slot untouched', () => {
    let s = narrate(empty, 'je relis l’historique', 0);
    s = enqueue(s, notice('carnet vide', 0), 0);

    s = supersedeQueued(s, 'review:Aucun entretien');

    expect(s.narration?.text).toBe('je relis l’historique');
    expect(s.queue).toHaveLength(0);
  });

  it('is a no-op returning the same state reference when nothing is queued', () => {
    const s = narrate(empty, 'je relis l’historique', 0);

    expect(supersedeQueued(s, 'review:Aucun entretien')).toBe(s);
  });
});

/**
 * The greeting is a ONE-SHOT ticker line, and the distinction matters because the ordinary ticker rule
 * is the opposite: a heartbeat step shown again later is news again, so it is never remembered as
 * said. A welcome is not news the second time — the operator reported hearing it repeat on every click
 * of the composer (2026-09-11) — so a line narrated with `once` is remembered when it is promoted and
 * can never be queued again. WHERE it is remembered is the next describe block's business.
 */
describe('narrate — a one-shot line is said once per session', () => {
  it('remembers a one-shot ticker line, and keeps it out of the card list', () => {
    const spoken = next(narrate(empty, 'bonjour Soyuka', 0, true), 1, true);

    expect(heard(spoken)?.text).toBe('bonjour Soyuka');
    expect(spoken.saidOnce).toContain('bonjour Soyuka');
    expect(spoken.spoken).not.toContain('bonjour Soyuka');
  });

  it('refuses to re-queue it afterwards', () => {
    let s = next(narrate(empty, 'bonjour Soyuka', 0, true), 1, true);
    s = finish(s);
    s = narrate(s, 'bonjour Soyuka', 2, true);

    expect(s.narration).toBeNull();
    expect(heard(s)).toBeNull();
  });

  // The ordinary ticker keeps its documented behaviour: shown again, it is news again.
  it('still forgets an ordinary ticker line', () => {
    let s = next(narrate(empty, 'je relis l’historique', 0), 1, true);
    s = finish(s);

    expect(s.spoken).not.toContain('je relis l’historique');
    expect(narrate(s, 'je relis l’historique', 2).narration?.text).toBe('je relis l’historique');
  });
});

/**
 * The one-shot memory has to OUTLIVE the two things that clear the ordinary one, because both of them
 * happen constantly and the operator heard the welcome again after each (2026-09-11, second report):
 *
 * - `follow()` prunes `spoken` to the texts still printed as cards, and a ticker line has no card —
 *   so any arriving toast used to forget the greeting;
 * - the hook wipes the whole state when the audition goes off, and it goes off on every mic press
 *   (`Atelier.tsx`: `audition && !listening`) — so pressing the mic used to forget it too.
 *
 * Hence a separate `saidOnce`, which nothing prunes: once a sentence has been spoken it is never
 * spoken a second time in that session.
 */
describe('a one-shot line is never spoken twice in a session', () => {
  const greeted = (): VoiceState => finish(next(narrate(emptyVoice, 'bonjour Soyuka', 0, true), 1, true));

  it('remembers it in saidOnce rather than in the prunable spoken list', () => {
    const s = greeted();

    expect(s.saidOnce).toContain('bonjour Soyuka');
  });

  it('still refuses it after a card arrives and prunes the spoken list', () => {
    let s = follow(greeted(), [notice('vidange enregistrée', 2)], 2);

    s = narrate(s, 'bonjour Soyuka', 3, true);

    expect(s.narration).toBeNull();
  });

  it('still refuses it after the audition was muted and turned back on', () => {
    let s = silence(greeted());

    s = narrate(s, 'bonjour Soyuka', 3, true);

    expect(s.narration).toBeNull();
  });

  /**
   * The mute must not un-say a CARD either. `follow()` re-enqueues every card still printed, and it is
   * `spoken` that stops those being read a second time — so a mute that cleared `spoken` meant the
   * whole visible notice stack was spoken again the moment the audition came back. The audition comes
   * back on every mic release, which is why the operator heard "everything twice" (2026-09-11).
   */
  it('does not re-speak the cards still on screen when the audition comes back', () => {
    const card = notice('vidange enregistrée', 0);
    let s = finish(next(enqueue(emptyVoice, card, 0), 1, true));
    expect(s.spoken).toContain('vidange enregistrée');

    // the mic opens (audition off), then closes with the same card still printed
    s = follow(silence(s), [card], 2);

    expect(s.queue).toHaveLength(0);
    expect(heard(s)).toBeNull();
  });

  // Muting is not a pause: the lines waiting behind the mute are stale by the time it lifts, and the
  // one on the lips was cut off. Only what was already SAID crosses.
  it('drops everything else on a mute', () => {
    let s = enqueue(greeted(), notice('vidange enregistrée', 2), 2);
    s = narrate(s, 'je relis l’historique', 2);

    const muted = silence(s);

    expect(muted).toMatchObject({ queue: [], narration: null, onAir: [], blocked: false });
    expect(muted.saidOnce).toContain('bonjour Soyuka');
  });

  // An autoplay refusal is not a hearing: the line goes back and must stay sayable, or the very first
  // greeting of a session is lost for good (the same argument `refused` already makes for `spoken`).
  it('forgets it again when the page refused to play it', () => {
    const s = refused(next(narrate(emptyVoice, 'bonjour Soyuka', 0, true), 1, true));

    expect(s.saidOnce).not.toContain('bonjour Soyuka');
    expect(narrate(rearm(s), 'bonjour Soyuka', 2, true).narration?.text).toBe('bonjour Soyuka');
  });
});

/**
 * The gapless seam. The next line
 * is promoted while the current one is still AUDIBLE — as soon as its body is fully placed on the audio
 * clock, which happens ~0.3 s into a 3.2 s line because the body arrives ~12× faster than realtime. The
 * impure edge passes that as `canChain`; everything the pure layer has to decide is here.
 *
 * `onAir` is the pipeline: `[0]` is what the operator hears, `[1]` is committed flush behind it.
 */
describe('the gapless seam', () => {
  const two = (): VoiceState => enqueue(enqueue(emptyVoice, notice('un', 0), 0), notice('deux', 0), 0);

  it('promotes the next line while the first is still audible, once it is fully placed', () => {
    const s = next(next(two(), 1, true), 2, true, true);

    expect(s.onAir.map((u) => u.text)).toEqual(['un', 'deux']);
    expect(heard(s)?.text).toBe('un'); // what is HEARD is still the first
    expect(s.queue).toHaveLength(0);
  });

  it('holds the second line while the first is not placed yet', () => {
    const playing = next(two(), 1, true);

    expect(next(playing, 2, true, false)).toBe(playing);
  });

  // One line of lookahead is all a seam needs. Committing more would tie up lines the burst rules
  // (cap, stale drop) exist to be able to drop.
  it('never places more than two lines on the clock', () => {
    let s = enqueue(two(), notice('trois', 0), 0);
    s = next(next(s, 1, true), 2, true, true);

    expect(next(s, 3, true, true)).toBe(s);
    expect(s.onAir).toHaveLength(2);
  });

  it('hands the head over when the first line ends, leaving the second audible', () => {
    const s = finish(next(next(two(), 1, true), 2, true, true));

    expect(s.onAir.map((u) => u.text)).toEqual(['deux']);
    expect(heard(s)?.text).toBe('deux');
  });

  /**
   * A line already flush on the clock PLAYS. Supersession, staleness and the cap apply to the queue —
   * to what is not yet committed — because un-scheduling a line that is already joined to the current
   * one would be a cut, and cuts are precisely what this seam removes.
   */
  it('keeps a committed line even when a card supersedes the queue behind it', () => {
    let s = next(next(two(), 1, true), 2, true, true);
    s = enqueue(s, notice('trois', 2), 2);

    s = supersedeQueued(s, 'info:trois');

    expect(s.onAir.map((u) => u.text)).toEqual(['un', 'deux']);
  });

  it('keeps a committed line even once it would have gone stale', () => {
    const s = next(next(two(), 1, true), 2, true, true);

    expect(next(s, VOICE_STALE_MS + 10, true, true).onAir.map((u) => u.text)).toEqual(['un', 'deux']);
  });

  // Committed means "will not be silently dropped", never "cannot be cut by the operator": a scheduled
  // source can still be stopped before it starts.
  it('drops a committed line when its own card is dismissed', () => {
    const s = cancel(next(next(two(), 1, true), 2, true, true), 'info:deux');

    expect(s.onAir.map((u) => u.text)).toEqual(['un']);
  });

  it('clears the whole pipeline on a mute', () => {
    expect(silence(next(next(two(), 1, true), 2, true, true)).onAir).toEqual([]);
  });

  /** A refusal is the PAGE saying no, so nothing behind the refused line can play either. */
  it('puts the refused line back and unschedules what was behind it', () => {
    const s = refused(next(next(two(), 1, true), 2, true, true));

    expect(s.onAir).toEqual([]);
    expect(s.blocked).toBe(true);
    expect(s.queue.map((u) => u.text)).toContain('un');
  });
});

/**
 * Two ticker lines can be on the clock together since the seam, and they share the id `narration` —
 * so `id` cannot be what the impure edge files a line under. `useVoice.ts` keys the fetch it started,
 * the audio source it owns and the "fully placed" signal by the KEY; keying them by `id` silently
 * dropped the second ticker line, left it at `onAir[0]` forever and jammed the pipeline at the cap for
 * the rest of the session (2026-09-18: a whole answer never read aloud).
 *
 * The hook itself has no jsdom to be tested in, so the invariant it leans on is pinned here.
 */
describe('a line’s key on the clock', () => {
  it('tells two consecutive ticker lines apart', () => {
    let s = narrate(empty, 'je relis le carnet', 0);
    s = next(s, 1, true);
    s = narrate(s, 'je compare les marques', 1);
    s = next(s, 2, true, true);

    expect(s.onAir.map((u) => u.id)).toEqual([NARRATION_ID, NARRATION_ID]);
    expect(new Set(s.onAir.map(utteranceKey)).size).toBe(2);
  });

  it('gives every line on the clock its own key, ticker or card', () => {
    let s = narrate(enqueue(empty, notice('la réponse', 0), 0), 'je relis le carnet', 0);
    s = next(next(s, 1, true), 2, true, true);

    expect(new Set(s.onAir.map(utteranceKey)).size).toBe(s.onAir.length);
  });

  // The key is the line's IDENTITY, the id is what a dismissal cancels. The same sentence re-arriving
  // under the same card is the same line — a prefetch already in flight for it must be reused, never
  // re-issued.
  it('is stable for the same line', () => {
    expect(utteranceKey({ id: 'info:un', text: 'un', at: 0 })).toBe(utteranceKey({ id: 'info:un', text: 'un', at: 9 }));
  });
});
