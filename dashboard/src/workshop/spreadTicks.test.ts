import { describe, expect, it } from 'vitest';
import { spreadTicks } from './spreadTicks';

describe('spreadTicks', () => {
  it('leaves well-spaced ticks where they are', () => {
    expect(spreadTicks([10, 50, 90], 14, 0, 100)).toEqual([10, 50, 90]);
  });

  it('pushes a cluster apart around its centre, keeping order', () => {
    expect(spreadTicks([50, 52], 14, 0, 100)).toEqual([44, 58]);
    expect(spreadTicks([40, 41, 42], 14, 0, 100)).toEqual([27, 41, 55]);
  });

  it('keeps the spread inside the rail', () => {
    expect(spreadTicks([0, 1], 14, 0, 100)).toEqual([0, 14]);
    expect(spreadTicks([99, 100], 14, 0, 100)).toEqual([86, 100]);
  });

  it('merges clusters that the first spread makes collide', () => {
    const out = spreadTicks([30, 31, 45, 46], 14, 0, 200);
    for (let i = 1; i < out.length; i++) expect(out[i] - out[i - 1]).toBeGreaterThanOrEqual(14 - 1e-9);
  });
});
