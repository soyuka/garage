import { useCallback, useEffect, useRef } from 'react';
import { BURST_INIT, burstReduce, type BurstState } from '../pilot';
import { BURST_MS } from '../tokens/motion';
import type { BodyRegion } from './anchors';

/**
 * The burst coalescer's React binding: request a region as often as the frames name one, fire `onFire`
 * once per burst, with the region the burst ENDED on.
 *
 * A pilot session lands its intents in bursts (docs/pilot.md), and every intent that resolves to a body
 * region asks for the earned camera. Four in 300ms used to mean four dollies, each re-easing from
 * wherever the last had reached — a camera whipping between regions and arriving nowhere. Coalescing
 * shows the operator where the attention landed instead of the route it took. Only the camera waits:
 * the panels those same frames open are not on this path (hard rule 1 — never gate an arrival).
 *
 * The window is a trailing debounce, so a burst extends itself while frames keep coming. The reducer
 * re-checks the window on flush, so a timer left over from a superseded burst fires nothing.
 */
export function useRegionBurst(onFire: (region: BodyRegion) => void): (region: BodyRegion) => void {
  const burst = useRef<BurstState>(BURST_INIT);
  const timer = useRef<number | null>(null);
  const fire = useRef(onFire);
  fire.current = onFire;

  useEffect(
    () => () => {
      if (timer.current != null) window.clearTimeout(timer.current);
    },
    [],
  );

  return useCallback((region: BodyRegion) => {
    burst.current = burstReduce(burst.current, { type: 'target', region, at: performance.now() }).state;
    if (timer.current != null) window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => {
      const out = burstReduce(burst.current, { type: 'flush', at: performance.now() });
      burst.current = out.state;
      if (out.fire) fire.current(out.fire);
    }, BURST_MS);
  }, []);
}
