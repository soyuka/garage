import { describe, expect, it } from 'vitest';
import { orbState, orbStrokes } from './orb';

describe('orbState', () => {
  it('breathes in ink at rest', () => {
    expect(orbState('idle', 'idle')).toEqual({ mode: 'idle', tone: 'ink' });
  });

  it('works in blue while a turn runs, whatever the mic says', () => {
    expect(orbState('working', 'idle')).toEqual({ mode: 'working', tone: 'blue' });
    expect(orbState('working', 'recording')).toEqual({ mode: 'working', tone: 'blue' });
  });

  it('listens in blue when the noyau listens or the mic is open, arming or transcribing', () => {
    expect(orbState('listening', 'idle')).toEqual({ mode: 'listening', tone: 'blue' });
    for (const mic of ['arming', 'recording', 'transcribing'] as const) {
      expect(orbState('idle', mic)).toEqual({ mode: 'listening', tone: 'blue' });
      expect(orbState('done', mic)).toEqual({ mode: 'listening', tone: 'blue' });
    }
  });

  it('settles once in ink when a turn is done', () => {
    expect(orbState('done', 'idle')).toEqual({ mode: 'settle', tone: 'ink' });
  });
});

describe('orbStrokes', () => {
  const strokes = orbStrokes(7, 4, 20);

  it('is deterministic for a seed, so the orb does not reshuffle on every render', () => {
    expect(orbStrokes(7, 4, 20)).toEqual(strokes);
    expect(orbStrokes(8, 4, 20)).not.toEqual(strokes);
  });

  it('draws the asked number of open pen strokes, each a polyline that overshoots its start', () => {
    expect(strokes).toHaveLength(4);
    for (const d of strokes) {
      expect(d).toMatch(/^M[-\d.]+ [-\d.]+( L[-\d.]+ [-\d.]+)+$/);
      const pts = [...d.matchAll(/([-\d.]+) ([-\d.]+)/g)].map((m) => [Number(m[1]), Number(m[2])]);
      const [x0, y0] = pts[0];
      const [x1, y1] = pts[pts.length - 1];
      expect(Math.hypot(x1 - x0, y1 - y0)).toBeGreaterThan(0.5);
    }
  });

  it('stays inside the sphere it sketches (radius plus a little pen wobble)', () => {
    const all = strokes.flatMap((d) => [...d.matchAll(/([-\d.]+) ([-\d.]+)/g)].map((m) => Math.hypot(Number(m[1]), Number(m[2]))));
    expect(Math.max(...all)).toBeLessThanOrEqual(20 * 1.06);
  });

  it('tilts every stroke differently: no two great circles share an orientation (not a globe of meridians)', () => {
    const firstPoints = strokes.map((d) => d.split(' L')[1]);
    expect(new Set(firstPoints).size).toBe(4);
  });
});
