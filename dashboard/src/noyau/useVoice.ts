import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { API, getToken } from '../auth';
import { currentLocale } from '../i18n/locale';
import { cardLine, emptyVoice, finish, follow, mediaOutcome, narrate, next, rearm, refused, waiting, type VoiceState } from './voice';
import type { Notice } from './state';

// The impure edge of the audition. Every RULE lives in voice.ts; what is left here is fetch, an
// <audio> element and onended — the parts a repo with no jsdom cannot test anyway.

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
 * Speaks the retained notice cards, one at a time, while `enabled`.
 *
 * The card list IS the source of truth: a notice that vanished (the operator said "vu") cancels its
 * own utterance, so audio can never outlive the visual that defines it.
 *
 * Failure is SILENT in every direction — a 503, a 204 over budget, a blocked autoplay, a decode
 * error. Voice is garnish; a toast about a toast would be worse than saying nothing.
 */
/**
 * The answer card, as the audition sees it: a `proposeActions` proposal reduced to the two printed
 * strings it speaks. Structural only — `Cta` itself carries actions the ear has no use for.
 */
export interface SpokenCard {
  headline: string;
  finding?: string | null;
}

/** 8 silent 16-bit frames — the shortest thing that is a real, decodable media resource to play. */
const SILENT_WAV =
  'data:audio/wav;base64,UklGRjQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YRAAAAAAAAAAAAAAAAAAAAAAAAAA';

/** `url` is null while the fetch for this exact `text` is still in flight. */
interface Prefetched {
  text: string;
  url: string | null;
}

/** Synthesize one line. Null means "no audio" (204 over budget, 503 vendor down, 401…) — always silent. */
async function fetchTts(text: string): Promise<Blob | null> {
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
  return res.status === 200 ? res.blob() : null;
}

/**
 * Reads `speaking`'s id back out, so `useNoticeAutoClose` (state.ts) knows whether a card's own line is
 * still on the lips — its dwell must not start until it isn't (CHOREOGRAPHY § "an answer that lands,
 * then clears"). Everything else about the audition stays this hook's own business.
 */
