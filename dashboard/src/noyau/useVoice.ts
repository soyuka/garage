import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { API, getToken } from '../auth';
import { currentLocale } from '../i18n/locale';
import { speak, type Speaking } from './speaker';
import { cardLine, emptyVoice, finish, follow, narrate, next, rearm, refused, waiting, type VoiceState } from './voice';
import type { Notice } from './state';

// The impure edge of the audition. Every RULE lives in voice.ts; what is left here is fetch and an
// AudioContext (speaker.ts) — the parts a repo with no jsdom cannot test anyway.

/** Reserved beside `mpa.recency` by CHOREOGRAPHY long before anything read it. Silence is the default. */
const AUDITION_KEY = 'mpa.audition';

/**
 * The one persisted preference. Ships MUTED: `prefers-reduced-motion` is a first path because the OS
 * tells us, and there is no audio twin — Media Queries Level 5 has no `prefers-reduced-sound` — so a
 * preference we cannot read must not be guessed loud.
 */
export function useAudition(): [boolean, () => void] {
  const [on, setOn] = useState(() => {
    try {
      return localStorage.getItem(AUDITION_KEY) === 'on';
    } catch {
      return false; // private mode / storage denied → silent, never a crash
    }
  });

  const toggle = useCallback(() => {
    setOn((prev) => {
      const nextOn = !prev;
      try {
        localStorage.setItem(AUDITION_KEY, nextOn ? 'on' : 'off');
      } catch {
        /* the preference simply does not persist */
      }
      return nextOn;
    });
  }, []);

  return [on, toggle];
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
interface Prefetched {
  text: string;
  response: Promise<Response | null>;
}

/**
 * Ask for one line. Resolves as soon as the headers are in — the body is a stream the speaker reads
 * while it is still being synthesized (`/tts` relays pocket-tts chunk for chunk). Null means "no
 * audio" (204 over budget, 503 vendor down, 401…) — always silent.
 */
async function fetchTts(text: string): Promise<Response | null> {
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
    body: JSON.stringify({ text }),
  });
  return res.status === 200 && res.body ? res : null;
}

