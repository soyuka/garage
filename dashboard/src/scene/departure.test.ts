import { describe, it, expect } from 'vitest';
import { departureTransform, DEPARTURE_SCALE, PODIUM_TARGET } from './departure';

function rect(over: Partial<DOMRect>): DOMRect {
  return { left: 0, top: 0, width: 240, height: 320, right: 240, bottom: 320, x: 0, y: 0, toJSON() {}, ...over } as DOMRect;
}

describe('departureTransform — the ghost drives to the named podium target', () => {
  it('translates a card already at the podium target by zero', () => {
    const viewport = { width: 800, height: 600 };
    const r = rect({ left: 800 / 2 - 120, top: 600 * PODIUM_TARGET.y - 160, width: 240, height: 320 });
    const t = departureTransform(r, viewport);
    expect(t.translateX).toBeCloseTo(0);
    expect(t.translateY).toBeCloseTo(0);
  });

  it('translates a top-left card toward the podium rather than the generic viewport centre', () => {
    const viewport = { width: 1000, height: 800 };
    const r = rect({ left: 40, top: 60, width: 200, height: 100 }); // card centre = (140, 110)
    const t = departureTransform(r, viewport);
    expect(t.translateX).toBeCloseTo(1000 * PODIUM_TARGET.x - 140);
    expect(t.translateY).toBeCloseTo(800 * PODIUM_TARGET.y - 110);
  });

  it('defaults to the ~1.6x growth documented in CHOREOGRAPHY', () => {
    const t = departureTransform(rect({}), { width: 800, height: 600 });
    expect(t.scale).toBe(DEPARTURE_SCALE);
    expect(DEPARTURE_SCALE).toBeCloseTo(1.6);
  });

  it('honours an explicit scale override', () => {
    const t = departureTransform(rect({}), { width: 800, height: 600 }, 2);
    expect(t.scale).toBe(2);
  });
});
