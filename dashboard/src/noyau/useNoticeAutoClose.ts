import { useEffect, useRef } from 'react';
import { emptyDwellClock, tickDwell, type DwellClock } from './noticeDwell';
import type { Notice } from './state';

// The impure edge of auto-close. Every RULE lives in noticeDwell.ts; a poll here just re-evaluates the clock and fires
// `onClose` for whatever it names — the same split as voice.ts / useVoice.ts.

/** Fine enough against an 8s dwell without a bespoke timer per card. */
const POLL_MS = 250;

/**
 * `speakingId` is the audition's own `state.speaking?.id` (useVoice's return value) — the one signal
 * this needs from the ear, so a card's own line is never cut off mid-sentence by a fixed timer.
 */
export function useNoticeAutoClose(notices: Notice[], speakingId: string | null, dwell: number, onClose: (id: string) => void): void {
  const clock = useRef<DwellClock>(emptyDwellClock);
  // A ref, not a dependency: `onClose` is a fresh closure every render (it dispatches), and putting it
  // in the effect's deps would tear down and rebuild the interval on every render for no reason.
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    if (notices.length === 0) {
      clock.current = emptyDwellClock; // nothing printed — nothing to leak an anchor for
      return;
    }
    const id = window.setInterval(() => {
      const { clock: next, toClose } = tickDwell(clock.current, notices, speakingId, performance.now(), dwell);
      clock.current = next;
      for (const noticeId of toClose) onCloseRef.current(noticeId);
    }, POLL_MS);
    return () => window.clearInterval(id);
  }, [notices, speakingId, dwell]);
}
