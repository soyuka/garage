import type { Notice } from './state';

// The ear's half of the notice stack (CHOREOGRAPHY § "Audition — speech, for answers"). Pure and
// clock-injected: every rule that matters — order, cap, staleness, dedup, cancellation — is decided
// here, so the impure edge in useVoice.ts is left with nothing but fetch/<audio>/onended.
//
// Speech is GARNISH. The notice card is the payload and the card is authoritative; this queue is
// allowed to drop lines the stack still shows, and does. It must never be the only way to learn
// something, and it must never gate anything.

/** One line waiting to be spoken. `id` is the NOTICE's id, which is what makes the two dedup alike. */
export interface Utterance {
  id: string;
  text: string;
  at: number;
}

export interface VoiceState {
  queue: Utterance[];
  speaking: Utterance | null;
  /** Ids already spoken — the same finding re-arriving is one utterance, mirroring the card dedup. */
  spoken: string[];
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

export const emptyVoice: VoiceState = { queue: [], speaking: null, spoken: [] };

/** Queue a notice. Already spoken, already queued or already on the lips → nothing happens. */
export function enqueue(state: VoiceState, notice: Notice, now: number): VoiceState {
  const known =
    state.spoken.includes(notice.id) ||
    state.speaking?.id === notice.id ||
    state.queue.some((u) => u.id === notice.id);
  if (known) return state;

  // Overrun keeps the NEWEST: the oldest pending line is the one most likely to be stale anyway,
  // and never hearing the latest answer is the worse failure.
  const queue = [...state.queue, { id: notice.id, text: notice.text, at: now }].slice(-VOICE_CAP);
  return { ...state, queue };
}

/** Promote the next fresh line, if nothing is speaking. Stale heads are discarded, not spoken late. */
export function next(state: VoiceState, now: number): VoiceState {
  if (state.speaking) return state;

  const fresh = state.queue.filter((u) => now - u.at <= VOICE_STALE_MS);
  if (fresh.length === 0) return state.queue.length === 0 ? state : { ...state, queue: [] };

  const [head, ...rest] = fresh;
  return { ...state, speaking: head, queue: rest, spoken: [...state.spoken, head.id] };
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
  return speaking === state.speaking && queue.length === state.queue.length ? state : { ...state, speaking, queue };
}
