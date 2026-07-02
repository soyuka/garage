import type { PanelKind, UIAction } from './types';

/**
 * A projected constellation node — the shape the spec constellation renders when the focus carries its
 * OWN nodes (a fetched surface: fluid capacities, the SIV carte grise) rather than filtering the
 * vehicle's stored facts. `info` is an optional secondary spec line (e.g. "ACEA E3|E4|E5"). Read-only.
 */
export interface SpecNode {
  key: string;
  label: string;
  value: string;
  verified: boolean;
  info?: string;
}

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

/** Which content the single swappable slot shows. Default (no focus) = the specs at-a-glance panel. */
export function slotPanel(focus: Focus | null): PanelKind {
  return focus?.panel ?? 'specs';
}

/** Maps an agent UIAction to a focus state, or null when the action does not change focus. */
export function focusForAction(action: UIAction): Focus | null {
  if (action.type === 'focusView') {
    return { panel: action.panel, keys: action.keys ?? [] };
  }
  if (action.type === 'showFact') {
    return { panel: 'specs', keys: action.keys, title: action.title };
  }
  if (action.type === 'showHistory') {
    return { panel: 'carnet', keys: [] };
  }
  return null;
}
