import { describe, expect, it } from 'vitest';
import { arcFractions, chainSegments, dropInterior, dropInteriorTriangles, insideDepth, pruneStrokes, pruneWithin, scheduleStrokes, strokeBuffers, strokeReveal } from './strokes';

// An L made of two segments sharing (1,0,0), plus a separate segment far away.
const SEGS = new Float32Array([
  0, 0, 0, 1, 0, 0,
  1, 0, 0, 1, 2, 0,
  5, 5, 5, 6, 5, 5,
]);

describe('chainSegments', () => {
  it('walks shared vertices into continuous polylines', () => {
    const lines = chainSegments(SEGS);
    expect(lines).toHaveLength(2);
    const l = lines.find((p) => p.length === 3)!;
    expect(l.map((p) => p.join(','))).toEqual(expect.arrayContaining(['0,0,0', '1,0,0', '1,2,0']));
    expect(l[1]).toEqual([1, 0, 0]);
  });

  it('joins vertices that differ only by float noise', () => {
    const noisy = new Float32Array([0, 0, 0, 1, 0, 0, 1.0000001, 0, 0, 2, 0, 0]);
    expect(chainSegments(noisy)).toHaveLength(1);
  });

  it('keeps every segment exactly once', () => {
    const lines = chainSegments(SEGS);
    expect(lines.reduce((n, l) => n + l.length - 1, 0)).toBe(3);
  });
});

describe('arcFractions', () => {
  it('is the normalised distance travelled along the stroke', () => {
    expect(arcFractions([[0, 0, 0], [1, 0, 0], [1, 3, 0]])).toEqual([0, 0.25, 1]);
  });

  it('is zero for a degenerate stroke', () => {
    expect(arcFractions([[0, 0, 0], [0, 0, 0]])).toEqual([0, 0]);
  });
});

describe('scheduleStrokes', () => {
  const strokes = [
    [[0, 4, 0], [1, 4, 0]],
    [[0, 0, 0], [1, 0, 0]],
    [[0, 2, 0], [1, 2, 0]],
  ] as Array<Array<[number, number, number]>>;

  it('starts strokes bottom to top, the last finishing at 1', () => {
    const s = scheduleStrokes(strokes, null, 0.4);
    expect(s.map((x) => x.start)).toEqual([0.6, 0, 0.3]);
    expect(Math.max(...s.map((x) => x.start + x.span))).toBeCloseTo(1, 9);
  });

  it('starts nearest the origin first when one is given', () => {
    const s = scheduleStrokes(strokes, [0.5, 4, 0], 0.4);
    expect(s[0].start).toBe(0);
  });

  it('draws a single stroke over the whole window', () => {
    expect(scheduleStrokes([strokes[0]], null, 0.4)).toEqual([{ start: 0, span: 1 }]);
  });
});

describe('strokeReveal', () => {
  it('is how far along its own window a stroke is', () => {
    expect(strokeReveal(0.1, 0.2, 0.4)).toBe(0);
    expect(strokeReveal(0.4, 0.2, 0.4)).toBeCloseTo(0.5, 9);
    expect(strokeReveal(0.9, 0.2, 0.4)).toBe(1);
  });
});

describe('strokeBuffers', () => {
  it('flattens strokes into segment pairs carrying arc fraction and schedule per vertex', () => {
    const b = strokeBuffers([[[0, 0, 0], [1, 0, 0], [1, 3, 0]]], [{ start: 0, span: 1 }]);
    expect(Array.from(b.position)).toEqual([0, 0, 0, 1, 0, 0, 1, 0, 0, 1, 3, 0]);
    expect(Array.from(b.along)).toEqual([0, 0.25, 0.25, 1]);
    expect(Array.from(b.start)).toEqual([0, 0, 0, 0]);
    expect(Array.from(b.span)).toEqual([1, 1, 1, 1]);
  });
});

describe('pruneStrokes', () => {
  it('drops the short strokes that read as hatching and keeps the confident ones', () => {
    const long: Array<[number, number, number]> = [[0, 0, 0], [1, 0, 0], [1, 1, 0]];
    const tiny: Array<[number, number, number]> = [[0, 0, 0], [0.02, 0, 0]];
    expect(pruneStrokes([long, tiny], 0.1)).toEqual([long]);
  });
});

describe('dropInterior', () => {
  // A 2×2×2 cube centred on the origin, as outward planes n·p + c = signed distance (negative inside).
  const cube = [
    { n: [1, 0, 0], c: -1 },
    { n: [-1, 0, 0], c: -1 },
    { n: [0, 1, 0], c: -1 },
    { n: [0, -1, 0], c: -1 },
    { n: [0, 0, 1], c: -1 },
    { n: [0, 0, -1], c: -1 },
  ] as Array<{ n: [number, number, number]; c: number }>;

  it('keeps strokes on or near the shell and drops the ones deep inside it', () => {
    const shell: Array<[number, number, number]> = [[-1, 1, 1], [1, 1, 1]];
    const near: Array<[number, number, number]> = [[-0.5, 0.9, 0.9], [0.5, 0.9, 0.9]];
    const seat: Array<[number, number, number]> = [[-0.2, 0, 0], [0.2, 0, 0]];
    expect(dropInterior([shell, near, seat], cube, 0.3)).toEqual([shell, near]);
  });

  it('measures how deep a point sits inside the hull', () => {
    expect(insideDepth([0, 0, 0], cube)).toBeCloseTo(1, 9);
    expect(insideDepth([0.8, 0, 0], cube)).toBeCloseTo(0.2, 9);
    expect(insideDepth([2, 0, 0], cube)).toBeLessThan(0);
  });
});

describe('pruneWithin', () => {
  it('holds strokes inside the given bounds to a stricter minimum length, leaving the rest alone', () => {
    const wheel = { min: [-1, 0, -5], max: [1, 2, 5] } as { min: [number, number, number]; max: [number, number, number] };
    const spoke: Array<[number, number, number]> = [[0, 1, 0], [0.3, 1, 0]];
    const tyre: Array<[number, number, number]> = [[-0.9, 1, 0], [0, 1.9, 0], [0.9, 1, 0], [0, 0.1, 0], [-0.9, 1, 0]];
    const body: Array<[number, number, number]> = [[3, 1, 0], [3.3, 1, 0]];
    expect(pruneWithin([spoke, tyre, body], [wheel], 1)).toEqual([tyre, body]);
  });
});

describe('dropInteriorTriangles', () => {
  const cube = [
    { n: [1, 0, 0], c: -1 },
    { n: [-1, 0, 0], c: -1 },
    { n: [0, 1, 0], c: -1 },
    { n: [0, -1, 0], c: -1 },
    { n: [0, 0, 1], c: -1 },
    { n: [0, 0, -1], c: -1 },
  ] as Array<{ n: [number, number, number]; c: number }>;

  it('keeps the shell triangles and drops the ones deep inside the hull', () => {
    const shell = [1, -1, -1, 1, 1, -1, 1, 1, 1];
    const seat = [-0.1, 0, 0, 0.1, 0, 0, 0, 0.1, 0];
    const out = dropInteriorTriangles(new Float32Array([...shell, ...seat]), cube, 0.3);
    expect(Array.from(out)).toEqual(shell);
  });
});
