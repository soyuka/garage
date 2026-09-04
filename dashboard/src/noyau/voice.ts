import type { Notice } from './state';

// The ear's half of the notice stack (CHOREOGRAPHY § "Audition — speech, for answers"). Pure and
// clock-injected: every rule that matters — order, cap, staleness, dedup, cancellation — is decided
// here, so the impure edge in useVoice.ts is left with nothing but fetch/<audio>/onended.
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
  speaking: Utterance | null;
  /** Texts already spoken — the same sentence re-arriving is one utterance, whichever surface sent it. */
  spoken: string[];
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

export const emptyVoice: VoiceState = { queue: [], narration: null, speaking: null, spoken: [], blocked: false };

/** Already said, already pending, already on the lips — by TEXT, so one sentence is one utterance. */
function said(state: VoiceState, text: string): boolean {
  return state.spoken.includes(text) || state.speaking?.text === text || state.queue.some((u) => u.text === text);
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
 */
export function follow(state: VoiceState, cards: Notice[], now: number): VoiceState {
  const live = new Set(cards.map((c) => c.id));
  let out = state;

  for (const u of [state.speaking, ...state.queue]) {
    if (u && NARRATION_ID !== u.id && !live.has(u.id)) out = cancel(out, u.id);
  }
  for (const card of cards) out = enqueue(out, card, now);

  return out;
}

/**
 * The spoken form of an answer CARD (a `proposeActions` proposal): its headline, then its finding. The
 * headline names the subject, the finding carries the content — the reference, the number, the verdict —
 * so a headline alone is an answer that answers nothing. Both strings are printed on the card, which is
 * what keeps this a reading and not a rephrase (CHOREOGRAPHY § Binding rules specific to speech).
 */
export function cardLine(headline: string, finding: string | null | undefined): string {
  const head = headline.trim();
  const tail = (finding ?? '').trim();
  if ('' === tail) return head;

  // Its own sentence-ending punctuation is kept; otherwise one is added, because the voice reads the
  // join and "Front tires CA 23x1050x12" is one run-on where the card had two lines.
  return `${head}${/[.!?…]$/.test(head) ? '' : '.'} ${tail}`;
}

/**
 * The orb's live line. ONE slot, latest wins: a `status` burst replaces its own pending sentence rather
 * than queueing three of them to be read after the run is over. A line already being spoken is left to
 * finish — a replacement is the next heartbeat, not a dismissal (CHOREOGRAPHY § Binding rules).
 */
export function narrate(state: VoiceState, text: string, now: number): VoiceState {
  const line = text.trim();
  if ('' === line || said(state, line)) return state;

  return { ...state, narration: { id: NARRATION_ID, text: line, at: now } };
}

/**
 * Promote the next fresh line, if nothing is speaking. Stale heads are discarded, not spoken late.
 *
 * `unlocked` is the browser's autoplay permission — a page that has had no real interaction cannot
 * play audio, and a PILOTED session produces no gesture in this tab at all. While it is false the
 * fresh lines stay QUEUED: promoting one would move it into `spoken`, and the impure edge would then
 * find it unplayable and drop it, burning the line permanently. The first answer of a session is
 * exactly the one that hits this, which is the worst one to lose.
 *
 * Staleness still applies while locked: waiting on a gesture does not make old news new.
 */
export function next(state: VoiceState, now: number, unlocked: boolean): VoiceState {
  if (state.speaking || state.blocked) return state;

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
    speaking: promoted,
    queue: head ? rest : fresh,
    narration: head ? ticker : null,
    spoken: [...state.spoken, promoted.text],
  };
}

/**
 * Every line waiting behind the one being spoken, fresh enough to be worth fetching ahead of the
 * handover — read-only, so `useVoice.ts` can start fetching each one's audio in PARALLEL with the
 * line now playing (CHOREOGRAPHY § "The voice starts before the browser asks": "one synthesis in
 * flight per waiting line, not one at a time"). Unlike `next()` this never mutates `spoken`: any of
 * these can still be superseded, cap-evicted or go stale before its turn actually comes, and none of
 * that may be decided here — only the real promotion in `next()` may consume a line.
 *
 * Only meaningful while something is ALREADY on the lips: if nothing is speaking, `next()` promotes
 * immediately and there is nothing to get ahead of. Ordered head-first (the queue, then the ticker —
 * `next()`'s own promotion order), though a fetch-ahead caller has no reason to care about the order.
 */
export function waiting(state: VoiceState, now: number): Utterance[] {
  if (!state.speaking) return [];

  const stale = (u: Utterance): boolean => now - u.at > VOICE_STALE_MS;
  const fresh = state.queue.filter((u) => !stale(u));
  const ticker = state.narration && !stale(state.narration) ? state.narration : null;

  return ticker ? [...fresh, ticker] : fresh;
}

/**
 * `play()` came back rejected. The line goes BACK where it was and the audition latches closed until a
 * gesture re-arms it: autoplay refusal is a property of the page, so the same sentence would play fine
 * one click later, and consuming it here is how a permission the operator can still grant becomes a
 * line they can never hear. A refused ticker line that was meanwhile superseded stays superseded.
 */
export function refused(state: VoiceState): VoiceState {
  const utterance = state.speaking;
  if (!utterance) return state.blocked ? state : { ...state, blocked: true };

  const spoken = state.spoken.filter((text) => text !== utterance.text);
  const back =
    NARRATION_ID === utterance.id
      ? { narration: state.narration ?? utterance }
      : { queue: [utterance, ...state.queue].slice(-VOICE_CAP) };

  return { ...state, ...back, speaking: null, spoken, blocked: true };
}

/**
 * What a media `error` event MEANS for the line that was playing — the one impure signal the ear has
 * to read, kept here with the rest of the rules rather than in the hook's wiring.
 *
 * Firefox reports an autoplay refusal by firing `error` AT THE ELEMENT with `MEDIA_ERR_ABORTED`
 * ("aborted by the user agent at the user's request"), not by rejecting `play()` the way Chrome does.
 * Routing every media error to {@see finish} therefore burned the line and never latched `blocked`.
 * A decode/network/unsupported error is the BODY's fault, not the page's: it spends the line and lets
 * the queue advance, because one bad body must never silence every line behind it.
 */
export function mediaOutcome(code: number | null | undefined): 'refused' | 'failed' {
  return 1 === code ? 'refused' : 'failed'; // 1 = MediaError.MEDIA_ERR_ABORTED
}

/** A real gesture arrived — the page may be allowed to play now, so try again. */
export function rearm(state: VoiceState): VoiceState {
  return state.blocked ? { ...state, blocked: false } : state;
}

/** The utterance ended on its own. */
export function finish(state: VoiceState): VoiceState {
  return state.speaking === null ? state : { ...state, speaking: null };
}

/**
 * The operator dismissed a card ("vu"). Its voice goes with it: audio that outlives its own card is
 * audio-only, which the audition binding rule forbids outright.
 */
export function cancel(state: VoiceState, id: string): VoiceState {
  const speaking = state.speaking?.id === id ? null : state.speaking;
  const queue = state.queue.filter((u) => u.id !== id);
  const narration = state.narration?.id === id ? null : state.narration;
  return speaking === state.speaking && queue.length === state.queue.length && narration === state.narration
    ? state
    : { ...state, speaking, queue, narration };
}
