// The opening ticker (CHOREOGRAPHY § "The opening — the workshop speaks first"). Pure decision,
// clock-free: given the settled selection before and after, it says whether a greeting line is due
// and for what. The impure edge (Atelier.tsx) only tracks `previous` and dispatches the result.

export type Greeting = { kind: 'none' } | { kind: 'vehicle'; name: string };

/**
 * `previous` is `undefined` for "nothing greeted yet" (so the very first settle always greets) and
 * `null` for "settled on no vehicle". `next` is only ever a settled value — the caller must withhold
 * a call while a selection is still resolving (e.g. a URL hash awaiting the vehicle collection), or
 * the transient null would fire the "no vehicle" line before the real one lands.
 */
export function greetingForSelection(
  previous: string | null | undefined,
  next: string | null,
  vehicleName: string | undefined,
  isBusy: boolean,
): Greeting | null {
  if (previous === next) return null; // no change — never re-greet a repaint or the same vehicle
  if (isBusy) return null; // the ticker belongs to the turn while one is working
  if (next === null) return { kind: 'none' };

  // A car whose make AND model are both blank would greet « sélectionnée. Que souhaitez-vous
  // savoir ? » — headless, and worse aloud than on screen. Silence is the degraded mode everywhere
  // else in this app (no vendor, no budget, no gesture → silence), so it is the honest one here too.
  return vehicleName ? { kind: 'vehicle', name: vehicleName } : null;
}
