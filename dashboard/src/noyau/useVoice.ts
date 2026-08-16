import { useCallback, useEffect, useRef, useState } from 'react';
import { API, getToken } from '../auth';
import { currentLocale } from '../i18n/locale';
import { cancel, emptyVoice, enqueue, finish, next, type VoiceState } from './voice';
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
export function useVoice(notices: Notice[], enabled: boolean): void {
  const [state, setState] = useState<VoiceState>(emptyVoice);
  const audio = useRef<HTMLAudioElement | null>(null);
  // A piloted session produces NO gesture in this browser, so autoplay must be unlocked from the
  // operator's first real interaction and then ASSERTED, never assumed (CHOREOGRAPHY § "The
  // AudioContext trap" — <audio>.play() is under the same policy as WebAudio).
  const unlocked = useRef(false);

  useEffect(() => {
    const unlock = () => {
      unlocked.current = true;
    };
    window.addEventListener('pointerdown', unlock, { once: true });
    window.addEventListener('keydown', unlock, { once: true });
    return () => {
      window.removeEventListener('pointerdown', unlock);
      window.removeEventListener('keydown', unlock);
    };
  }, []);

  // Follow the stack: new cards queue, vanished cards cancel.
  useEffect(() => {
    if (!enabled) return;
    setState((prev) => {
      const live = new Set(notices.map((n) => n.id));
      let out = prev;
      for (const u of [prev.speaking, ...prev.queue]) {
        if (u && !live.has(u.id)) out = cancel(out, u.id);
      }
      for (const n of notices) out = enqueue(out, n, performance.now());
      return out;
    });
  }, [notices, enabled]);

  // Muting stops the current line at once — a mute that finishes its sentence is not a mute.
  useEffect(() => {
    if (enabled) return;
    audio.current?.pause();
    setState(emptyVoice);
  }, [enabled]);

  // Promote whenever nothing is on the lips.
  useEffect(() => {
    if (!enabled || state.speaking) return;
    setState((prev) => next(prev, performance.now()));
  }, [enabled, state.speaking, state.queue]);

  // Speak the promoted line.
  useEffect(() => {
    const utterance = state.speaking;
    if (!enabled || !utterance) return;

    let stopped = false;
    let url: string | null = null;
    const done = () => setState((prev) => (prev.speaking?.id === utterance.id ? finish(prev) : prev));

    void (async () => {
      if (!unlocked.current) return done(); // no gesture yet — the card already carries the answer

      try {
        const token = getToken();
        // Deliberately NOT authFetch: that bounces to the login flow on 401, and a garnish feature
        // must never seize the session. A voice that cannot authenticate is a voice that stays quiet.
        const res = await fetch(`${API}/tts`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
            // Which language to READ the line in. The card's text is whatever the agent wrote; this
            // says how to pronounce it, and a multilingual voice needs telling (api SpeechController).
            'Accept-Language': currentLocale(),
          },
          body: JSON.stringify({ text: utterance.text }),
        });
        if (stopped) return;
        if (res.status !== 200) return done(); // 204 over budget, 503 vendor down, 401 — all silent

        url = URL.createObjectURL(await res.blob());
        if (stopped) return;

        const el = new Audio(url);
        audio.current = el;
        el.addEventListener('ended', done, { once: true });
        el.addEventListener('error', done, { once: true });
        await el.play();
      } catch {
        if (!stopped) done(); // blocked autoplay or a dead network — advance, never stall the queue
      }
    })();

    return () => {
      stopped = true;
      audio.current?.pause();
      audio.current = null;
      if (url) URL.revokeObjectURL(url);
    };
  }, [enabled, state.speaking]);
}
