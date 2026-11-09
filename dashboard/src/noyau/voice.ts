import type { Notice } from './state';

// The ear's half of the notice stack. Pure and
// clock-injected: every rule that matters — order, cap, staleness, dedup, cancellation — is decided
// here, so the impure edge in useVoice.ts is left with nothing but fetch and an AudioContext (speaker.ts).
//
// Speech is GARNISH. The notice card is the payload and the card is authoritative; this queue is
// allowed to drop lines the stack still shows, and does. It must never be the only way to learn
// something, and it must never gate anything.

/**
 * One line waiting to be spoken. `id` is the NOTICE's id (or {@see NARRATION_ID} for the ticker slot)
 * and is used for CANCELLATION only — which card's "vu" kills which line. What makes a line already
 * said is its TEXT, because one `toast` frame legitimately lands on two surfaces at once.
 */
export interface Utterance {
  id: string;
  text: string;
  at: number;
  /**
   * Said once per session, then never again. The ordinary ticker line is the opposite by design (see
   * {@see next}: a heartbeat shown again later is news again), which is right for a tool step and
   * wrong for a greeting — the operator heard the welcome repeat on every click of the composer
   * (2026-09-11). A one-shot line joins {@see VoiceState.saidOnce} when promoted, and nothing prunes
   * that list, so {@see narrate} refuses the same text for the rest of the session.
   */
  once?: boolean;
}

/**
 * What the impure edge files a line under: its own identity, which `id` is NOT.
 *
 * Since the seam, two lines sit on the clock at once and both may be ticker lines — the ticker's id is
 * the constant {@see NARRATION_ID}, so a map keyed by `id` collapses them into one. `useVoice.ts` keyed
 * its in-flight fetch, its audio source and its "fully placed" signal that way and therefore never
 * started the second ticker line, never saw it end, and left it at the head of `onAir` for good: the
 * pipeline stayed full at {@see ON_AIR_CAP} and every later answer was queued and never read aloud
 * (2026-09-18).
 *
 * The text is enough to separate them because no two LIVE lines ever share one: `said()` is what
 * {@see enqueue} and {@see narrate} both refuse on, and it compares texts.
 */
export function utteranceKey(utterance: Utterance): string {
  return `${utterance.id}\u0000${utterance.text}`;
}

/**
 * The ticker slot's fixed id. The orb's live line never stacks on screen (`state.ts` "the single ticker
 * line"), so one id is all it can ever need — a second line does not join it, it replaces it.
 */
export const NARRATION_ID = 'narration';

export interface VoiceState {
  /** The notice cards' queue — the answers. Capped, ordered, each line its own card. */
  queue: Utterance[];
  /** The ticker's ONE pending line: the orb's commentary, latest wins. */
  narration: Utterance | null;
  /**
   * The lines PLACED on the audio clock, in the order they will be heard — at most two.
   *
   * `onAir[0]` is what the operator is hearing; `onAir[1]` is committed flush behind it, scheduled
   * while the first was still playing so the two are one continuous signal. The distinction is the whole point of the array: this used to be a
   * single `speaking`, which meant "placed" and "audible" at once, and a seam needs them apart — a
   * notice card's read-dwell may only start when its own line is off the LIPS ({@see heard}), not when
   * the next one is merely scheduled.
   *
   * One line of lookahead and no more: committing further would tie up lines the burst rules (the cap,
   * the stale drop) exist to be able to drop.
   */
  onAir: Utterance[];
  /** Texts already spoken — the same sentence re-arriving is one utterance, whichever surface sent it. */
  spoken: string[];
  /**
   * The texts of ONE-SHOT lines that have been spoken. Deliberately a second list rather than a flag
   * on {@see spoken}: that one is pruned to what is still printed ({@see follow}) and wiped when the
   * audition goes off ({@see silence}), and both happen constantly — an arriving toast prunes it, and
   * every mic press mutes the audition (`Atelier.tsx`: `audition && !listening`). A greeting kept
   * there was therefore forgotten seconds after it was said and the operator heard it again on the
   * next click (2026-09-11). Nothing prunes this list: once a sentence has been spoken, it is never
   * spoken a second time in that session.
   */
  saidOnce: string[];
  /**
   * The browser refused to play. A property of the PAGE, not of the line: nothing is promoted until a
   * real gesture re-arms it ({@see rearm}), because retrying against an autoplay policy that will not
   * change on its own is a loop that burns every line it touches.
   */
  blocked: boolean;
}