export function useVoice(notices: Notice[], narration: string | null, cta: SpokenCard | null, enabled: boolean): string | null {
  const [state, setState] = useState<VoiceState>(emptyVoice);
  const audio = useRef<HTMLAudioElement | null>(null);
  // Audio fetched AHEAD of the handover, keyed by utterance id (CHOREOGRAPHY § "The voice starts
  // before the browser asks"). Object URLs live here until either the "speak" effect below consumes
  // one (ownership transfers to ITS cleanup) or the pruning pass finds it no longer live and revokes
  // it — every entry is one of those two, never both, so nothing leaks.
  const prefetched = useRef<Map<string, Prefetched>>(new Map());
  // A piloted session produces NO gesture in this browser, so autoplay must be unlocked from the
  // operator's first real interaction and then ASSERTED, never assumed (CHOREOGRAPHY § "The
  // AudioContext trap" — <audio>.play() is under the same policy as WebAudio).
  //
  // STATE, not a ref: the promotion below has to re-run when the gesture finally arrives, and a ref
  // mutation renders nothing. As a ref this silently swallowed the first line of every session.
  const [unlocked, setUnlocked] = useState(false);

  // Every gesture, not just the first: a refused play() latches the audition closed (the browser may
  // have autoplay disabled for this origin), and the next real interaction is the only honest moment to
  // try again — the operator may have granted the permission in between.
  useEffect(() => {
    const unlock = () => {
      // Bless the ELEMENT, not just the document. A line arrives ~2-3 s after this gesture (eleven_v3
      // latency), and an element created that late is judged on the page's autoplay permission alone —
      // which is what Firefox refuses. An element that has already begun playback *inside* a gesture
      // keeps its permission for every later `src`, so one silent frame here buys the whole session.
      // Must be synchronous: an `await` before play() spends the gesture.
      const el = (audio.current ??= new Audio());
      if (!el.src) {
        el.src = SILENT_WAV;
        // Pause only if the blessing frame is still what is loaded — a real line may have claimed the
        // element while this resolved, and silencing the answer would be a worse bug than not pausing.
        void el.play().then(() => { if (el.src === SILENT_WAV) el.pause(); }).catch(() => {});
      }
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

  // Muting stops the current line at once — a mute that finishes its sentence is not a mute. Nothing
  // will ever consume a prefetch fetched under the old (enabled) session, so its object URLs go too.
  useEffect(() => {
    if (enabled) return;
    audio.current?.pause();
    setState(emptyVoice);
    for (const entry of prefetched.current.values()) if (entry.url) URL.revokeObjectURL(entry.url);
    prefetched.current.clear();
  }, [enabled]);

  // Unmount: whatever is still cached was never handed to the "speak" effect below, so its cleanup
  // will never run either — this is the only other owner an object URL here can have.
  useEffect(
    () => () => {
      for (const entry of prefetched.current.values()) if (entry.url) URL.revokeObjectURL(entry.url);
      prefetched.current.clear();
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
  // other), so each is ready at its own handover instead of only starting synthesis once it reaches
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
        if (entry.url) URL.revokeObjectURL(entry.url);
        prefetched.current.delete(id);
      }
    }

    let stopped = false;

    for (const target of waiting(state, performance.now())) {
      const have = prefetched.current.get(target.id);
      if (have && have.text === target.text) continue; // already fetched, or already in flight, for this exact line

      prefetched.current.set(target.id, { text: target.text, url: null }); // in-flight placeholder

      void (async () => {
        let blob: Blob | null = null;
        try {
          blob = await fetchTts(target.text);
        } catch {
          blob = null; // a dead network here just means no prefetch — the handover falls back to its own fetch
        }
        if (stopped) return;

        const url = blob ? URL.createObjectURL(blob) : null;
        const current = prefetched.current.get(target.id);
        const stillOurs = current !== undefined && current.text === target.text && current.url === null;
        if (!stillOurs) {
          // Superseded (a newer line claimed this id, e.g. the ticker moved on) or already consumed
          // at the handover below — abandon this result rather than resurrect a stale cache entry.
          if (url) URL.revokeObjectURL(url);
          return;
        }
        if (!url) prefetched.current.delete(target.id);
        else prefetched.current.set(target.id, { text: target.text, url });
      })();
    }

    return () => {
      stopped = true;
    };
  }, [enabled, state.speaking, state.queue, state.narration]);

  // Speak the promoted line.
  useEffect(() => {
    const utterance = state.speaking;
    if (!enabled || !utterance) return;

    let stopped = false;
    let url: string | null = null;
    const done = () => setState((prev) => (prev.speaking?.id === utterance.id ? finish(prev) : prev));

    // Ready from the prefetch above? Consume it — ownership of the object URL transfers to this
    // effect's cleanup. An in-flight (not yet resolved) entry is left alone: this fetch just runs
    // independently rather than waiting on it, which the `/tts` disk cache makes cheap either way.
    const cached = prefetched.current.get(utterance.id);
    const ready = cached && cached.text === utterance.text ? cached.url : null;
    if (ready) prefetched.current.delete(utterance.id);

    void (async () => {
      try {
        let blobUrl = ready;
        if (!blobUrl) {
          const blob = await fetchTts(utterance.text);
          if (stopped) return;
          if (!blob) return done(); // 204 over budget, 503 vendor down, 401 — all silent
          blobUrl = URL.createObjectURL(blob);
        }
        if (stopped) {
          URL.revokeObjectURL(blobUrl);
          return;
        }
        url = blobUrl;

        // The SAME element every time — see `unlock` above. Handlers are assigned, not added: a reused
        // element would otherwise accumulate one listener pair per line, and a stale pair firing on a
        // later utterance would settle the wrong line. The cleanup nulls them before the next `src`
        // lands, so switching source never trips the error path below.
        const el = (audio.current ??= new Audio());
        el.onended = done;
        // NOT `done`: Firefox delivers an autoplay refusal here, as an aborted media load, and it
        // fires BEFORE the play() promise rejects — so wiring this to done() cleared `speaking` and
        // turned the refused() branch below into a no-op. `stopped` guards our OWN teardown, which
        // aborts the load too and must never be read as the browser refusing.
        el.onerror = () => {
          if (stopped) return;
          const spent = mediaOutcome(el.error?.code) === 'failed';
          setState((prev) => (prev.speaking?.id === utterance.id ? (spent ? finish(prev) : refused(prev)) : prev));
        };
        el.src = url;

        try {
          await el.play();
        } catch {
          // REFUSED, not failed: the page is not allowed to play (Firefox with autoplay off for the
          // origin reports it as an aborted media load, Chrome as NotAllowedError — same cause, and
          // neither is the line's fault). Put it back and wait for a gesture rather than burn it.
          if (!stopped) setState((prev) => (prev.speaking?.id === utterance.id ? refused(prev) : prev));
        }
      } catch {
        if (!stopped) done(); // a dead network or an undecodable body — advance, never stall the queue
      }
    })();

    return () => {
      stopped = true;
      const el = audio.current;
      if (el) {
        // Drop the handlers BEFORE pausing: tearing down aborts the load, and an error arriving on the
        // outgoing line would otherwise be read as a refusal and latch the audition closed.
        el.onended = null;
        el.onerror = null;
        el.pause();
      }
      // The element itself SURVIVES — it carries the gesture's playback permission, and a fresh one
      // would have to earn it again from a page that may no longer be allowed to ask.
      if (url) URL.revokeObjectURL(url);
    };
  }, [enabled, state.speaking]);

  return state.speaking?.id ?? null;
}
