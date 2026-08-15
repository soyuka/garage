import { useEffect, useRef, useState } from 'react';
import { formatSilence } from '../pilot';
import type { NoyauState } from './state';
import './noyau.css';

/**
 * The elapsed-silence read of a quiet pilot run — the datum the veille pilotée state exists to show.
 * A leaf with its own interval so the once-a-second tick re-renders THIS and nothing else: the scene
 * and the memoized Plateau never see it (CHOREOGRAPHY hard rule 6 — cheap idle). `aria-hidden` at the
 * call site: the transition into waiting is announced once on the live line, the stopwatch is not.
 */
function PilotSilence({ since }: { since: number }) {
  const [now, setNow] = useState(() => performance.now());
  useEffect(() => {
    const id = window.setInterval(() => setNow(performance.now()), 1000);
    return () => window.clearInterval(id);
  }, []);
  return <>{formatSilence(Math.max(0, now - since))}</>;
}

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
      data-driver={state.driver ?? undefined}
      data-quiet={state.quiet ? 'true' : undefined}
      data-reduced={reduced ? 'true' : undefined}
    >
      <div className="noyau-narration" aria-live="polite">
        {exiting && (
          <span className="noyau-narration-exit" aria-hidden="true">
            {exiting.text}
          </span>
        )}
        {/* who is at the controls — inside the live region so a screen reader hears it once, when the
            external host takes over, and not again on every step (the chips rule, applied here). */}
        {state.driver === 'pilot' && <span className="noyau-driver">pilote</span>}
        {state.narration && <span key={state.narration}>{state.narration}</span>}
        {/* the run is held but nothing is arriving; the words are announced once, the clock never is */}
        {state.quiet && <span className="noyau-wait"> · en attente</span>}
        {state.quiet && state.lastFrameAt !== null && (
          <span className="noyau-wait-clock" aria-hidden="true">
            <PilotSilence since={state.lastFrameAt} />
          </span>
        )}
      </div>
    </div>
  );
}
