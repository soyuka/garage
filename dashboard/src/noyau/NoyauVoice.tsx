import { useEffect, useRef, useState } from 'react';
import type { NoyauState } from './state';
import './noyau.css';

/**
 * Le Noyau's voice — the single narration line that changes as the agent works. Split out of the orb
 * (Noyau.tsx) so the console can dock the orb in a fixed socket and swap this line into the chip-rail
 * slot for the duration of an action (no extra band → no layout shift). Shares the `.noyau-stage` root
 * class with the orb for `--nx-mono`; it reads none of the phase-driven filament custom properties.
 */
export function NoyauVoice({ state, reduced = false }: { state: NoyauState; reduced?: boolean }) {
  // Narration crossfade: the outgoing line fades up-and-out (aria-hidden, so it is never
  // re-announced) while the incoming line etches in on the aria-live span — one motion grammar
  // for both. Reduced motion → instant swap: no exit line is ever staged.
  const [exiting, setExiting] = useState<{ id: number; text: string } | null>(null);
  const prevNarration = useRef(state.narration);
  const exitSeq = useRef(0);
  useEffect(() => {
    const prev = prevNarration.current;
    prevNarration.current = state.narration;
    if (reduced || prev === state.narration || !prev) return;
    const id = ++exitSeq.current;
    setExiting({ id, text: prev });
    const timeout = window.setTimeout(() => setExiting((e) => (e?.id === id ? null : e)), 360);
    return () => window.clearTimeout(timeout);
  }, [state.narration, reduced]);

  return (
    <div
      className="noyau-stage noyau-voice"
      data-phase={state.phase}
      data-result={state.result ?? undefined}
      data-reduced={reduced ? 'true' : undefined}
    >
      <div className="noyau-narration" aria-live="polite">
        {exiting && (
          <span className="noyau-narration-exit" aria-hidden="true">
            {exiting.text}
          </span>
        )}
        {state.narration && <span key={state.narration}>{state.narration}</span>}
      </div>
    </div>
  );
}
