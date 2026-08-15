import type { RailItem, RailModel } from './rail';

// The WORKLIST — the projection behind `focus_view('tasks', keys)`, the answer to « qu'est-ce que
// j'ai à faire ? ». It is a CUT of the rail, never a second source: the rail stays the persistent
// reading surface on the left, this is the agent's answer projected into the right-edge column
// beside the specs constellation and the carnet.
//
// Two readings, because the pilot asks the question two ways:
//   no keys  → the actionable workload: what is LATE plus what the operator DECIDED to do. The
//              forecast is deliberately excluded — "à faire" is not "un jour".
//   keys     → exactly those services, wherever they sit (late, decided, merely upcoming, or not
//              tracked at all), so a spotlight on `coolant` answers even when coolant is calm.
//
// `missing` is the honesty seam: a key the vehicle carries no échéance for is reported, not silently
// dropped into an empty panel. That was the old failure — `focus_view('tasks', [...])` dimmed the
// scene and rendered nothing, so a bad key and a working panel looked identical.

export interface Worklist {
  items: RailItem[];
  /** requested keys with no échéance on this vehicle — rendered as "non suivi ici", never hidden */
  missing: string[];
}

/** Worst-first across buckets: late → decided → forecast → untracked (each already sorted inside). */
function ordered(model: RailModel): RailItem[] {
  return [...model.todo, ...model.ongoing, ...model.planned, ...model.gaps];
}

export function buildWorklist(model: RailModel, keys: string[] = []): Worklist {
  if (keys.length === 0) {
    return { items: [...model.todo, ...model.ongoing].filter((i) => !i.done), missing: [] };
  }
  const wanted = new Set(keys);
  const items = ordered(model).filter((i) => wanted.has(i.type));
  const found = new Set(items.map((i) => i.type));
  return { items, missing: keys.filter((k) => !found.has(k)) };
}
