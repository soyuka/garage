import type { PanelKind, UIAction } from './types';

// The unified focus state behind the single swappable slot. The agent drives it via the `focusView`
// intent (and the legacy `showFact`, kept as the specs-only subset); a car-pin click drives the same
// state locally. `keys` are service-type / fact keys to spotlight (lit on the car + etched in the panel).
export interface Focus {
  panel: PanelKind;
  keys: string[];
  title?: string;
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
  return null;
}
