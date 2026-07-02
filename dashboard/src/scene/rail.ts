import { buildEcheances, type Echeance } from './echeances';
import type { ForecastDue, PlannedTask } from '../types';

// The rail model — the SINGLE ordered list that drives BOTH the ring pins and the persistent left rail
// ("Entretien"). It reuses buildEcheances' merge + severity sort verbatim (red → amber → green → done →
// gap), then stamps each item with the index its pin and its rail row share. A gap has no ring pin, so it
// carries no index (null); the pinned items therefore keep a contiguous 1..k numbering. A task item also
// carries its full PlannedTask so the rail can render the editable/checkable row without a second lookup.

export interface RailItem extends Echeance {
  /** 1-based index shared by the ring pin and the rail row. Null on a gap (no pin, no index badge). */
  index: number | null;
  /** the full task backing a `task`-kind item — drives the checkbox + inline editor. */
  task?: PlannedTask;
}

export function buildRail(due: ForecastDue[], gaps: string[] = [], tasks: PlannedTask[] = []): RailItem[] {
  const byId = new Map(tasks.map((t) => [t.id, t]));
  let pin = 0;
  return buildEcheances(due, gaps, tasks).map((e) => ({
    ...e,
    index: e.kind === 'gap' ? null : ++pin,
    task: e.kind === 'task' && e.taskId ? byId.get(e.taskId) : undefined,
  }));
}
