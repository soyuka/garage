import { easeOut, fitDistance, HOME, lerpPose, orbitPosition, resetPose } from './camera';

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

describe('reset', () => {
  it('returns to the home angles around the part centre at the fitted distance', () => {
    const pose = resetPose([0, 4, 0], 300);
    expect(pose.target).toEqual([0, 4, 0]);
    near(pose.position, orbitPosition([0, 4, 0], 300, HOME.azimuth, HOME.polar));
  });

  it('eases out from the current pose to the home pose', () => {
    const from = { position: [0, 0, 100] as const, target: [10, 0, 0] as const };
    const to = resetPose([0, 0, 0], 200);
    expect(lerpPose(from, to, 0)).toEqual({ position: [0, 0, 100], target: [10, 0, 0] });
    near(lerpPose(from, to, 1).position, to.position);
    near(lerpPose(from, to, 1).target, to.target);
    expect(easeOut(0)).toBe(0);
    expect(easeOut(1)).toBe(1);
    expect(easeOut(0.5)).toBeGreaterThan(0.5);
    expect(easeOut(2)).toBe(1);
  });
});
