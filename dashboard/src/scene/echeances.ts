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

export function buildEcheances(due: ForecastDue[], gaps: string[] = [], tasks: PlannedTask[] = []): Echeance[] {
  const openByType = new Map<string, PlannedTask>();
  const doneByType = new Map<string, PlannedTask>();
  for (const t of tasks) {
    if (t.status === 'open') openByType.has(t.type) || openByType.set(t.type, t);
    else if (t.status === 'done') doneByType.has(t.type) || doneByType.set(t.type, t);
  }
  const taskFor = (type: string) => openByType.get(type) ?? doneByType.get(type);

  const out: Echeance[] = [];
  const dueTypes = new Set(due.map((d) => d.type));
  const emitted = new Set<string>();

  for (const d of due) {
    const desc = dueDescriptor(d);
    const label = serviceLabel(d.type);
    const t = taskFor(d.type);
    if (t) {
      emitted.add(d.type);
      out.push({ id: t.id, kind: 'task', type: d.type, label, horizon: desc.horizon, signal: desc.signal, taskId: t.id, done: t.status === 'done', due: d });
    } else {
      out.push({ id: `due:${d.type}`, kind: 'due', type: d.type, label, horizon: desc.horizon, signal: desc.signal, due: d });
    }
  }
  // open/done tasks with no forecast due — a manually-planned service still shows.
  for (const t of tasks) {
    if (t.status === 'dismissed' || dueTypes.has(t.type) || emitted.has(t.type)) continue;
    if (taskFor(t.type)?.id !== t.id) continue;
    emitted.add(t.type);
    out.push({ id: t.id, kind: 'task', type: t.type, label: serviceLabel(t.type), horizon: null, signal: 'green', taskId: t.id, done: t.status === 'done' });
  }
  // coverage gaps not carried by a due.
  for (const g of gaps) {
    if (dueTypes.has(g)) continue;
    out.push({ id: `gap:${g}`, kind: 'gap', type: g, label: serviceLabel(g), horizon: null, signal: 'green' });
  }

  // stable sort, worst-first (JS sort is stable → equal-weight items keep soonest-first forecast order).
  return out.map((e, i) => ({ e, i })).sort((a, b) => weight(b.e) - weight(a.e) || a.i - b.i).map(({ e }) => e);
}
