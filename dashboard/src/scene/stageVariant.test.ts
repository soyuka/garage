import { afterEach, describe, expect, it, vi } from 'vitest';
import { readStageVariant, STAGE_VARIANT_KEY } from './stageVariant';

// readStageVariant is the single source for the shipped stage look: 'mat' (workbench cutting-mat +
// porcelain car) is now the DEFAULT; 'night' (the original tinted-glass/void look) is the explicit
// opt-out stored under the same key. environment: 'node' (vite.config.ts) means `window` does not exist
// unless stubbed — these stub a minimal localStorage per test.

function stubStorage(values: Record<string, string> = {}) {
  const store = new Map(Object.entries(values));
  vi.stubGlobal('window', {
    localStorage: { getItem: (k: string) => (store.has(k) ? store.get(k)! : null) },
  });
}

afterEach(() => vi.unstubAllGlobals());

describe('readStageVariant', () => {
  it('defaults to mat when the key is absent', () => {
    stubStorage();
    expect(readStageVariant()).toBe('mat');
  });

  it('a value already stored as "mat" (pre-promotion) keeps resolving to mat', () => {
    stubStorage({ [STAGE_VARIANT_KEY]: 'mat' });
    expect(readStageVariant()).toBe('mat');
  });

  it('resolves to night only on the explicit opt-out value', () => {
    stubStorage({ [STAGE_VARIANT_KEY]: 'night' });
    expect(readStageVariant()).toBe('night');
  });

  it('falls back to mat on any unrecognized stored value', () => {
    stubStorage({ [STAGE_VARIANT_KEY]: 'garbage' });
    expect(readStageVariant()).toBe('mat');
  });

  it('falls back to mat when localStorage access throws (privacy mode / SSR)', () => {
    vi.stubGlobal('window', {
      localStorage: {
        getItem: () => {
          throw new Error('denied');
        },
      },
    });
    expect(readStageVariant()).toBe('mat');
  });

  it('falls back to mat outside a browser (no window global at all)', () => {
    expect(readStageVariant()).toBe('mat');
  });
});
