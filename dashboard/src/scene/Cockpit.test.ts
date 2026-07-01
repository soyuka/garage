import { describe, it, expect } from 'vitest';
import { engineBadge } from './Cockpit';

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
