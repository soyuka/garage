import { describe, expect, it } from 'vitest';
import { placeLabels } from './trackLabels';

const label = (id: string, x: number, width: number, prefer: 'up' | 'down' = 'down', right = false) => ({ id, x, width, prefer, right });

describe('placeLabels', () => {
  it('keeps every label in its preferred lane when nothing collides', () => {
    expect(placeLabels([label('a', 0, 50, 'up'), label('b', 100, 50, 'down')], [])).toEqual({ a: 'up', b: 'down' });
  });

  it('moves a colliding label to the other lane', () => {
    expect(placeLabels([label('a', 0, 120), label('b', 60, 120)], [])).toEqual({ a: 'down', b: 'up' });
  });

  it('drops to tick-only when both lanes collide', () => {
    expect(placeLabels([label('a', 0, 120), label('b', 40, 120), label('c', 80, 120)], [])).toEqual({ a: 'down', b: 'up', c: null });
  });

  it('treats the reserved intervals (the today label) as occupied', () => {
    expect(placeLabels([label('a', 100, 60, 'up')], [{ lane: 'up', from: 120, to: 220 }])).toEqual({ a: 'down' });
  });

  it('anchors a right-flagged label to the left of its tick', () => {
    expect(placeLabels([label('a', 300, 100, 'down', true), label('b', 230, 30, 'down')], [])).toEqual({ a: 'down', b: 'up' });
  });

  it('keeps a gap between neighbours in a lane', () => {
    expect(placeLabels([label('a', 0, 50), label('b', 52, 50)], [])).toEqual({ a: 'down', b: 'up' });
  });
});

describe('placeLabels — priority', () => {
  it('places higher-priority labels first, so a done label yields to a state label', () => {
    const done = { id: 'done', x: 0, width: 120, prefer: 'down' as const, right: false, priority: 0 };
    const due = { id: 'due', x: 40, width: 120, prefer: 'down' as const, right: false, priority: 2 };
    const planned = { id: 'planned', x: 80, width: 120, prefer: 'down' as const, right: false, priority: 1 };
    expect(placeLabels([done, due, planned], [])).toEqual({ due: 'down', planned: 'up', done: null });
  });

  it('never drops a must-keep label, even when both lanes are taken', () => {
    const over = { id: 'over', x: 100, width: 80, prefer: 'down' as const, right: false, priority: 3, keep: true };
    expect(placeLabels([over], [{ lane: 'up', from: 90, to: 200 }, { lane: 'down', from: 90, to: 200 }])).toEqual({ over: 'down' });
  });
});
