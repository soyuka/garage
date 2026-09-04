import type { Notice } from './state';

// The auto-close decision (CHOREOGRAPHY § "The notice stack — an answer that lands, then clears",
// "Reversed 2026-09-04 — the cards now auto-close"). Pure and clock-injected, same shape as voice.ts:
// the impure edge (a polling effect in Atelier.tsx) owns nothing but a timer and the `dismissNotice`
// dispatch it fires for whatever `toClose` names.
//
// The one rule that matters: **a dismissal is a cancellation** (voice.ts `follow`), so a fixed timer
// counted from the card's ARRIVAL could fire while its own line is still being spoken and cut it off
// mid-sentence. So the dwell does not count from arrival — it counts from the moment the card is first
// observed NOT being spoken, whether that is "immediately" (muted, or never reached the front of the
// voice queue) or "once its sentence ends" (it was on the lips a moment ago). Once latched, an anchor
// never moves — a card that starts speaking again (it cannot: `voice.ts` only speaks a line once) would
// otherwise need to un-latch, which this design never has to consider.

export interface DwellClock {
  /** notice id → the timestamp its read dwell counts FROM. Absent while still on the lips. */
  anchors: Record<string, number>;
}

export const emptyDwellClock: DwellClock = { anchors: {} };

/**
 * One tick: given which card (if any) is currently being spoken, latch an anchor for every OTHER
 * printed card that does not have one yet, and report which cards have dwelled past `dwell`.
 *
 * Rebuilding `anchors` from `notices` on every call is what makes a vanished card's anchor disappear
 * for free — "vu", a cap eviction or a voice cancellation all mean the card is simply no longer in the
 * list, so there is nothing to prune separately and nothing left to leak.
 */
export function tickDwell(
  clock: DwellClock,
  notices: Notice[],
  speakingId: string | null,
  now: number,
  dwell: number,
): { clock: DwellClock; toClose: string[] } {
  const anchors: Record<string, number> = {};
  const toClose: string[] = [];

  for (const n of notices) {
    if (n.id === speakingId) continue; // still on the lips — no anchor, never closes

    const anchor = clock.anchors[n.id] ?? now;
    anchors[n.id] = anchor;
    if (now - anchor >= dwell) toClose.push(n.id);
  }

  return { clock: { anchors }, toClose };
}
