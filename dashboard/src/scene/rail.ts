import { buildEcheances, type Echeance } from './echeances';
import type { ForecastDue, PlannedTask } from '../types';

// The rail model — the operator's THREE workload buckets, built over buildEcheances' merge + severity
// sort (kept verbatim inside each bucket):
//   "À faire"  = anything actually LATE (an overdue due, planned or not, as long as it isn't done)
//   "En cours" = work the operator DECIDED to do: any open task not already late. It is intent, not
//                prediction, so it outranks the forecast — a dateless task used to sort green and sink
//                to the bottom of "Planifié", under services the schedule merely guesses at.
//   "Planifié" = the forecast and what is already handled (upcoming dues with no task, done tasks).
// Gaps ("non suivi") sit apart. This is a status cut, not a severity gradient. A task item carries its
// full PlannedTask so the rail renders the editable/checkable row without a second lookup.

export interface RailItem extends Echeance {
  /** the full task backing a `task`-kind item — drives the checkbox + inline editor. */
  task?: PlannedTask;
}

export interface RailModel {
  todo: RailItem[];
  ongoing: RailItem[];
  planned: RailItem[];
  gaps: RailItem[];
}

export function buildRail(due: ForecastDue[], gaps: string[] = [], tasks: PlannedTask[] = []): RailModel {
  const byId = new Map(tasks.map((t) => [t.id, t]));
  const model: RailModel = { todo: [], ongoing: [], planned: [], gaps: [] };
  for (const e of buildEcheances(due, gaps, tasks)) {
    const item: RailItem = { ...e, task: e.kind === 'task' && e.taskId ? byId.get(e.taskId) : undefined };
    if (e.kind === 'gap') model.gaps.push(item);
    else if (e.due?.overdue && !e.done) model.todo.push(item);
    else if (e.kind === 'task' && !e.done) model.ongoing.push(item);
    else model.planned.push(item);
  }
  return model;
}
