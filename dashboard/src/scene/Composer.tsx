import { useCallback, useEffect, useRef, type ReactNode } from 'react';
import { useT } from '../i18n/LocaleProvider';
import { merge, submission } from '../noyau/dictation';
import { useDictation } from '../noyau/useDictation';
import type { ArmedMicCue } from '../noyau/useCues';

// The single composer input, extracted out of SceneView (CHOREOGRAPHY § "The agent is on the home
// screen too" — "the composer moves, it is not copied"). Both the hero (via SceneView) and the parc
// landing (Atelier.tsx directly) mount THIS, so there is exactly one input to keep in sync and one
// place the busy/lock rule (`onSubmit` no-ops while busy — see Atelier.tsx `send`) is enforced.
//
// The mic lives HERE for the same reason (CHOREOGRAPHY § "La dictée"): a second mic in a second
// composer would be the copy that rule forbids.

export interface ComposerProps {
  /** Composer focus toggles Le Noyau's listening cue (a local UI event, not a wire frame). */
  onComposerFocus?: (active: boolean) => void;
  /** Submit an instruction (Enter). Live → posts an agent turn; absent → input is inert (pre-agent). */
  onSubmit?: (instruction: string) => void;
  /** Driver-injected controls rendered before the input (the playground's ▶/⚠ sims, the hero's ✕ cancel). */
  composerExtras?: ReactNode;
  /**
   * The mic is open. The caller holds the audition while it is (CHOREOGRAPHY § "La dictée": the
   * microphone must never transcribe our own voice), which is why this reports upward at all.
   */
  onListening?: (active: boolean) => void;
  /** A turn is running: the mic is inert, exactly as submit is. One instruction at a time. */
  busy?: boolean;
  armMicCue?: () => ArmedMicCue;
}

export function Composer({ onComposerFocus, onSubmit, composerExtras, onListening, busy = false, armMicCue }: ComposerProps) {
  const t = useT();
  const input = useRef<HTMLInputElement>(null);

  /**
   * A landed transcript SENDS itself (`dictation.ts` `submission`, 2026-09-11) — appended to whatever
   * was typed, never replacing it, so the turn carries the whole sentence.
   *
   * When it cannot be sent — no live agent (the playground mounts the composer inert), a turn already
   * running, nothing but silence — the words are put in the input instead, focused with the caret at
   * the end, where Enter has always been the way out. Nothing dictated is ever dropped.
   */
  const fill = useCallback(
    (text: string) => {
      const field = input.current;
      if (!field) return;

      const line = submission(field.value, text, busy);
      if (onSubmit && null !== line) {
        field.value = '';
        onSubmit(line);

        return;
      }

      field.value = merge(field.value, text);
      field.focus();
      field.setSelectionRange(field.value.length, field.value.length);
    },
    [busy, onSubmit],
  );

  const { state, toggle } = useDictation(fill, busy, armMicCue);
  const listening = 'recording' === state.phase;
  // The caller holds the audition from the PRESS, not from the stream: the permission prompt can sit
  // there for seconds, and a mic that opens mid-sentence would hear us finish it.
  const open = 'arming' === state.phase || listening;
  useEffect(() => {
    onListening?.(open);
  }, [open, onListening]);

  const handleSubmit = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== 'Enter' || !onSubmit) return;
    const value = e.currentTarget.value.trim();
    if (!value) return;
    onSubmit(value);
    e.currentTarget.value = '';
  };

  return (
    <div className="at-scene-composer">
      {composerExtras}
      <input
        ref={input}
        className="at-scene-input"
        // No `name` and no autocomplete: a named field is the key the browser stores form history
        // under, and the dropdown of past instructions covered the composer on every focus. Nothing
        // reads this field by name (verified 2026-09-11) — it existed only to be autofilled.
        autoComplete="off"
        aria-label={t.scene.speak}
        placeholder={t.scene.speakPlaceholder}
        onFocus={() => onComposerFocus?.(true)}
        onBlur={() => onComposerFocus?.(false)}
        onKeyDown={handleSubmit}
      />
      {/* Gone for the session once the permission is refused: a browser permission the operator
          declined is an answer, not a control to keep offering. */}
      {!state.unavailable && (
        <button
          type="button"
          className="at-scene-mic"
          data-phase={state.phase}
          aria-label={open ? t.scene.dictating : t.scene.dictate}
          aria-pressed={open}
          disabled={busy || 'transcribing' === state.phase}
          // ONE gesture, latched: press to start, press again to stop (CHOREOGRAPHY § "La dictée",
          // corrected 2026-09-11 — a held control cannot outlive the permission prompt). `onClick`
          // rather than pointer events on purpose: it is the one handler a browser fires for the mouse
          // AND for Space/Enter on a focused button, so the keyboard needs no rule of its own.
          onClick={toggle}
        >
          {'transcribing' === state.phase ? '⋯' : listening ? '⏹' : '⏺'}
        </button>
      )}
    </div>
  );
}
