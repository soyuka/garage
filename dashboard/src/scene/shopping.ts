import { taskTitle } from '../tasks';
import type { Iri, PlannedPart, VehicleBundle } from '../types';

// The shopping list — every reference chosen across the OPEN checklist, grouped by the job it belongs to.
//
// The parts manifest carries ONE `taskId`, so « prochaines pièces à commander ? » — a question that spans
// the checklist — had no surface. Asked on the live Mazda, the agent answered with a single freshly-fetched
// ref for the one task that had none, while the seven references already sitting on three open tasks never
// appeared. Same defect the carnet had before it took keys, and the parc still has: a single-thing surface
// answering a multi-thing question.
//
// Read-only, like the worklist and the carnet: every one of these lines is editable in the rail two hundred
// pixels to the left. This is the cut, not a second editor.

export interface ShoppingGroup {
  taskId: Iri;
  /** the job these references are for — the task's own title, so two engine mounts stay tellable apart. */
  title: string;
  parts: PlannedPart[];
}

export interface ShoppingList {
  groups: ShoppingGroup[];
  taskCount: number;
  partCount: number;
  totalCents: number;
  /** references with no price at all — without this a total reads as complete when it is partial. */
  unpricedCount: number;
}

export function buildShoppingList(bundle: VehicleBundle): ShoppingList {
  const groups: ShoppingGroup[] = (bundle.vehicle.plannedTasks ?? [])
    .filter((t) => t.status === 'open' && (t.parts?.length ?? 0) > 0)
    .map((t) => ({ taskId: t.id, title: taskTitle(t), parts: t.parts }));

  const parts = groups.flatMap((g) => g.parts);
  return {
    groups,
    taskCount: groups.length,
    partCount: parts.length,
    totalCents: parts.reduce((sum, p) => sum + (p.priceCents ?? 0) * (p.qty ?? 1), 0),
    unpricedCount: parts.filter((p) => p.priceCents == null).length,
  };
}
