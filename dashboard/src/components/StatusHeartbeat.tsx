interface Props {
  queue: string[];
  busy: boolean;
  onAdvance: () => void;
}

/**
 * Single-line loading presenter. Instead of stacking a toast per agent step / live event, the
 * messages are queued and shown one at a time: the current sentence glows with a heartbeat pulse,
 * and on each completed beat the next queued sentence takes over. The last sentence is held while
 * the turn is still running, then cleared once it settles.
 */
export function StatusHeartbeat({ queue, busy, onAdvance }: Props) {
  if (!busy && queue.length === 0) return null;
  const text = queue[0] ?? 'Réflexion en cours…';

  const onBeat = () => {
    // Advance to the next sentence; hold the last one while the turn is still running.
    if (queue.length > 1 || (queue.length === 1 && !busy)) onAdvance();
  };

  return (
    <span className="heartbeat" aria-live="polite">
      <span className="hb-dot" />
      <span key={text} className="hb-text" onAnimationIteration={onBeat}>
        {text}
      </span>
    </span>
  );
}
