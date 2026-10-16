import { fitDistance, orbitPosition } from './camera';

const near = (a: readonly number[], b: readonly number[]) => a.forEach((v, i) => expect(v).toBeCloseTo(b[i]!, 6));

describe('fitDistance', () => {
  it('backs off until the bounding sphere fills the asked share of the narrower view', () => {
    expect(fitDistance(50, 22, 1, 0.5)).toBeCloseTo(50 / (0.5 * Math.tan((11 * Math.PI) / 180)), 6);
  });

  it('uses the horizontal field of view when the drawing area is portrait', () => {
    const square = fitDistance(50, 22, 1, 0.5);
    const portrait = fitDistance(50, 22, 0.5, 0.5);
    expect(portrait).toBeCloseTo(50 / (0.5 * Math.tan(Math.atan(Math.tan((11 * Math.PI) / 180) * 0.5))), 6);
    expect(portrait).toBeGreaterThan(square);
    expect(fitDistance(50, 22, 2, 0.5)).toBeCloseTo(square, 6);
  });

  it('doubles with the part', () => {
    expect(fitDistance(100, 22, 1.4, 0.66)).toBeCloseTo(2 * fitDistance(50, 22, 1.4, 0.66), 6);
  });
});

describe('orbitPosition', () => {
  it('places the camera on a sphere around the target, +Y up', () => {
    near(orbitPosition([1, 2, 3], 10, 0, Math.PI / 2), [1, 2, 13]);
    near(orbitPosition([0, 0, 0], 10, Math.PI / 2, Math.PI / 2), [10, 0, 0]);
    near(orbitPosition([0, 5, 0], 10, 0.3, 0), [0, 15, 0]);
  });
});
