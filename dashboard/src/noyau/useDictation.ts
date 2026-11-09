import { useCallback, useEffect, useRef, useState } from 'react';
import { API, getToken } from '../auth';
import { currentLocale } from '../i18n/locale';
import { MicrophoneRefused, RecordingCancelled, record, type Recorder } from './recorder';
import type { ArmedMicCue } from './useCues';
import { MAX_RECORDING_MS, dictate, emptyDictation, type DictationEvent, type DictationState } from './dictation';

// The impure edge of dictation. Every RULE lives in dictation.ts; what is left here is a microphone
// (recorder.ts) and one fetch — the parts a repo with no jsdom cannot test anyway. Same shape and
// same reasoning as useVoice.ts beside it.
//
// Its one hard job is the gap between the press and the stream: `getUserMedia` resolves a permission
// prompt later, or several seconds later the first time. Everything in here that looks like ceremony —
// the `pending` token, reading the machine off a ref instead of a setState updater — is about that gap
// (2026-09-11: a click used to transcribe a recorder that did not exist yet, and leave the stream that
// arrived afterwards open with nothing holding it).

/**
 * Post one recording. Resolves to the transcript, `''` for silence, and **null** for every failure —
 * a dead vendor, a 503, a 401, a network that went away. The caller cannot tell them apart on purpose:
 * all of them end with the composer unchanged and the operator typing.
 *
 * Deliberately NOT `authFetch`: that bounces to the login flow on a 401, and an input convenience must
 * never seize the session (the same posture `/tts` takes — CONTRACT.md).
 */
async function fetchStt(blob: Blob): Promise<string | null> {
  const token = getToken();
  try {
    // Every failure below is SILENT by design (no card, no toast), which
    // makes a broken vendor indistinguishable from a working one that heard nothing. The console is the
    // one place that can tell them apart without breaking that rule, so each outcome says which it was.
    console.debug('[dictée] posting %d bytes (%s)', blob.size, blob.type || 'no type');
    const res = await fetch(`${API}/stt`, {
      method: 'POST',
      headers: {
        // The blob's own container, whatever the browser picked. The endpoint refuses anything that
        // is not audio/*, and the vendor decodes by this rather than sniffing.
        'Content-Type': blob.type || 'audio/webm',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        // Which language is being SPOKEN. Not a hint: transcribed as the wrong language, a French
        // instruction comes back as different words, not as accented ones.
        'Accept-Language': currentLocale(),
      },
      body: blob,
    });
    if (200 !== res.status) {
      console.warn(
        '[dictée] /stt refused: %d%s',
        res.status,
        401 === res.status
          ? ' — not authenticated (the session token is missing or stale; reload and sign in)'
          : 503 === res.status
            ? ' — the transcription vendor is unreachable or refused the key/plan (api/.env.local ELEVENLABS_API_KEY, and Scribe needs an entitled plan)'
            : 400 === res.status
              ? ' — the recording was empty, over 1 MB, or not sent as audio/*'
              : '',
      );

      return null;
    }
    const payload: unknown = await res.json();
    const text =
      'object' === typeof payload && null !== payload && 'string' === typeof (payload as { text?: unknown }).text
        ? (payload as { text: string }).text
        : null;
    console.debug('[dictée] transcript: %o', text);

    return text;
  } catch (e) {
    // offline, aborted, CORS — silence in the UI, never a throw into a render
    console.warn('[dictée] /stt could not be reached:', e);

    return null;
  }
}

export interface Dictation {
  state: DictationState;
  /**
   * The one control: opens the mic at rest, closes it and transcribes while recording, cancels while
   * the permission prompt is still up. Must be called from the gesture itself — a browser grants
   * capture nowhere else.
   */
  toggle: () => void;
}

/**
 * The push-to-start / press-again-to-stop control's behaviour.
 *
 * @param onTranscript called with the operator's words once they land. Never called for silence or for
 *                     a failure — the composer must be left exactly as it was in both cases, so that a
 *                     mis-press costs one gesture and nothing else.
 * @param busy         a turn is running. One instruction at a time, on both input paths.
 */
