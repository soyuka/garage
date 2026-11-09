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
