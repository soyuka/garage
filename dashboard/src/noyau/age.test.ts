import { describe, expect, it } from 'vitest';
import { whenLabel } from './age';
import { fr } from '../i18n/fr';

// The "when" caption, shared by the à-décider inbox and the notice stack. Clock-injected so it is a
// pure function — `at` is a performance.now() stamp (monotonic, NOT wall clock), which is exactly why
// the caption is relative: there is no hour to print.

describe('whenLabel — how long ago a card was written', () => {
  it('calls the first ten seconds "just now" rather than counting them', () => {
    expect(whenLabel(1_000, fr, 1_000)).toBe(fr.inbox.justNow);
    expect(whenLabel(1_000, fr, 10_000)).toBe(fr.inbox.justNow); // 9 s
    expect(whenLabel(1_000, fr, 11_000)).toBe(fr.inbox.secondsAgo(10)); // the boundary is `< 10`
  });

  it('counts seconds below a minute', () => {
    expect(whenLabel(0, fr, 42_000)).toBe(fr.inbox.secondsAgo(42));
  });

  it('counts minutes below an hour, then hours', () => {
    expect(whenLabel(0, fr, 5 * 60_000)).toBe(fr.inbox.minutesAgo(5));
    expect(whenLabel(0, fr, 3 * 3_600_000)).toBe(fr.inbox.hoursAgo(3));
  });

  it('never counts backwards — a stamp from the future reads as just now', () => {
    expect(whenLabel(9_000, fr, 1_000)).toBe(fr.inbox.justNow);
  });
});
