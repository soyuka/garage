// Pure geometry for the departure ghost (CHOREOGRAPHY § "One stage — the lot and the podium", row 1).
// A lot card is clicked: a ghost of that card drives forward to the stage centre while growing. This
// is the ONLY math in that motion — everything else (mount timing, the CSS transition) lives in
// Departure.tsx.

export interface Viewport {
  width: number;
  height: number;
}

export interface DepartureTransform {
  /** px to translate the ghost's own centre so it lands on the viewport centre. */
  translateX: number;
  translateY: number;
  scale: number;
}

/** The default growth at the stage centre (CHOREOGRAPHY: "grows (~1.6×)"). */
export const DEPARTURE_SCALE = 1.6;

/**
 * The transform that drives the ghost, fixed at `rect`, to the viewport centre while growing to
 * `scale`. `translate` is computed from CENTRE to CENTRE (not corner to corner) so the growth reads as
 * the card driving toward the middle of the stage, not sliding off toward a corner.
 */
export function departureTransform(rect: DOMRect, viewport: Viewport, scale: number = DEPARTURE_SCALE): DepartureTransform {
  const rectCenterX = rect.left + rect.width / 2;
  const rectCenterY = rect.top + rect.height / 2;
  const viewportCenterX = viewport.width / 2;
  const viewportCenterY = viewport.height / 2;
  return {
    translateX: viewportCenterX - rectCenterX,
    translateY: viewportCenterY - rectCenterY,
    scale,
  };
}
