// Pure geometry for the departure ghost (CHOREOGRAPHY § "One stage — the lot and the podium", row 1).
// A lot card is clicked: its silhouette drives forward to the hero podium while growing. This
// is the ONLY math in that motion — everything else (mount timing, the CSS transition) lives in
// Departure.tsx.

export interface Viewport {
  width: number;
  height: number;
}

export interface DepartureTransform {
  /** px to translate the ghost's own centre so it lands on the hero podium. */
  translateX: number;
  translateY: number;
  scale: number;
}

/** The default growth at the hero podium (CHOREOGRAPHY: "grows (~1.6×)"). */
export const DEPARTURE_SCALE = 1.6;

/** The hero's podium sits in the lower centre of its full-bleed stage. */
export const PODIUM_TARGET = { x: 0.5, y: 0.62 } as const;

/**
 * The transform that drives the ghost, fixed at `rect`, to the named podium target while growing to
 * `scale`. `translate` is computed from centre to centre, so it does not slide toward a corner.
 */
export function departureTransform(rect: DOMRect, viewport: Viewport, scale: number = DEPARTURE_SCALE): DepartureTransform {
  const rectCenterX = rect.left + rect.width / 2;
  const rectCenterY = rect.top + rect.height / 2;
  const podiumX = viewport.width * PODIUM_TARGET.x;
  const podiumY = viewport.height * PODIUM_TARGET.y;
  return {
    translateX: podiumX - rectCenterX,
    translateY: podiumY - rectCenterY,
    scale,
  };
}
