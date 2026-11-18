export type Lane = 'up' | 'down';

export interface LabelBox {
  id: string;
  /** Tick position in px along the rail. */
  x: number;
  width: number;
  prefer: Lane;
  /** Anchored to the left of its tick (near the rail's right end). */
  right: boolean;
  /** Placed before lower priorities; ties keep their order. */
  priority?: number;
}

export interface Reserved {
  lane: Lane;
  from: number;
  to: number;
}

const GAP = 6;
// Matches the label's 4px offset from its tick in workshop.css (.w-tick-label).
const NUDGE = 4;

function span(l: LabelBox): { from: number; to: number } {
  return l.right ? { from: l.x - l.width + NUDGE, to: l.x + NUDGE } : { from: l.x - NUDGE, to: l.x - NUDGE + l.width };
}

/** Each label goes to its preferred lane, else the other one, else it is dropped (tick only). */
export function placeLabels(labels: LabelBox[], reserved: Reserved[]): Record<string, Lane | null> {
  const taken: Reserved[] = [...reserved];
  const out: Record<string, Lane | null> = {};
  const free = (lane: Lane, from: number, to: number) => !taken.some((r) => r.lane === lane && from < r.to + GAP && to + GAP > r.from);
  const ordered = labels.map((l, i) => ({ l, i })).sort((a, b) => (b.l.priority ?? 0) - (a.l.priority ?? 0) || a.i - b.i);
  for (const { l } of ordered) {
    const { from, to } = span(l);
    const other: Lane = l.prefer === 'up' ? 'down' : 'up';
    const lane = free(l.prefer, from, to) ? l.prefer : free(other, from, to) ? other : null;
    out[l.id] = lane;
    if (lane) taken.push({ lane, from, to });
  }
  return out;
}
