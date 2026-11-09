import { formatReading, NBSP } from '../format';
import type { Locale } from '../i18n/locale';
import { messages } from '../i18n/messages';
import { serviceLabel } from '../labels';
import type { TrackUnit } from './kmTrack';
import type { JobItem } from './modules';

export function reading(value: number, unit: TrackUnit, locale: Locale): string {
  return formatReading(value, unit, locale);
}

/** A job's display label, in the one casing every surface uses (the operator's "embrayage" reads "Embrayage"). */
export function jobTitle(job: Pick<JobItem, 'label' | 'type'>, locale: Locale): string {
  return sentenceCase(job.label ?? serviceLabel(job.type, locale));
}

export function jobWhen(job: Pick<JobItem, 'state' | 'immediate' | 'remaining' | 'days'>, locale: Locale): string {
  const t = messages(locale).workshop;
  if (job.immediate) return t.state.now;
  if (job.state === 'over') {
    if (job.remaining && job.remaining.value < 0) return t.overBy(reading(Math.abs(job.remaining.value), job.remaining.unit, locale));
    if (job.days !== null && job.days < 0) {
      const days = Math.abs(job.days);
      return t.overBy(`${days}${NBSP}${messages(locale).forecast.dayUnit(days)}`);
    }
    return t.state.over;
  }
  if (job.remaining) return t.remaining(reading(job.remaining.value, job.remaining.unit, locale));
  if (job.days !== null) return t.inDays(job.days);
  return '';
}

export function sentenceCase(text: string): string {
  return text ? text.charAt(0).toLocaleUpperCase() + text.slice(1) : text;
}

function fold(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().replace(/\s+/g, ' ').trim();
}

/** The two labels say the same thing, whatever their case or accents. */
export function sameLabel(a: string, b: string): boolean {
  return fold(a) === fold(b);
}
