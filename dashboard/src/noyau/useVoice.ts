import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { API, getToken } from '../auth';
import { currentLocale } from '../i18n/locale';
import { audioContext, closeAudio, resumeAudioFromGesture, suspendIfIdle } from './audioContext';
import { speak, type Speaking } from './speaker';
import { readVoicePreference, writeVoicePreference } from './voicePreference';
import {
  cardLine,
  emptyVoice,
  heard,
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
  type Utterance,
  type VoiceState,
} from './voice';
import type { Notice } from './state';

// The impure edge of the audition. Every RULE lives in voice.ts; what is left here is fetch and an
// AudioContext (speaker.ts) — the parts a repo with no jsdom cannot test anyway.

/**
 * The persisted voice preference (own storage key, `voicePreference.ts` — shared with
 * `Preferences.tsx`'s picker and the fetch bodies below). `null` is the server default, not "unset":
 * there is nothing to validate against a stale list here (`applyVoice`, `speechVoices.ts` — the
 * picker's job), this hook only remembers what the operator last chose.
 */
export function useVoicePreference(): [string | null, (id: string | null) => void] {
  const [voice, setVoiceState] = useState<string | null>(() => readVoicePreference(localStorage));

  const setVoice = useCallback((id: string | null) => {
    writeVoicePreference(localStorage, id);
    setVoiceState(id);
  }, []);

  return [voice, setVoice];
}

/**
 * The answer card, as the audition sees it: a `proposeActions` proposal reduced to the two printed
 * strings it speaks. Structural only — `Cta` itself carries actions the ear has no use for.
 */
export interface SpokenCard {
  headline: string;
  finding?: string | null;
}

/** A line's audio, fetched ahead of its turn. The promise settles on HEADERS, not on the last byte. */
type Prefetched = Promise<Response | null>;

/**
 * Ask for one line. Resolves as soon as the headers are in — the body is a stream the speaker reads
 * while it is still being synthesized (`/tts` relays the backend chunk for chunk). Null means "no
 * audio" (503 vendor down or no key configured, 401…) — always silent.
 *
 * `voice` is omitted from the body entirely when null — the server default, and the wire must not
 * carry a literal `voice: null` for it (CONTRACT.md).
 */
async function fetchTts(text: string, voice: string | null): Promise<Response | null> {
  const token = getToken();
  // Deliberately NOT authFetch: that bounces to the login flow on 401, and a garnish feature must
  // never seize the session. A voice that cannot authenticate is a voice that stays quiet.
  const res = await fetch(`${API}/tts`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      // Which language to READ the line in. The card's text is whatever the agent wrote; this says
      // how to pronounce it, and a multilingual voice needs telling (api SpeechController).
      'Accept-Language': currentLocale(),
    },
    body: JSON.stringify({ text, ...(voice ? { voice } : {}) }),
  });
  return res.status === 200 && res.body ? res : null;
}

/** Let a body nobody will read go, so the connection — and the synthesis behind it — is released. */
function discard(response: Prefetched): void {
  void response.then((res) => res?.body?.cancel()).catch(() => undefined);
}

/**
 * Speaks the retained notice cards, one at a time, while `enabled`.
 *
 * The card list IS the source of truth: a notice that vanished (the operator said "vu") cancels its
 * own utterance, so audio can never outlive the visual that defines it.
 *
 * Failure is SILENT in every direction — a 503, a 204 over budget, a refused context, an undecodable
 * body. Voice is garnish; a toast about a toast would be worse than saying nothing.
 *
 * Reads the AUDIBLE line's id back out (`voice.ts` `heard`), so `useNoticeAutoClose` (state.ts) knows
 * whether a card's own line is still on the lips — its dwell must not start until it isn't.
 * Deliberately the head of the pipeline and not
 * merely "scheduled": since the gapless seam (2026-09-11) a second line is placed on the clock while
 * the first is still being heard, and a card whose dwell started then would clear while it was still
 * being read aloud. Everything else about the audition stays this hook's own business.
 *
 * `voice` is read, never reacted to: it is deliberately absent from every effect's dependency array
 * below, so a change lands only in the closure a later render captures — the next `fetchTts` call, for
 * a line QUEUED or PROMOTED after the change. It is never a reason on its own to re-run an effect,
 * which is what keeps it from cutting the utterance currently on the lips.
 */