/**
 * At most two lines wait. A pilot burst of four intents in 300 ms must not become four overlapping
 * voices NOR a backlog narrated a minute late, and the notice stack's own cap is the precedent.
 * Deliberately tighter than NOTICE_CAP (3): three cards can be readable at a glance while three
 * spoken lines are a queue nobody can skip.
 */
export const VOICE_CAP = 2;

/** A line older than this is dropped unread — by the time it would be spoken it is no longer news. */
export const VOICE_STALE_MS = 10_000;

/** At most two lines are ever placed on the audio clock — one audible, one committed behind it. */
export const ON_AIR_CAP = 2;

export const emptyVoice: VoiceState = {
  queue: [],
  narration: null,
  onAir: [],
  spoken: [],
  saidOnce: [],
  blocked: false,
};

/**
 * The audition just went off — a mute, or the mic opening (the microphone must never transcribe our own
 * voice). Everything PENDING goes: the line on the lips was cut mid-word and the ones behind it will be
 * stale by the time the audition comes back, so resuming them would read old news aloud.
 *
 * What crosses is the MEMORY of what has been said — both lists. A mute is not an un-saying, and the
 * cost of treating it as one is not subtle: `follow()` re-enqueues every card still printed, so an
 * audition that came back with an empty `spoken` read the whole visible notice stack out again. Since
 * the audition goes off on every mic press, the operator heard "everything twice" (2026-09-11, the
 * second report of this shape — the first was the greeting, remembered in the wrong list).
 *
 * `spoken` stays pruned to what is still on screen by `follow()`, so carrying it across a mute does not
 * make it grow: a text is still said only while its card is.
 */
/** What the operator is HEARING — the head of the pipeline. Null when the audition is idle. */
export function heard(state: VoiceState): Utterance | null {
  return state.onAir[0] ?? null;
}

export function silence(state: VoiceState): VoiceState {
  return { ...emptyVoice, spoken: state.spoken, saidOnce: state.saidOnce };
}

/** Already said, already pending, already on the clock — by TEXT, so one sentence is one utterance. */
function said(state: VoiceState, text: string): boolean {
  return (
    state.spoken.includes(text) ||
    state.onAir.some((u) => u.text === text) ||
    state.queue.some((u) => u.text === text)
  );
}

/** Queue a notice. Already spoken, already queued or already on the lips → nothing happens. */
export function enqueue(state: VoiceState, notice: Notice, now: number): VoiceState {
  if (said(state, notice.text)) return state;

  // Overrun keeps the NEWEST: the oldest pending line is the one most likely to be stale anyway,
  // and never hearing the latest answer is the worse failure.
  const queue = [...state.queue, { id: notice.id, text: notice.text, at: now }].slice(-VOICE_CAP);
  // The card wins over the ticker for the same sentence: a toast writes both, and the card is the one
  // that outlives the turn.
  const narration = state.narration?.text === notice.text ? null : state.narration;
  return { ...state, queue, narration };
}

/**
 * Reconcile the queue against the cards currently PRINTED — the notice stack plus the answer card, in
 * that order. This is what makes a dismissal a cancellation: the card list, not an event stream, is the
 * input, so audio can never outlive the visual that defines it.
 *
 * The ticker slot is exempt from the sweep. Its line has no card of its own, so a naive "cancel every
 * utterance whose id is not on screen" cuts the orb's commentary off mid-word the moment any card
 * arrives — which is the one thing the burst rule promises not to do.
 *
 * `spoken` is pruned to the texts of `cards` BEFORE enqueueing (2026-09-05): a text counts as said only
 * while its card is still on screen, never for the life of the tab. Without this a repeatable action
 * (a chip clicked twice, a generic step line) goes silent forever after its first run — `spoken` used
 * to grow unbounded and `said()` consulted it for good.
 */
export function follow(state: VoiceState, cards: Notice[], now: number): VoiceState {
  const live = new Set(cards.map((c) => c.id));
  let out = state;

  for (const u of [...state.onAir, ...state.queue]) {
    if (NARRATION_ID !== u.id && !live.has(u.id)) out = cancel(out, u.id);
  }

  const liveTexts = new Set(cards.map((c) => c.text));
  out = { ...out, spoken: out.spoken.filter((text) => liveTexts.has(text)) };

  for (const card of cards) out = enqueue(out, card, now);

  return out;
}

