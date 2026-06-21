import { useEffect } from 'react';

interface Props {
  queue: string[];
  busy: boolean;
  onAdvance: () => void;
}

/**
 * Single-line loading presenter. Instead of stacking a toast per agent step / live event, the
 * messages are queued and shown one at a time on a ~2.2s metronome: each tick promotes the next
 * queued sentence, and the last sentence is held while the turn is still running, then cleared once
 * it settles. The glow itself is NOT animated here — it comes from the feed's shared --mpa-pulse
 * (see app.css), so the dot and this text stay frame-locked no matter when this mounts. (Advancing
 * used to ride hb-text's own animation iteration, which both desynced the glow and forced a remount
 * per sentence.)
 */
export function StatusHeartbeat({ queue, busy, onAdvance }: Props) {
  useEffect(() => {
    // Advance to the next sentence each beat; hold the last one while the turn is still running.
    if (queue.length > 1 || (queue.length === 1 && !busy)) {
      const id = setInterval(onAdvance, 2200);
      return () => clearInterval(id);
    }
  }, [queue.length, busy, onAdvance]);

  if (!busy && queue.length === 0) return null;
  const text = queue[0] ?? 'Réflexion en cours…';

  return (
    <span className="heartbeat" aria-live="polite">
      <span className="hb-dot" />
      <span className="hb-text">{text}</span>
    </span>
  );
}
