import { buildEcheances, type Echeance } from './echeances';
import type { ForecastDue, PlannedTask } from '../types';

// The rail model — the operator's TWO workload buckets, built over buildEcheances' merge + severity sort
// (kept verbatim inside each bucket): "À faire" = anything actually LATE (an overdue due, planned or not,
// as long as it isn't done) and "Planifié" = everything coming or already handled (upcoming dues, open
// tasks without a late forecast, done tasks). Gaps ("non suivi") sit apart. This is a status cut, not a
// severity gradient — late is a workload bucket, not a position on a scale. A task item carries its full
// PlannedTask so the rail renders the editable/checkable row without a second lookup.

export interface RailItem extends Echeance {
  /** the full task backing a `task`-kind item — drives the checkbox + inline editor. */
  task?: PlannedTask;
}

export interface RailModel {
  todo: RailItem[];
  planned: RailItem[];
  gaps: RailItem[];
}

export function buildRail(due: ForecastDue[], gaps: string[] = [], tasks: PlannedTask[] = []): RailModel {
  const byId = new Map(tasks.map((t) => [t.id, t]));
  const model: RailModel = { todo: [], planned: [], gaps: [] };
  for (const e of buildEcheances(due, gaps, tasks)) {
    const item: RailItem = { ...e, task: e.kind === 'task' && e.taskId ? byId.get(e.taskId) : undefined };
    if (e.kind === 'gap') model.gaps.push(item);
    else if (e.due?.overdue && !e.done) model.todo.push(item);
    else model.planned.push(item);
  }
  return model;
}