/**
 * The spoken form of an answer CARD (a `proposeActions` proposal): the finding ALONE when there is one,
 * else the headline. Journal evidence (turn 21:58:34) caught the old headline-then-finding rule reading
 * the same sentence twice — the agent's headline routinely restates the finding in fewer words ("Aucun
 * entretien dans le carnet" atop "Aucun entretien n'a encore été enregistré…"), so speaking both was an
 * answer said twice, not a reading of two distinct lines. Both strings still print on the card — this only changes what the
 * VOICE reads.
 */
export function cardLine(headline: string, finding: string | null | undefined): string {
  const tail = (finding ?? '').trim();
  return '' === tail ? headline.trim() : tail;
}

/**
 * The orb's live line. ONE slot, latest wins: a `status` burst replaces its own pending sentence rather
 * than queueing three of them to be read after the run is over. A line already being spoken is left to
 * finish — a replacement is the next heartbeat, not a dismissal.
 */
export function narrate(state: VoiceState, text: string, now: number, once = false): VoiceState {
  const line = text.trim();
  if ('' === line || said(state, line)) return state;
  if (once && state.saidOnce.includes(line)) return state;

  return { ...state, narration: { id: NARRATION_ID, text: line, at: now, once } };
}

/**
 * Promote the next fresh line onto the clock. Stale heads are discarded, not spoken late.
 *
 * `canChain` is the gapless seam: the impure
 * edge sets it once the audible line's body is **fully placed** on the audio clock, which is ~0.3 s
 * into a 3.2 s line because the body arrives ~12× faster than realtime. Promoting then — while the
 * first line is still being heard — is what lets its successor be scheduled flush against its last
 * sample instead of after a React round-trip and a fresh 190 ms of priming. Without it a second line
 * would simply overlap the first, which is why it defaults to false.
 *
 * `unlocked` is the browser's autoplay permission — a page that has had no real interaction cannot
 * play audio, and a PILOTED session produces no gesture in this tab at all. While it is false the
 * fresh lines stay QUEUED: promoting one would move it into `spoken`, and the impure edge would then
 * find it unplayable and drop it, burning the line permanently. The first answer of a session is
 * exactly the one that hits this, which is the worst one to lose.
 *
 * Staleness still applies while locked: waiting on a gesture does not make old news new.
 */
export function next(state: VoiceState, now: number, unlocked: boolean, canChain = false): VoiceState {
  if (state.blocked) return state;
  // Full, or audible-but-not-yet-placed: nothing may be promoted, and nothing ages either — a line
  // cannot go stale while the one in front of it is still being heard.
  if (state.onAir.length >= ON_AIR_CAP || (state.onAir.length > 0 && !canChain)) return state;

  const stale = (u: Utterance): boolean => now - u.at > VOICE_STALE_MS;
  const fresh = state.queue.filter((u) => !stale(u));
  const ticker = state.narration && !stale(state.narration) ? state.narration : null;
  const aged = fresh.length !== state.queue.length || ticker !== state.narration;

  if (!unlocked) return aged ? { ...state, queue: fresh, narration: ticker } : state;

  // A card first: it IS the answer and it outlives the turn, where the ticker line is a heartbeat that
  // is already gone from the screen by the time it would be read.
  const [head, ...rest] = fresh;
  const promoted = head ?? ticker;
  if (!promoted) return aged ? { ...state, queue: fresh, narration: ticker } : state;

  return {
    ...state,
    onAir: [...state.onAir, promoted],
    queue: head ? rest : fresh,
    narration: head ? ticker : null,
    // Only a CARD utterance joins `spoken` (2026-09-05) — a ticker line has no card to keep it "on
    // screen", so it is never remembered past the clock: shown again later, it is news again.
    spoken: head ? [...state.spoken, promoted.text] : state.spoken,
    // A ONE-SHOT ticker line is remembered for the session instead, in the list nothing prunes.
    saidOnce: !head && promoted.once ? [...state.saidOnce, promoted.text] : state.saidOnce,
  };
}

