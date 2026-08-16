import { describe, it, expect } from 'vitest';
import { engineBadge, healthStat } from './Cockpit';

// The engine badge — variant + code joined " · ", either alone standing on its own, null when the
// vehicle has neither (never an empty string that would render a stray label).

describe('engineBadge — join variant + code, either alone, null when neither', () => {
  it('joins both parts with " · "', () => {
    expect(engineBadge({ engineVariant: '1.6 HDi', engineCode: '9HZ' })).toBe('1.6 HDi · 9HZ');
  });

  it('renders just the variant when the code is absent', () => {
    expect(engineBadge({ engineVariant: '1.6 HDi', engineCode: null })).toBe('1.6 HDi');
  });

  it('renders just the code when the variant is absent', () => {
    expect(engineBadge({ engineVariant: null, engineCode: '9HZ' })).toBe('9HZ');
  });

  it('is null when neither is known', () => {
    expect(engineBadge({ engineVariant: null, engineCode: null })).toBeNull();
    expect(engineBadge({})).toBeNull();
  });

  it('treats an empty/blank string the same as absent', () => {
    expect(engineBadge({ engineVariant: '', engineCode: '  ' })).toBeNull();
  });
});

// The SANTÉ stat moved off the top-right HUD INTO the cockpit slab, beside the kilométrage: value =
// rounded % (no false precision, §5), status = the signal driving its glow. Null when the rollup is
// absent or unknown-without-score — the cockpit renders no stat rather than a dash.

describe('healthStat — the cockpit health readout', () => {
  it('rounds the score to the nearest 5 with a non-breaking-space percent', () => {
    expect(healthStat({ status: 'green', score: 72, overdue: 0, dueSoon: 0, tracked: 3, gaps: 0 }, 'fr')).toEqual({ text: '70 %', status: 'green' });
  });

  it('carries the signal status through for the glow', () => {
    expect(healthStat({ status: 'red', score: 45, overdue: 2, dueSoon: 0, tracked: 3, gaps: 0 }, 'fr')?.status).toBe('red');
  });

  it('is null when the rollup is missing or has no score', () => {
    expect(healthStat(undefined, 'fr')).toBeNull();
    expect(healthStat({ status: 'unknown', score: null, overdue: 0, dueSoon: 0, tracked: 0, gaps: 0 }, 'fr')).toBeNull();
  });
});
