import { formatReading, NBSP } from './format';
import { serviceLabel } from './labels';
import type { ServiceAction, ServiceRule } from './types';

// Pure formatting for the service-schedule editor (#11) — the recurring rules behind the forecast.
// French via format.ts (THIN/NBSP), never toLocaleString. Rebuilt fresh from the contract.

const ACTION_VERB: Record<ServiceAction, string> = {
  change: 'changer',
  replace: 'remplacer',
  clean: 'nettoyer',
  inspect: 'inspecter',
};

export function actionVerb(action: ServiceAction | null): string {
  return action ? ACTION_VERB[action] : '';
}

/** "<service label> · <verb>" with an optional " — <component>" suffix. */
export function ruleTitle(rule: ServiceRule): string {
  const verb = actionVerb(rule.action);
  const head = verb ? `${serviceLabel(rule.type)} · ${verb}` : serviceLabel(rule.type);
  return rule.component ? `${head} — ${rule.component}` : head;
}

// Engine-hours is spelled `hours` on the maintenance axis but reads better as the short `h` in a cadence.
function cadenceUnit(unit: string): string {
  return unit === 'hours' ? 'h' : unit;
}

/** "tous les 10 000 km · tous les 12 mois · d'abord à 5 h" — the parts the rule actually carries. */
export function formatCadence(rule: ServiceRule): string {
  const parts: string[] = [];
  if (rule.everyUsage) parts.push(`tous les ${formatReading(rule.everyUsage.value, cadenceUnit(rule.everyUsage.unit))}`);
  if (rule.everyMonths != null) parts.push(`tous les ${rule.everyMonths}${NBSP}mois`);
  if (rule.firstUsageValue != null) parts.push(`d’abord à ${formatReading(rule.firstUsageValue, rule.everyUsage ? cadenceUnit(rule.everyUsage.unit) : undefined)}`);
  return parts.join(' · ') || 'cadence non définie';
}