/**
 * Every line waiting behind the one being spoken, fresh enough to be worth fetching ahead of the
 * handover — read-only, so `useVoice.ts` can start fetching each one's audio in PARALLEL with the
 * line now playing (one synthesis in
 * flight per waiting line, not one at a time). Unlike `next()` this never mutates `spoken`: any of
 * these can still be superseded, cap-evicted or go stale before its turn actually comes, and none of
 * that may be decided here — only the real promotion in `next()` may consume a line.
 *
 * Only meaningful while something is ALREADY on the clock: with nothing playing, `next()` promotes
 * immediately and there is nothing to get ahead of. Ordered head-first (the queue, then the ticker —
 * `next()`'s own promotion order), though a fetch-ahead caller has no reason to care about the order.
 */
export function waiting(state: VoiceState, now: number): Utterance[] {
  if (0 === state.onAir.length) return [];

  const stale = (u: Utterance): boolean => now - u.at > VOICE_STALE_MS;
  const fresh = state.queue.filter((u) => !stale(u));
  const ticker = state.narration && !stale(state.narration) ? state.narration : null;

  return ticker ? [...fresh, ticker] : fresh;
}

/**
 * The page refused to play — the AudioContext would not come back running (speaker.ts). The line goes
 * BACK where it was and the audition latches closed until a gesture re-arms it: autoplay refusal is a
 * property of the page, so the same sentence would play fine one click later, and consuming it here is
 * how a permission the operator can still grant becomes a line they can never hear. A refused ticker
 * line that was meanwhile superseded stays superseded.
 *
 * The refusal is the PAGE's, not the line's, so a line committed BEHIND the refused one cannot play
 * either: the whole pipeline is unscheduled. Only the head goes back into the queue — the one behind it
 * was never consumed from anywhere it could be returned to, and `blocked` holds everything until a
 * gesture re-arms it anyway.
 */
export function refused(state: VoiceState): VoiceState {
  const utterance = heard(state);
  if (!utterance) return state.blocked ? state : { ...state, blocked: true };

  const spoken = state.spoken.filter((text) => text !== utterance.text);
  // A refusal is not a hearing: the FIRST line of a session is the one that meets the autoplay policy,
  // and a one-shot line left in `saidOnce` here could never be said at all.
  const saidOnce = state.saidOnce.filter((text) => text !== utterance.text);
  const back =
    NARRATION_ID === utterance.id
      ? { narration: state.narration ?? utterance }
      : { queue: [utterance, ...state.queue].slice(-VOICE_CAP) };

  return { ...state, ...back, onAir: [], spoken, saidOnce, blocked: true };
}

/** A real gesture arrived — the page may be allowed to play now, so try again. */
export function rearm(state: VoiceState): VoiceState {
  return state.blocked ? { ...state, blocked: false } : state;
}

/**
 * The audible line ended on its own — the head leaves the clock and whatever was committed behind it
 * (already sounding, flush against it) becomes what the operator is hearing.
 */
export function finish(state: VoiceState): VoiceState {
  return 0 === state.onAir.length ? state : { ...state, onAir: state.onAir.slice(1) };
}

/**
 * A `proposeActions` card just arrived (or changed to a different headline) — it IS the answer, so
 * every notice-toast line still WAITING behind it is dropped unspoken; a toast that said the same
 * thing the card now says is not read twice. `keepId` is the card's own queued utterance (if any) and survives the sweep. The line
 * already ON THE LIPS is untouched — never cut mid-word for a supersession — and so is
 * the ticker slot, which has no card behind it to be superseded by.
 */
export function supersedeQueued(state: VoiceState, keepId: string): VoiceState {
  if (0 === state.queue.length) return state;

  const queue = state.queue.filter((u) => u.id === keepId);
  return queue.length === state.queue.length ? state : { ...state, queue };
}

/**
 * The operator dismissed a card ("vu"). Its voice goes with it: audio that outlives its own card is
 * audio-only, which is forbidden.
 */
export function cancel(state: VoiceState, id: string): VoiceState {
  // A line COMMITTED to the clock is still cancellable: a scheduled source can be stopped before it
  // starts. "Committed" means it will not be silently dropped, never that the operator cannot cut it.
  const onAir = state.onAir.filter((u) => u.id !== id);
  const queue = state.queue.filter((u) => u.id !== id);
  const narration = state.narration?.id === id ? null : state.narration;
  return onAir.length === state.onAir.length && queue.length === state.queue.length && narration === state.narration
    ? state
    : { ...state, onAir, queue, narration };
}
