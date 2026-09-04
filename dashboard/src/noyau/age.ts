import type { Messages } from '../i18n/messages';

/**
 * How long ago a card was written, in the operator's words. Shared by the à-décider inbox and the
 * notice stack so one card's age never reads differently from another's.
 *
 * `at` is a `performance.now()` stamp — monotonic milliseconds since the page loaded, NOT a wall
 * clock — so there is no hour to print and the caption is necessarily relative. The clock is injected
 * (defaulting to the real one) to keep this pure and testable, per this directory's rule that the
 * decisions live outside the components.
 */
export function whenLabel(at: number, t: Messages, now: number = performance.now()): string {
  const secs = Math.max(0, Math.round((now - at) / 1000));
  if (secs < 10) return t.inbox.justNow;
  if (secs < 60) return t.inbox.secondsAgo(secs);
  const mins = Math.round(secs / 60);
  if (mins < 60) return t.inbox.minutesAgo(mins);
  return t.inbox.hoursAgo(Math.round(mins / 60));
}
