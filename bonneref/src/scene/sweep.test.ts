import { EMPTY, markReady, present, step, sweepEase, type SweepState } from './sweep';

const layer = (key: string) => ({ key, item: key });
const S = EMPTY as SweepState<string>;

describe('sweep layers', () => {
  it('draws the first part in from nothing once it is mounted', () => {
    let { state } = present(S, layer('carousel-piston'));
    expect(state.shown?.key).toBe('carousel-piston');
    expect(state.outgoing).toBeNull();
    expect(step(state, 0.5, 1).state.progress).toBe(0);
    state = markReady(state, 'carousel-piston');
    expect(step(state, 0.5, 1).state.progress).toBeCloseTo(0.5);
  });

  it('keeps the outgoing part fully drawn until the incoming one is ready, then drops it at the end', () => {
    let s = markReady(present(S, layer('carousel-piston')).state, 'carousel-piston');
    s = step(s, 2, 1).state;
    s = present(s, layer('mesh-spark-plug')).state;
    expect(s.outgoing?.key).toBe('carousel-piston');
    expect(step(s, 0.4, 1).state.progress).toBe(0);
    s = markReady(s, 'mesh-spark-plug');
    const mid = step(s, 0.4, 1);
    expect(mid.dropped).toEqual([]);
    expect(mid.state.outgoing?.key).toBe('carousel-piston');
    const end = step(mid.state, 0.7, 1);
    expect(end.dropped.map((l) => l.key)).toEqual(['carousel-piston']);
    expect(end.state.outgoing).toBeNull();
    expect(end.state.progress).toBe(1);
  });

  it('hands off to the result mid-sweep: the oldest layer drops at once, never three layers', () => {
    let s = markReady(present(S, layer('carousel-a')).state, 'carousel-a');
    s = step(s, 2, 1).state;
    s = markReady(present(s, layer('carousel-b')).state, 'carousel-b');
    s = step(s, 0.3, 1).state;
    const handoff = present(s, layer('filter-oil'));
    expect(handoff.dropped.map((l) => l.key)).toEqual(['carousel-a']);
    expect(handoff.state.outgoing?.key).toBe('carousel-b');
    expect(handoff.state.shown?.key).toBe('filter-oil');
    expect(handoff.state.progress).toBe(0);
  });

  it('updates the part in place when the key does not change (variant cotes)', () => {
    const s = step(markReady(present(S, layer('filter-oil')).state, 'filter-oil'), 2, 1).state;
    const next = present(s, { key: 'filter-oil', item: 'new dims' });
    expect(next.dropped).toEqual([]);
    expect(next.state.shown?.item).toBe('new dims');
    expect(next.state.progress).toBe(1);
  });

  it('eases in and out over [0, 1]', () => {
    expect(sweepEase(0)).toBe(0);
    expect(sweepEase(1)).toBe(1);
    expect(sweepEase(0.5)).toBeCloseTo(0.5);
    expect(sweepEase(0.1)).toBeLessThan(0.1);
  });
});
