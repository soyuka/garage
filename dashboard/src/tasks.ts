import { formatDate, formatReading } from './format';
import type { Locale } from './i18n/locale';
import { messages } from './i18n/messages';
import { serviceLabel } from './labels';
import type { PlannedTask } from './types';

// Pure derivations for the open-checklist surface (#10). Rebuilt fresh from the contract (the old
// VehicleDetail is reference only). The title, the milestone a task is pegged to, and the bucketing
// that groups the checklist by milestone — all honest French via format.ts (no toLocaleString).

// Words that carry no identity in a French service label, so they must not decide whether a custom
// label already names its service ("Plaquettes de frein" vs "plaquettes frein avant").
const FILLER = new Set(['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'a', 'au', 'aux', 'en', 'et']);

/** Accent- and punctuation-insensitive significant words. */
function words(text: string): string[] {
  return text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .split(/[^a-z0-9]+/)
    .filter((w) => w !== '' && !FILLER.has(w));
}

/**
 * "<service label>" or "<service label> — <custom label>" — but never both when the custom label
 * ALREADY names the service. The prefix exists to name a service the operator's words leave implicit;
 * when their words cover it, repeating it says one thing twice and costs three lines of rail.
 */
export function taskTitle(task: PlannedTask, locale: Locale): string {
  const base = serviceLabel(task.type, locale);
  if (!task.label) return base;
  const own = new Set(words(task.label));
  const covered = words(base).every((w) => own.has(w));
  return covered ? task.label : messages(locale).task.titleWithLabel(base, task.label);
}

export interface Milestone {
  key: string;
  label: string;
  tasks: PlannedTask[];
}

/** The milestone a task is pegged to: its usage target wins, else its due date, else none. */
export function milestoneOf(task: PlannedTask, locale: Locale): { key: string; label: string } {
  const t = messages(locale);
  if (task.target) {
    const unit = task.target.unit ?? '';
    const reading = formatReading(task.target.value, unit || undefined, locale);
    return { key: `u:${task.target.value}:${unit}`, label: t.task.atUsage(reading) };
  }
  if (task.dueDate) {
    return { key: `d:${task.dueDate}`, label: t.task.beforeDate(formatDate(task.dueDate, locale)) };
  }
  return { key: 'none', label: t.forecast.noDueDate };
}

/** Bucket the checklist by milestone, preserving the backend order (first-appearance order kept). */
export function groupByMilestone(tasks: PlannedTask[], locale: Locale): Milestone[] {
  const groups: Milestone[] = [];
  const byKey = new Map<string, Milestone>();
  for (const task of tasks) {
    const { key, label } = milestoneOf(task, locale);
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