/** A line's hold on the audio clock, owned by the hook (not by the pure queue). */
interface Live {
  /** the audio-clock time this line's last sample lands on — null when it never reached the clock */
  placed: Promise<number | null>;
  stop(): void;
}

export function useVoice(
  notices: Notice[],
  narration: string | null,
  /**
   * The ticker line is the opening greeting: said once per session, never re-spoken. Every other
   * narration keeps the ticker's own rule (shown again = news again), which is why this is a flag on
   * the LINE rather than a mode of the hook — `state.ts` sets it, `voice.ts` enforces it.
   */
  narrationOnce: boolean,
  cta: SpokenCard | null,
  enabled: boolean,
  voice: string | null,
): string | null {
  const [state, setState] = useState<VoiceState>(emptyVoice);
  // ONE context for the tab's life, now shared with the room bed (`audioContext.ts`) — the bed's duck is
  // placed against the same clock as the line it ducks under. It is created — and resumed — inside the
  // first real gesture, which is the only place a browser lets it start; a piloted session produces no gesture of its own, so this is asserted on every line, never
  // assumed.
  // Responses fetched AHEAD of the handover. Each entry is either consumed by the "speak" effect below (which then owns the body) or
  // found no longer live by the pruning pass and cancelled — one of the two, never both, so no
  // connection is left hanging.
  //
  // Keyed by `utteranceKey`, never by `id`: two ticker lines share the id `narration` and both can be on
  // the clock at once since the seam, so an id-keyed map loses one of them (voice.ts `utteranceKey`).
  const prefetched = useRef<Map<string, Prefetched>>(new Map());
  /** One line's hold on the audio clock: when its last sample lands, and how to cut it. Same key. */
  const playing = useRef<Map<string, Live>>(new Map());
  /**
   * The line whose body is fully PLACED on the clock — the seam's trigger. State, not a ref: the
   * promotion below has to re-run the moment it changes, and a ref mutation renders nothing.
   */
  const [placedKey, setPlacedKey] = useState<string | null>(null);
  /** Still mounted. Set on the way in as well as cleared, so StrictMode's remount does not latch it. */
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;

    return () => {
      mounted.current = false;
    };
  }, []);
  // STATE, not a ref: the promotion below has to re-run when the gesture finally arrives, and a ref
  // mutation renders nothing. As a ref this silently swallowed the first line of every session.
  const [unlocked, setUnlocked] = useState(false);

  // Every gesture, not just the first: a refused context latches the audition closed (the browser may
  // have autoplay disabled for this origin), and the next real interaction is the only honest moment to
  // try again — the operator may have granted the permission in between.
  useEffect(() => {
    const unlock = () => {
      void resumeAudioFromGesture().then((running) => {
        if (!running) return;
        setUnlocked(true);
        setState(rearm);
      });
    };
    window.addEventListener('pointerdown', unlock);
    window.addEventListener('keydown', unlock);
    return () => {
      window.removeEventListener('pointerdown', unlock);
      window.removeEventListener('keydown', unlock);
    };
  }, []);

  // Everything PRINTED as a card, in speaking order: the notice stack, then the answer card. The card
  // is deliberately last — a notice is a settled answer, a proposal is one awaiting a decision.
  const cards = useMemo(
    () =>
      null === cta
        ? notices
        : [
            ...notices,
            // Keyed on the headline, so a re-rendered proposal is ONE line to the ear, not two.
            { id: `review:${cta.headline}`, tone: 'info' as const, text: cardLine(cta.headline, cta.finding), at: 0 },
          ],
    [notices, cta],
  );

  // Follow the stack: new cards queue, vanished cards cancel.
  useEffect(() => {
    if (!enabled) return;
    setState((prev) => follow(prev, cards, performance.now()));
  }, [cards, enabled]);

  // A `proposeActions` card arriving (or changing to a different headline) IS the answer — every
  // notice-toast line still waiting behind it is dropped unspoken (voice.ts `supersedeQueued`).
  // Keyed on the headline ALONE so an
  // unrelated re-render (a finding edit under the same headline, a hover) never re-fires this — only
  // the card's arrival or a genuinely different headline does.
  useEffect(() => {
    if (!enabled || null === cta) return;
    setState((prev) => supersedeQueued(prev, `review:${cta.headline}`));
  }, [enabled, cta?.headline]);

  // The orb's live line — the commentary, one slot, latest wins (voice.ts `narrate`).
  useEffect(() => {
    if (!enabled || null === narration) return;
    setState((prev) => narrate(prev, narration, performance.now(), narrationOnce));
  }, [narration, narrationOnce, enabled]);

  // Muting stops the current line at once (the "speak" effect's cleanup runs on `enabled`) and empties
  // the queue — `silence()` rather than `emptyVoice` so the one-shot memory survives it (voice.ts): the
  // audition goes off on every mic press, and a welcome forgotten there is a welcome said twice.
  // Nothing will ever consume a prefetch fetched under the old session, so those go too, and the
  // context is suspended so a silent tab does not keep a render thread awake (cheap idle).
  useEffect(() => {
    if (enabled) return;
    setState(silence);
    for (const entry of playing.current.values()) entry.stop();
    playing.current.clear();
    setPlacedKey(null);
    for (const entry of prefetched.current.values()) discard(entry);
    prefetched.current.clear();
    // Guarded, not a bare suspend: this runs on every mic press, and the bed may be holding the
    // context (`audioContext.ts` `suspendIfIdle`).
    void suspendIfIdle();
  }, [enabled]);

  // Unmount: whatever is still cached was never handed to the "speak" effect below, so its cleanup
  // will never run either — this is the only other owner a prefetched body can have.
  useEffect(
    () => () => {
      for (const entry of playing.current.values()) entry.stop();
      playing.current.clear();
      for (const entry of prefetched.current.values()) discard(entry);
      prefetched.current.clear();
      void closeAudio();
    },
    [],
  );

  /**
   * Start ONE line: take its prefetched body (or fetch it), place it on the audio clock flush behind
   * `previous`, and own it until it ends. Returns synchronously — the entry is registered before any
   * await, so a re-render mid-fetch can never start the same line twice — and hands back the two things
   * the pipeline needs: when this line's last sample lands, and how to cut it.
   *
   * Every failure is SILENT (a 204 over budget, a 503, a 401, an undecodable body): the line is spent
   * and the next one takes over. Voice is garnish; a toast about a toast would be worse than nothing.
   */
  const start = useCallback(
    (utterance: Utterance, previous: Live | null): Live => {
      const key = utteranceKey(utterance);
      let cancelled = false;
      let scheduled: Speaking | null = null;
      let settlePlaced: (at: number | null) => void = () => undefined;
      const placed = new Promise<number | null>((resolve) => {
        settlePlaced = (at) => {
          resolve(at);
          settlePlaced = () => undefined;
        };
      });
      // By KEY: the head may carry the same id as this line and still be a different line (two ticker
      // lines), and popping the pipeline on the wrong one strands a line at the head for good.
      const isHead = (prev: VoiceState): boolean => {
        const head = heard(prev);
        return null !== head && utteranceKey(head) === key;
      };
      const ended = (): void => setState((prev) => (isHead(prev) ? finish(prev) : prev));

      void (async () => {
        // Asked for ahead of time? Take it over — ownership of the body transfers here. The promise may
        // still be waiting on headers; awaiting it is still earlier than asking again, and it is the one
        // request the server is synthesizing for, so a second would only compete with it.
        const response = prefetched.current.get(key) ?? null;
        if (response) prefetched.current.delete(key);

        let res: Response | null = null;
        try {
          res = await (response ?? fetchTts(utterance.text, voice));
        } catch {
          res = null; // a dead network — spend the line, never stall the pipeline
        }
        if (cancelled || !mounted.current) {
          if (res) discard(Promise.resolve(res));
          settlePlaced(null);
          return;
        }
        if (!res?.body) {
          settlePlaced(null);
          ended();
          return;
        }

        // The seam: the previous line's last sample. Already settled by the time we get here — the
        // queue only promotes a successor once its predecessor is fully placed — so this does not wait.
        // Null (nothing before it, or it never played) means "start at the clock", i.e. today's cold start.
        const startAt = previous ? await previous.placed : null;
        if (cancelled || !mounted.current) {
          discard(Promise.resolve(res));
          settlePlaced(null);
          return;
        }

        const ctx = audioContext();
        scheduled = speak(ctx, res.body, startAt);
        void scheduled.placed.then((at) => {
          settlePlaced(at);
          // This is the "fully placed" signal `next()` waits on to promote the successor early — the
          // whole reason a seam is possible at all (voice.ts `canChain`).
          if (!cancelled && mounted.current) setPlacedKey(key);
        });

        const outcome = await scheduled.outcome;
        if (cancelled || !mounted.current) return;
        // REFUSED, not failed: the page is not allowed to play (autoplay off for the origin). Put the
        // line back and wait for a gesture rather than burn it.
        if ('refused' === outcome) setState((prev) => (isHead(prev) ? refused(prev) : prev));
        else ended();
      })();

      return {
        placed,
        stop: () => {
          cancelled = true;
          settlePlaced(null);
          scheduled?.stop();
        },
      };
    },
    [voice],
  );

  // Promote onto the clock. `canChain` is the seam: the audible line's body is fully placed, so its
  // successor can be scheduled flush against it while it is still being heard. While the page is still locked this runs anyway, to let a stale
  // line expire — it just never consumes a fresh one. EVERY source of a pending line is a dependency:
  // the ticker slot was missing here once, and a slot nothing watches is a line nobody speaks (measured
  // against the live pilot channel, 2026-08-17).
  useEffect(() => {
    if (!enabled) return;
    const canChain = 1 === state.onAir.length && placedKey === utteranceKey(state.onAir[0]);
    setState((prev) => next(prev, performance.now(), unlocked, canChain));
  }, [enabled, state.onAir, state.queue, state.narration, state.blocked, unlocked, placedKey]);

  // Fetch every line waiting behind the ones on the clock, each in PARALLEL with them (and with each
  // other), so each is already streaming at its own handover instead of only asking once it reaches the
  // lips. `waiting()` (voice.ts) says which
  // lines those are; this effect only fetches them — the promotion itself still happens, and is still
  // gated on `unlocked`, in `next()`.
  useEffect(() => {
    if (!enabled) return;

    // Prune first: a prefetch whose line is no longer on the clock, queued or ticker-pending was for one
    // that got superseded, cap-evicted or cancelled — nothing will ever consume it.
    const live = new Set(
      [...state.onAir, ...state.queue, ...(state.narration ? [state.narration] : [])].map(utteranceKey),
    );
    for (const [key, entry] of prefetched.current) {
      if (live.has(key)) continue;
      discard(entry);
      prefetched.current.delete(key);
    }

    for (const target of waiting(state, performance.now())) {
      const key = utteranceKey(target);
      if (prefetched.current.has(key)) continue; // already asked for this exact line

      // A dead network here just means no prefetch — the handover asks again itself.
      prefetched.current.set(key, fetchTts(target.text, voice).catch(() => null));
    }
  }, [enabled, state.onAir, state.queue, state.narration]);

  // Keep the running lines in step with the pipeline: start what has been promoted and is not yet
  // sounding, cut what has left it (a dismissal, a supersession, a mute). Deliberately NOT a cleanup
  // that tears everything down on every change — a line must survive its successor being scheduled
  // behind it, which is the entire point of the seam.
  useEffect(() => {
    if (!enabled) return;

    const onClock = new Set(state.onAir.map(utteranceKey));
    for (const [key, entry] of playing.current) {
      if (onClock.has(key)) continue;
      entry.stop();
      playing.current.delete(key);
    }

    state.onAir.forEach((utterance, index) => {
      const key = utteranceKey(utterance);
      if (playing.current.has(key)) return;
      const ahead = 0 === index ? null : (playing.current.get(utteranceKey(state.onAir[index - 1])) ?? null);
      playing.current.set(key, start(utterance, ahead));
    });
  }, [enabled, state.onAir, start]);

  return heard(state)?.id ?? null;
}
