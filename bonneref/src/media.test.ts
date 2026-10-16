import { fitWithin, pickSharpest, sampleTimes, sharpness } from './media';

describe('fitWithin', () => {
  it('caps the long side at 1600 px, keeping the ratio', () => {
    expect(fitWithin(4000, 3000)).toEqual({ width: 1600, height: 1200 });
    expect(fitWithin(1080, 1920)).toEqual({ width: 900, height: 1600 });
  });

  it('never upscales', () => {
    expect(fitWithin(800, 600)).toEqual({ width: 800, height: 600 });
  });
});

describe('sampleTimes', () => {
  it('takes three frames at 25, 50 and 75 % of the clip', () => {
    expect(sampleTimes(8)).toEqual([2, 4, 6]);
  });

  it('falls back to the first frame when the duration is unknown', () => {
    expect(sampleTimes(Number.NaN)).toEqual([0]);
    expect(sampleTimes(Infinity)).toEqual([0]);
    expect(sampleTimes(0)).toEqual([0]);
  });
});

const flat = (w: number, h: number, v = 128) => new Uint8ClampedArray(w * h).fill(v);
const checker = (w: number, h: number) => Uint8ClampedArray.from({ length: w * h }, (_, i) => ((i % w) + Math.floor(i / w)) % 2 ? 255 : 0);

describe('sharpness', () => {
  it('reads a flat frame as blurred and an edgy one as sharp', () => {
    expect(sharpness(flat(8, 8), 8, 8)).toBe(0);
    expect(sharpness(checker(8, 8), 8, 8)).toBeGreaterThan(1000);
  });
});

describe('pickSharpest', () => {
  it('returns the index of the sharpest frame, the first one on a tie', () => {
    expect(pickSharpest([3, 9, 4])).toBe(1);
    expect(pickSharpest([5, 5])).toBe(0);
    expect(pickSharpest([])).toBe(0);
  });
});
