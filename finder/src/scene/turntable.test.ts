import { turntableStep } from './turntable';

describe('turntableStep', () => {
  it('turns one revolution in about 40 s', () => {
    let angle = 0;
    for (let i = 0; i < 2400; i++) angle += turntableStep(1 / 60, { reducedMotion: false, stopped: false });
    expect(angle).toBeCloseTo(2 * Math.PI);
  });

  it('never moves once the user touched the view, or under reduced motion', () => {
    expect(turntableStep(1, { reducedMotion: false, stopped: true })).toBe(0);
    expect(turntableStep(1, { reducedMotion: true, stopped: false })).toBe(0);
  });

  it('ignores a long frame gap instead of jumping', () => {
    expect(turntableStep(5, { reducedMotion: false, stopped: false })).toBeCloseTo((2 * Math.PI) / 40 * 0.1);
  });
});
