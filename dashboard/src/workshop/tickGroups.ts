import type { Tick } from './kmTrack';

export interface TickGroup {
  /** The first tick's id: stable while the group holds. */
  id: string;
  value: number;
  state: Tick['state'];
  ticks: Tick[];
}

/** Sorted ticks at the same reading and state are one mark on the rail: seven jobs due at 90 000 km are one event. */
export function groupTicks(ticks: Tick[]): TickGroup[] {
  const out: TickGroup[] = [];
  for (const tick of ticks) {
    const last = out[out.length - 1];
    if (last && last.value === tick.value && last.state === tick.state) last.ticks.push(tick);
    else out.push({ id: tick.id, value: tick.value, state: tick.state, ticks: [tick] });
  }
  return out;
}

/** A click on a group walks its jobs: the first, then each next one, wrapping. */
export function nextInGroup(group: TickGroup, selectedId: string | null): Tick {
  const at = group.ticks.findIndex((t) => t.id === selectedId);
  return group.ticks[(at + 1) % group.ticks.length];
}
