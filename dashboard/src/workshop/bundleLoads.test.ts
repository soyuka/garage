import { describe, expect, it } from 'vitest';
import { createBundleSeq, nextPrefetch } from './bundleLoads';

describe('createBundleSeq — only the latest read of a car lands', () => {
  it('rejects an older response that arrives after a newer one', () => {
    const seq = createBundleSeq();
    const first = seq.begin('/garage/vehicles/1');
    const second = seq.begin('/garage/vehicles/1');
    expect(seq.accept('/garage/vehicles/1', second)).toBe(true);
    expect(seq.accept('/garage/vehicles/1', first)).toBe(false);
  });

  it('keeps cars independent', () => {
    const seq = createBundleSeq();
    const a = seq.begin('/garage/vehicles/1');
    seq.begin('/garage/vehicles/2');
    expect(seq.accept('/garage/vehicles/1', a)).toBe(true);
  });
});

describe('nextPrefetch — bounded parc prefetch', () => {
  const iris = ['/v/1', '/v/2', '/v/3', '/v/4', '/v/5'];

  it('starts at most the free slots under the cap', () => {
    expect(nextPrefetch(iris, () => false, new Set(), 3)).toEqual(['/v/1', '/v/2', '/v/3']);
    expect(nextPrefetch(iris, () => false, new Set(['/v/1', '/v/2']), 3)).toEqual(['/v/3']);
  });

  it('skips cars already loaded, tried or in flight', () => {
    const skip = (iri: string) => iri === '/v/1' || iri === '/v/3';
    expect(nextPrefetch(iris, skip, new Set(['/v/2']), 3)).toEqual(['/v/4', '/v/5']);
  });

  it('starts nothing when the cap is full', () => {
    expect(nextPrefetch(iris, () => false, new Set(['/v/7', '/v/8', '/v/9']), 3)).toEqual([]);
  });
});
