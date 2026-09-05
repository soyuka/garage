import type { Focus } from '../focus';

// Which station's BODY belongs on the disc, given the two legend drawers + the current focus
// (CHOREOGRAPHY § "Stations under both drivers"). A station now "belongs to the dataset, not to the
// surface that opened it" — the agent's `focusView`/`showHistory`/`showFact` raise the same bodies a
// deterministic chip does. Precedence, in order: a docked LEGEND (the full Carnet / Fiche drawer) wins
// outright; among focus-driven stations, `nodes` (a fetched, already-formatted surface — fluid
// capacities, the carte grise) is checked before the stored-facts/carnet cases, so a focus carrying
// `nodes` never falls through to the wrong pose. Pure: callers still gate on the layout being non-empty
// (an empty cut is no station, same as the fluids row) — this only names WHICH station a focus targets.
export type StationKind = 'history' | 'data' | 'fluids' | 'carnet' | 'specs' | null;

export function stationFor({
  historyOpen,
  factsDrawerOpen,
  focus,
}: {
  historyOpen: boolean;
  factsDrawerOpen: boolean;
  focus: Focus | null | undefined;
}): StationKind {
  if (historyOpen) return 'history';
  if (factsDrawerOpen) return 'data';
  if (!focus) return null;
  if (focus.panel === 'specs' && focus.nodes) return 'fluids';
  if (focus.panel === 'carnet') return 'carnet';
  if (focus.panel === 'specs') return 'specs';
  return null;
}
