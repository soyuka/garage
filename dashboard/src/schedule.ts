import { serviceLabel } from './labels';
import type { ServiceRule } from './types';

/** French verb for each rule action; mirrors the forecast's ACTION_LABELS. */
const ACTION_LABELS: Record<ServiceRule['action'], string> = {
  change: 'changer',
  replace: 'remplacer',
  clean: 'nettoyer',
  inspect: 'contrôler',
};

/** "hours" reads "h" in French; km/mi stay verbatim. */
function unitLabel(unit: string): string {
  return unit === 'hours' ? 'h' : unit;
}

/** French thousands grouping (15000 → "15 000"), normalising Intl's narrow no-break space. */
function group(value: number): string {
  return value.toLocaleString('fr-FR').replace(/\u202f|\u00a0/g, ' ');
}

/** The rule's verb label for the action select. */
export function actionLabel(action: ServiceRule['action']): string {
  return ACTION_LABELS[action] ?? action;
}

/** The rule title: "Vidange · changer", "Filtre à air · remplacer — cartouche" with a component. */
export function ruleTitle(r: ServiceRule): string {
  const base = serviceLabel(r.type);
  const action = ACTION_LABELS[r.action] ?? r.action;
  let title = `${base} · ${action}`;
  if (r.component) title += ` — ${r.component}`;
  return title;
}

/**
 * The cadence line: a usage interval ("tous les 50 h"), a time interval ("tous les 12 mois"), and/or
 * a break-in first interval ("1ʳᵉ à 5"), joined with a middot. A dash when no cadence is set (the
 * server rejects that on write, but a defensive read should still render).
 */
export function formatCadence(r: ServiceRule): string {
  const parts: string[] = [];
  if (r.everyUsage) parts.push(`tous les ${group(r.everyUsage.value)} ${unitLabel(r.everyUsage.unit)}`);
  if (r.everyMonths != null) parts.push(`tous les ${r.everyMonths} mois`);
  if (r.firstUsageValue != null) parts.push(`1ʳᵉ à ${group(r.firstUsageValue)}`);
  return parts.length ? parts.join(' · ') : '—';
}

/** Whole-month spans fold to years: 12 → "1 an", 24 → "2 ans", 18 stays "18 mois". */
function spanLabel(months: number): string {
  if (months % 12 === 0) {
    const years = months / 12;
    return `${years} an${years > 1 ? 's' : ''}`;
  }
  return `${months} mois`;
}

/** Compact cadence for the gap panel: "tous les 10 000 km / 1 an". Drops the break-in interval —
 *  the gap card only needs the recurring rhythm. Empty string when no cadence is set. */
export function formatCadenceShort(r: ServiceRule): string {
  const parts: string[] = [];
  if (r.everyUsage) parts.push(`${group(r.everyUsage.value)} ${unitLabel(r.everyUsage.unit)}`);
  if (r.everyMonths != null) parts.push(spanLabel(r.everyMonths));
  return parts.length ? `tous les ${parts.join(' / ')}` : '';
}
