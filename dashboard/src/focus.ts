import type { PanelKind, SpecNode, UIAction } from './types';

// SpecNode moved to types.ts when it went ON THE WIRE (show_fact's `nodes`) — it is a contract shape
// now, not a client-local one. Re-exported so the surfaces that build them keep their import.
export type { SpecNode } from './types';

// The unified focus state behind the single swappable slot. The agent drives it via the `focusView`
// intent (and the legacy `showFact`, kept as the specs-only subset); a car-pin click or a deterministic
// chip drives the same state locally. `keys` are service-type / fact keys to spotlight (lit on the car +
// etched in the panel). `nodes`, when set, ARE the constellation content (a fetched surface projected
// through the same specs mechanism), bypassing the `keys` filter over the vehicle's stored facts.
export interface Focus {
  panel: PanelKind;
  keys: string[];
  title?: string;
  nodes?: SpecNode[];
}

/** Maps an agent UIAction to a focus state, or null when the action does not change focus. */
export function focusForAction(action: UIAction): Focus | null {
  if (action.type === 'focusView') {
    return { panel: action.panel, keys: action.keys ?? [] };
  }
  if (action.type === 'showFact') {
    const focus: Focus = { panel: 'specs', keys: action.keys, title: action.title };
    if (action.nodes) focus.nodes = action.nodes;
    return focus;
  }
  if (action.type === 'showHistory') {
    return { panel: 'carnet', keys: [] };
  }
  return null;
}