/** Let a body nobody will read go, so the connection — and the synthesis behind it — is released. */
function discard(response: Promise<Response | null>): void {
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
 * Reads `speaking`'s id back out, so `useNoticeAutoClose` (state.ts) knows whether a card's own line is
 * still on the lips — its dwell must not start until it isn't (CHOREOGRAPHY § "an answer that lands,
 * then clears"). Everything else about the audition stays this hook's own business.
 */
export function useVoice(notices: Notice[], narration: string | null, cta: SpokenCard | null, enabled: boolean): string | null {
  const [state, setState] = useState<VoiceState>(emptyVoice);
  // ONE context for the tab's life. It is created — and resumed — inside the first real gesture, which
  // is the only place a browser lets it start (CHOREOGRAPHY § "The AudioContext trap"); a piloted
  // session produces no gesture of its own, so this is asserted on every line, never assumed.
  const audio = useRef<AudioContext | null>(null);
  // Responses fetched AHEAD of the handover, keyed by utterance id (CHOREOGRAPHY § "The voice starts
  // before the browser asks"). Each entry is either consumed by the "speak" effect below (which then
  // owns the body) or found no longer live by the pruning pass and cancelled — one of the two, never
  // both, so no connection is left hanging.
  const prefetched = useRef<Map<string, Prefetched>>(new Map());
  // STATE, not a ref: the promotion below has to re-run when the gesture finally arrives, and a ref
  // mutation renders nothing. As a ref this silently swallowed the first line of every session.
  const [unlocked, setUnlocked] = useState(false);

  // Every gesture, not just the first: a refused context latches the audition closed (the browser may
  // have autoplay disabled for this origin), and the next real interaction is the only honest moment to
  // try again — the operator may have granted the permission in between.
  useEffect(() => {
    const unlock = () => {
      // Synchronous on purpose: an `await` before resume() spends the gesture.
      const ctx = (audio.current ??= new AudioContext());
      void ctx.resume().catch(() => undefined);
      setUnlocked(true);
      setState(rearm);
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
            // Keyed exactly like the inbox's deferred copy (`review:<headline>`, Atelier.tsx), so a
            // proposal that gets superseded and parked is ONE line to the ear, not two.
            { id: `review:${cta.headline}`, tone: 'info' as const, text: cardLine(cta.headline, cta.finding), at: 0 },
          ],
    [notices, cta],
  );

  // Follow the stack: new cards queue, vanished cards cancel.
  useEffect(() => {
    if (!enabled) return;
    setState((prev) => follow(prev, cards, performance.now()));
  }, [cards, enabled]);

  // The orb's live line — the commentary, one slot, latest wins (voice.ts `narrate`).
  useEffect(() => {
    if (!enabled || null === narration) return;
    setState((prev) => narrate(prev, narration, performance.now()));
  }, [narration, enabled]);

  // Muting stops the current line at once (the "speak" effect's cleanup runs on `enabled`) and empties
  // the queue. Nothing will ever consume a prefetch fetched under the old session, so those go too, and
  // the context is suspended so a silent tab does not keep a render thread awake (cheap idle).
  useEffect(() => {
    if (enabled) return;
    setState(emptyVoice);
    for (const entry of prefetched.current.values()) discard(entry.response);
    prefetched.current.clear();
    void audio.current?.suspend().catch(() => undefined);
  }, [enabled]);

  // Unmount: whatever is still cached was never handed to the "speak" effect below, so its cleanup
  // will never run either — this is the only other owner a prefetched body can have.
  useEffect(
    () => () => {
      for (const entry of prefetched.current.values()) discard(entry.response);
      prefetched.current.clear();
      void audio.current?.close().catch(() => undefined);
      audio.current = null;
    },
    [],
  );

  // Promote whenever nothing is on the lips AND the page may actually play audio. While locked this
  // still runs, to let a stale line expire — it just never consumes a fresh one. EVERY source of a
  // pending line is a dependency: the ticker slot was missing here, and a slot nothing watches is a
  // line nobody speaks (measured against the live pilot channel, 2026-08-17).
  useEffect(() => {
    if (!enabled || state.speaking) return;
    setState((prev) => next(prev, performance.now(), unlocked));
  }, [enabled, state.speaking, state.queue, state.narration, state.blocked, unlocked]);

  // Fetch every line waiting behind the one now speaking, each in PARALLEL with it (and with each
  // other), so each is already streaming at its own handover instead of only asking once it reaches
  // the lips (CHOREOGRAPHY § "The voice starts before the browser asks": "one synthesis in flight per
  // waiting line, not one at a time"). `waiting()` (voice.ts) says which lines those are; this effect
  // only fetches them — the promotion itself still happens, and is still gated on `unlocked`, in
  // `next()`.
  useEffect(() => {
    if (!enabled) return;

    // Prune first: a prefetch whose id+text is no longer speaking, queued or ticker-pending was for a
    // line that got superseded, cap-evicted or cancelled — nothing will ever consume it.
    for (const [id, entry] of prefetched.current) {
      const stillLive =
        state.speaking?.id === id && state.speaking.text === entry.text
          ? true
          : state.queue.some((u) => u.id === id && u.text === entry.text) ||
            (state.narration?.id === id && state.narration.text === entry.text);
      if (!stillLive) {
        discard(entry.response);
        prefetched.current.delete(id);
      }
    }

    for (const target of waiting(state, performance.now())) {
      const have = prefetched.current.get(target.id);
      if (have && have.text === target.text) continue; // already asked for this exact line

      // A dead network here just means no prefetch — the handover asks again itself.
      prefetched.current.set(target.id, { text: target.text, response: fetchTts(target.text).catch(() => null) });
    }
  }, [enabled, state.speaking, state.queue, state.narration]);

  // Speak the promoted line.
  useEffect(() => {
    const utterance = state.speaking;
    if (!enabled || !utterance) return;

    let stopped = false;
    let playing: Speaking | null = null;
    const done = () => setState((prev) => (prev.speaking?.id === utterance.id ? finish(prev) : prev));

    // Asked for ahead of time? Take it over — ownership of the body transfers to this effect. The
    // promise may still be waiting on headers; awaiting it is still earlier than asking again, and it
    // is the one request the server is synthesizing for, so a second would only compete with it.
    const ahead = prefetched.current.get(utterance.id);
    const response = ahead && ahead.text === utterance.text ? ahead.response : null;
    if (response) prefetched.current.delete(utterance.id);

    void (async () => {
      try {
        const res = await (response ?? fetchTts(utterance.text));
        if (stopped) {
          if (res) discard(Promise.resolve(res));
          return;
        }
        if (!res?.body) return done(); // 204 over budget, 503 vendor down, 401 — all silent

        const ctx = (audio.current ??= new AudioContext());
        playing = speak(ctx, res.body);
        const outcome = await playing.outcome;
        if (stopped) return;

        // REFUSED, not failed: the page is not allowed to play (autoplay off for the origin). Put the
        // line back and wait for a gesture rather than burn it. A body that could not be decoded is
        // the LINE's fault: spend it and advance, so one bad body never silences the ones behind it.
        if (outcome === 'refused') setState((prev) => (prev.speaking?.id === utterance.id ? refused(prev) : prev));
        else done();
      } catch {
        if (!stopped) done(); // a dead network — advance, never stall the queue
      }
    })();

    return () => {
      stopped = true;
      playing?.stop();
    };
  }, [enabled, state.speaking]);

  return state.speaking?.id ?? null;
}
