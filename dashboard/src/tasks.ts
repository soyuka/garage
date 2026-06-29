import { formatDate, formatReading } from './format';
import { serviceLabel } from './labels';
import type { PlannedTask } from './types';

// Pure derivations for the open-checklist surface (#10). Rebuilt fresh from the contract (the old
// VehicleDetail is reference only). The title, the milestone a task is pegged to, and the bucketing
// that groups the checklist by milestone — all honest French via format.ts (no toLocaleString).

/** "<service label>" or "<service label> — <custom label>". */
export function taskTitle(task: PlannedTask): string {
  const base = serviceLabel(task.type);
  return task.label ? `${base} — ${task.label}` : base;
}

export interface Milestone {
  key: string;
  label: string;
  tasks: PlannedTask[];
}

/** The milestone a task is pegged to: its usage target wins, else its due date, else none. */
export function milestoneOf(task: PlannedTask): { key: string; label: string } {
  if (task.target) {
    const unit = task.target.unit ?? '';
    return { key: `u:${task.target.value}:${unit}`, label: `À ${formatReading(task.target.value, unit || undefined)}` };
  }
  if (task.dueDate) {
    return { key: `d:${task.dueDate}`, label: `Avant le ${formatDate(task.dueDate)}` };
  }
  return { key: 'none', label: 'Sans échéance' };
}

/** Bucket the checklist by milestone, preserving the backend order (first-appearance order kept). */
export function groupByMilestone(tasks: PlannedTask[]): Milestone[] {
  const groups: Milestone[] = [];
  const byKey = new Map<string, Milestone>();
  for (const task of tasks) {
    const { key, label } = milestoneOf(task);
    let group = byKey.get(key);
    if (!group) {
      group = { key, label, tasks: [] };
      byKey.set(key, group);
      groups.push(group);
    }
    group.tasks.push(task);
  }
  return groups;
}
