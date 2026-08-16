import { formatReading } from './format';
import type { Locale } from './i18n/locale';
import { messages } from './i18n/messages';
import { serviceLabel } from './labels';
import { cadenceUnit } from './units';
import type { ServiceAction, ServiceRule } from './types';

// Pure formatting for the service-schedule editor (#11) — the recurring rules behind the forecast.
// French via format.ts (THIN/NBSP), never toLocaleString. Rebuilt fresh from the contract.

export function actionVerb(action: ServiceAction | null, locale: Locale): string {
  return action ? messages(locale).schedule.verb[action] : '';
}

/** "<service label> · <verb>" with an optional " — <component>" suffix. */
export function ruleTitle(rule: ServiceRule, locale: Locale): string {
  const verb = actionVerb(rule.action, locale);
  const label = serviceLabel(rule.type, locale);
  const head = verb ? `${label} · ${verb}` : label;
  return rule.component ? messages(locale).schedule.ruleTitle(head, rule.component) : head;
}

/** "tous les 10 000 km · tous les 12 mois · d'abord à 5 h" — the parts the rule actually carries. */
export function formatCadence(rule: ServiceRule, locale: Locale): string {
  const t = messages(locale);
  const parts: string[] = [];
  if (rule.everyUsage) parts.push(t.schedule.everyUsage(formatReading(rule.everyUsage.value, cadenceUnit(rule.everyUsage.unit), locale)));
  if (rule.everyMonths != null) parts.push(t.schedule.everyMonths(rule.everyMonths));
  if (rule.firstUsageValue != null) {
    const unit = rule.everyUsage ? cadenceUnit(rule.everyUsage.unit) : undefined;
    parts.push(t.schedule.firstAt(formatReading(rule.firstUsageValue, unit, locale)));
  }
  return parts.join(' · ') || t.schedule.noCadence;
}
