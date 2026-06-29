import { describe, it, expect } from 'vitest';
import { tickMarks } from './gauge';

describe('tickMarks', () => {
  const ticks = tickMarks(60, 66, 66, 50, 56, 5);

  it('emits one tick per count', () => {
    expect(ticks).toHaveLength(60);
  });

  it('marks every Nth tick major (60/5 = 12 majors)', () => {
    expect(ticks.filter((t) => t.major)).toHaveLength(12);
  });

  it('places the first tick at 12 o-clock (straight up from center)', () => {
    const t0 = ticks[0];
    expect(t0.x1).toBeCloseTo(66, 5); // vertical: x == cx
    expect(t0.x2).toBeCloseTo(66, 5);
    expect(t0.y1).toBeCloseTo(16, 5); // inner = cy - rInner = 66 - 50
    expect(t0.y2).toBeCloseTo(10, 5); // outer = cy - rOuter = 66 - 56
    expect(t0.major).toBe(true); // index 0 is a major
  });

  it('advances clockwise — the quarter tick (index 15 of 60) is at 3 o-clock', () => {
    const q = ticks[15];
    expect(q.x1).toBeCloseTo(116, 4); // cx + rInner
    expect(q.y1).toBeCloseTo(66, 4); // cy
  });
});
