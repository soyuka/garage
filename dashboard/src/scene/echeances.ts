import { dueDescriptor } from '../derive';
import { serviceLabel } from '../labels';
import type { ForecastDue, PlannedTask, Signal } from '../types';

// The échéances as a FLAT, severity-sorted list — one item per service, NOT grouped by body region (the
// grouping buried the urgency). A due binds to its open/done task to become checkable; an unplanned due is
// a prediction (plan it); a coverage gap is a dashed "non suivi". This is the shared ordering both the
// ring pins and the left rail render; `rail.ts` wraps it to stamp the pin/row index. Stays pure data.

export interface Echeance {
  id: string;
  kind: 'task' | 'due' | 'gap';
  type: string;
  label: string;
  horizon: string | null;
  signal: Signal;
  taskId?: string;
  done?: boolean;
  due?: ForecastDue;
}

const RANK: Record<Signal, number> = { green: 1, amber: 2, red: 3 };
/** Worst-first sort weight: red > amber > green > done > gap. */
function weight(e: Echeance): number {
  if (e.kind === 'gap') return -1;
  if (e.done) return 0;
  return RANK[e.signal];
}

/** A task's own words when it has them ("support moteur gauche"), else the service label — the only
 *  thing telling two same-type jobs apart. */
function taskLabel(t: PlannedTask): string {
  const own = t.label?.trim();
  return own ? own : serviceLabel(t.type);
}

export function buildEcheances(due: ForecastDue[], gaps: string[] = [], tasks: PlannedTask[] = []): Echeance[] {
  const openByType = new Map<string, PlannedTask>();
  const doneByType = new Map<string, PlannedTask>();
  for (const t of tasks) {
    if (t.status === 'open') openByType.has(t.type) || openByType.set(t.type, t);
    else if (t.status === 'done') doneByType.has(t.type) || doneByType.set(t.type, t);
  }
  const taskFor = (type: string) => openByType.get(type) ?? doneByType.get(type);

  const out: Echeance[] = [];
  // Emitted TASKS are tracked by id, never by type: two open tasks can share a type and still be two
  // distinct jobs (a rear engine mount and a left one). Keying this by type dropped the second job
  // from every surface — it existed in the garage and appeared nowhere.
  const emittedTasks = new Set<string>();
  const dueTypes = new Set(due.map((d) => d.type));

  for (const d of due) {
    const desc = dueDescriptor(d);
    const t = taskFor(d.type);
    if (t) {
      emittedTasks.add(t.id);
      out.push({ id: t.id, kind: 'task', type: d.type, label: taskLabel(t), horizon: desc.horizon, signal: desc.signal, taskId: t.id, done: t.status === 'done', due: d });
    } else {
      out.push({ id: `due:${d.type}`, kind: 'due', type: d.type, label: serviceLabel(d.type), horizon: desc.horizon, signal: desc.signal, due: d });
    }
  }
  // Every remaining task — a manually-planned service with no forecast, and any SIBLING of a task a
  // due already bound above.
  for (const t of tasks) {
    if (t.status === 'dismissed' || emittedTasks.has(t.id)) continue;
    emittedTasks.add(t.id);
    out.push({ id: t.id, kind: 'task', type: t.type, label: taskLabel(t), horizon: null, signal: 'green', taskId: t.id, done: t.status === 'done' });
  }
  // coverage gaps not carried by a due.
  for (const g of gaps) {
    if (dueTypes.has(g)) continue;
    out.push({ id: `gap:${g}`, kind: 'gap', type: g, label: serviceLabel(g), horizon: null, signal: 'green' });
  }

  // stable sort, worst-first (JS sort is stable → equal-weight items keep soonest-first forecast order).
  return out.map((e, i) => ({ e, i })).sort((a, b) => weight(b.e) - weight(a.e) || a.i - b.i).map(({ e }) => e);
}
