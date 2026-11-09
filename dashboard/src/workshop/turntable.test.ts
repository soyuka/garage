import { describe, expect, it } from 'vitest';
import { autoRotateSpeed, easeOutExpo, facingAzimuth, FACING_LIMIT, shortestTurn, turntableSpins } from './turntable';

describe('autoRotateSpeed', () => {
  it('matches drei: speed 2 is one orbit per 30 s at 60 fps', () => {
    expect(autoRotateSpeed(30)).toBeCloseTo(2);
  });

  it('turns once per 40 s on the car stage', () => {
    expect(autoRotateSpeed(40)).toBeCloseTo(1.5);
  });
});

describe('facingAzimuth', () => {
  const target: [number, number, number] = [0, 0.7, 0];

  it('swings to the side the region sits on, as a three-quarter view', () => {
    const rearWheel: [number, number, number] = [1.36, 0.3, 0.9];
    expect(facingAzimuth(rearWheel, target, -0.7)).toBeCloseTo(Math.min(FACING_LIMIT, Math.atan2(1.36, 0.9)));
    expect(facingAzimuth(rearWheel, target, -0.7)).toBeGreaterThan(0);
  });

  it('never turns to a straight front or rear view', () => {
    const hood: [number, number, number] = [-1.4, 0.9, 0];
    expect(facingAzimuth(hood, target, -0.7)).toBeCloseTo(-FACING_LIMIT);
  });

  it('keeps the side the camera is on for a centre-line region', () => {
    const hood: [number, number, number] = [-1.4, 0.9, 0];
    const farSide = Math.PI - 0.7;
    const theta = facingAzimuth(hood, target, farSide);
    expect(Math.cos(theta)).toBeLessThan(0);
    expect(Math.abs(shortestTurn(theta, Math.PI))).toBeCloseTo(FACING_LIMIT);
  });

  it('faces a region on the far side from the far side', () => {
    const farWheel: [number, number, number] = [-1.36, 0.3, -0.9];
    const theta = facingAzimuth(farWheel, target, -0.7);
    expect(Math.cos(theta)).toBeLessThan(0);
    expect(Math.sin(theta)).toBeLessThan(0);
  });

  it('stays put for a region at the target', () => {
    expect(facingAzimuth([0, 2, 0], target, 0.3)).toBe(0.3);
  });
});

describe('shortestTurn', () => {
  it('takes the short way round', () => {
    expect(shortestTurn(0.1, 0.4)).toBeCloseTo(0.3);
    expect(shortestTurn(3, -3)).toBeCloseTo(2 * Math.PI - 6);
    expect(shortestTurn(-3, 3)).toBeCloseTo(6 - 2 * Math.PI);
    expect(shortestTurn(0, 4 * Math.PI + 0.2)).toBeCloseTo(0.2);
  });
});

describe('easeOutExpo', () => {
  it('runs 0 to 1, decelerating', () => {
    expect(easeOutExpo(0)).toBe(0);
    expect(easeOutExpo(1)).toBe(1);
    expect(easeOutExpo(2)).toBe(1);
    expect(easeOutExpo(-1)).toBe(0);
    expect(easeOutExpo(0.25)).toBeGreaterThan(0.75);
  });
});

describe('turntableSpins', () => {
  const on = { playing: true, reduced: false, dragging: false, focused: false, visible: true };

  it('spins only while playing', () => {
    expect(turntableSpins(on)).toBe(true);
    expect(turntableSpins({ ...on, playing: false })).toBe(false);
  });

  it('pauses while dragged, focused by the agent, offscreen or hidden', () => {
    expect(turntableSpins({ ...on, dragging: true })).toBe(false);
    expect(turntableSpins({ ...on, focused: true })).toBe(false);
    expect(turntableSpins({ ...on, visible: false })).toBe(false);
  });

  it('never spins under reduced motion', () => {
    expect(turntableSpins({ ...on, reduced: true })).toBe(false);
  });
});
