import { formatReading } from './format';
import type { Locale } from './i18n/locale';
import { messages } from './i18n/messages';
import { serviceLabel } from './labels';
import { cadenceUnit } from './units';
import type { ServiceAction, ServiceRule } from './types';

// Pure formatting for the service-schedule editor (#11) — the recurring rules behind the forecast.
// French via format.ts (THIN/NBSP), never toLocaleString. Rebuilt fresh from the contract.

/**
 * The triple that NAMES a cadence — type + action + component. A `ServiceRule` and a `ForecastDue`
 * both carry it, and the backend keys the schedule on exactly these three
 * (`ServiceInterval::key()`), so one type can legitimately hold several cadences.
 */
export interface NamedRule {
  type: string;
  action?: ServiceAction | null;
  component?: string | null;
}

export function actionVerb(action: ServiceAction | null, locale: Locale): string {
  return action ? messages(locale).schedule.verb[action] : '';
}

/** Mirrors the backend's `ServiceInterval::key()` — `spark_plug:inspect` ≠ `spark_plug:replace`. */
export function ruleKey(rule: NamedRule): string {
  return [rule.type, rule.action ?? '', rule.component ?? ''].join(':');
}

/** The rule named WITHOUT its verb: "<service label>" + " — <component>" when it carries one. */
export function serviceTitle(rule: NamedRule, locale: Locale): string {
  const label = serviceLabel(rule.type, locale);
  return rule.component ? messages(locale).schedule.ruleTitle(label, rule.component) : label;
}

/** "<service label> · <verb>" with an optional " — <component>" suffix. */
export function ruleTitle(rule: NamedRule, locale: Locale): string {
  const verb = actionVerb(rule.action ?? null, locale);
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