export function useDictation(onTranscript: (text: string) => void, busy: boolean, armMicCue?: () => ArmedMicCue): Dictation {
  const [state, setState] = useState<DictationState>(emptyDictation);
  // The machine's current value, read synchronously. NOT a `setState(prev => …)` updater, because the
  // transitions below have side effects — opening a microphone, stopping one, posting a recording — and
  // React double-invokes updaters under StrictMode, which asked the browser for TWO streams per press
  // and leaked the one that lost the race (2026-09-11).
  const current = useRef<DictationState>(emptyDictation);
  /** The open mic, once the browser has handed it over. */
  const recorder = useRef<Recorder | null>(null);
  /**
   * The `record()` call in flight. It is also the CANCELLATION token: cleared the moment the gesture
   * stops wanting a microphone, so the stream that lands afterwards is closed instead of opened.
   */
  const pending = useRef<Promise<Recorder> | null>(null);
  const cancelPending = useRef<(() => void) | null>(null);
  /**
   * This component is still mounted. The transcript lands asynchronously and an unmounted component
   * must not write into an input that is gone, nor leave the microphone open.
   *
   * Set on the way IN as well as cleared on the way out, which is not ceremony: StrictMode mounts,
   * unmounts and re-mounts every component once in development, so a flag only ever cleared stayed
   * false for the whole life of the hook — the mic opened and was handed straight back, and any
   * transcript was dropped on arrival, leaving the control stuck on `⋯`. That is dev-only, and
   * `npm run dev` is where this is used (2026-09-11).
   */
  const live = useRef(true);
  useEffect(() => {
    live.current = true;

    return () => {
      live.current = false;
      cancelPending.current?.();
      cancelPending.current = null;
      pending.current = null;
      void recorder.current?.stop();
      recorder.current = null;
    };
  }, []);

  /** Apply one event. Returns the new state, or null when the machine refused the event. */
  const apply = useCallback((event: DictationEvent): DictationState | null => {
    const next = dictate(current.current, event);
    if (next === current.current) return null;
    current.current = next;
    setState(next);

    return next;
  }, []);

  const transcribe = useCallback(async () => {
    const open = recorder.current;
    recorder.current = null;
    if (!open) {
      apply({ type: 'failed' });
      return;
    }

    const { blob } = await open.stop();
    if (0 === blob.size) console.warn('[dictée] the recording is empty — the mic captured no audio at all');
    // An empty capture never reaches the endpoint: it would be a 400, and a refusal we can predict is
    // not worth a round trip or a line in the vendor's bill.
    const text = 0 === blob.size ? '' : await fetchStt(blob);
    if (!live.current) return;

    if (null === text) {
      apply({ type: 'failed' });
      return;
    }
    apply({ type: 'transcript', text });
    if ('' !== text.trim()) onTranscript(text);
  }, [apply, onTranscript]);

  /**
   * Ask for the microphone. The permission prompt may outlive the press by seconds — which is exactly
   * why the control latches (`dictation.ts`) — so the recording only begins when the stream lands, and
   * a stream nobody is waiting for any more is closed rather than opened.
   */
  const arm = useCallback(() => {
    const cue = armMicCue?.();
    const controller = new AbortController();
    cancelPending.current = () => {
      controller.abort();
      cue?.cancel();
    };
    const asked = record(() => cue?.play() ?? Promise.resolve(), controller.signal);
    pending.current = asked;
    void asked
      .then((open) => {
        if (pending.current !== asked || !live.current) {
          void open.stop(); // cancelled mid-prompt, or unmounted: give the microphone straight back
          return;
        }
        pending.current = null;
        cancelPending.current = null;
        recorder.current = open;
        apply({ type: 'opened', at: performance.now() });
      })
      .catch((e: unknown) => {
        cue?.cancel();
        if (pending.current !== asked || !live.current) return;
        pending.current = null;
        cancelPending.current = null;
        if (e instanceof RecordingCancelled) return;
        // A declined permission is an answer, not an error to decorate: the control goes away for
        // the session rather than re-prompting on every press.
        console.warn('[dictée] the microphone did not open:', e);
        apply(e instanceof MicrophoneRefused ? { type: 'denied' } : { type: 'failed' });
      });
  }, [apply, armMicCue]);

  const toggle = useCallback(() => {
    const next = apply({ type: 'press', at: performance.now(), busy });
    if (!next) return; // busy, latched away, or a transcript still in flight

    if ('arming' === next.phase) arm();
    else if ('transcribing' === next.phase) void transcribe();
    // Back to idle from `arming`: the gesture was cancelled before any audio existed. Dropping the
    // token is what turns the stream still on its way into one that closes itself.
    else {
      cancelPending.current?.();
      cancelPending.current = null;
      pending.current = null;
    }
  }, [apply, arm, busy, transcribe]);

  // The cap: a mic the operator forgot to close stops itself and transcribes what it has. One timer
  // for the whole recording, not a polling tick — the rule is a deadline, not a sampled state.
  useEffect(() => {
    if ('recording' !== state.phase || null === state.startedAt) return;
    const remaining = state.startedAt + MAX_RECORDING_MS - performance.now();
    const timer = setTimeout(
      () => {
        if (apply({ type: 'tick', at: performance.now() })) void transcribe();
      },
      Math.max(0, remaining),
    );

    return () => clearTimeout(timer);
  }, [apply, state.phase, state.startedAt, transcribe]);

  return { state, toggle };
}
