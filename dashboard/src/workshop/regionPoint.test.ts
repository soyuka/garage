import { describe, expect, it } from 'vitest';
import { regionPoint } from './regionPoint';

const box = { length: 4.6, height: 1.7, width: 1.85 };

describe('regionPoint', () => {
  it('reads the front at −x, the house facing of every shipped mesh', () => {
    expect(regionPoint('hood', box)[0]).toBeLessThan(0);
    expect(regionPoint('frontAxle', box)[0]).toBeLessThan(0);
    expect(regionPoint('rearAxle', box)[0]).toBeGreaterThan(0);
  });

  it('puts the axles low on the near side and the windshield high', () => {
    const [, axleY, axleZ] = regionPoint('frontAxle', box);
    expect(axleY).toBeLessThan(box.height / 2);
    expect(axleZ).toBeCloseTo(box.width / 2, 5);
    expect(regionPoint('windshield', box)[1]).toBeGreaterThan(regionPoint('hood', box)[1]);
  });

  it('stays inside the box', () => {
    for (const region of ['hood', 'windshield', 'frontAxle', 'rearAxle', 'bay'] as const) {
      const [x, y] = regionPoint(region, box);
      expect(Math.abs(x)).toBeLessThanOrEqual(box.length / 2);
      expect(y).toBeGreaterThanOrEqual(0);
      expect(y).toBeLessThanOrEqual(box.height);
    }
  });
});

describe('regionPoint on a motorcycle', () => {
  const bike = { length: 2.3, height: 1.5, width: 0.95 };

  it('reads the engine off the block between the wheels, not the headlight fairing', () => {
    const [x, y] = regionPoint('hood', bike, 'moto');
    expect(Math.abs(x)).toBeLessThan(bike.length * 0.1);
    expect(y).toBeLessThan(bike.height * 0.45);
  });

  it('puts the axles on the wheel hubs, on the centre plane', () => {
    const [fx, fy, fz] = regionPoint('frontAxle', bike, 'moto');
    const [rx, , rz] = regionPoint('rearAxle', bike, 'moto');
    expect(fx).toBeLessThan(-bike.length * 0.3);
    expect(rx).toBeGreaterThan(bike.length * 0.3);
    expect(fy).toBeLessThan(bike.height * 0.3);
    expect(fz).toBe(0);
    expect(rz).toBe(0);
  });

  it('keeps the car table for any other body', () => {
    expect(regionPoint('hood', box, 'car')).toEqual(regionPoint('hood', box));
  });
});
