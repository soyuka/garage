import { arrowSide, facingAngle, filterCotes, rimTop, visibleLabels } from './cotes';

const R = 38;
const H = 90;

describe('filterCotes', () => {
  const c = filterCotes(R, H, { offset: 12, gap: 1.5, overshoot: 2.5, lift: 3 });

  it('draws Ø across the top face, through the axis, arrowheads on the rim', () => {
    expect(c.diameter.from).toEqual([-R, H, 0]);
    expect(c.diameter.to).toEqual([R, H, 0]);
    expect(c.diameter.label).toEqual([0, H + 3, 0]);
  });

  it('draws the height parallel to the axis, offset out of the side, exactly bottom to top', () => {
    expect(c.height.from).toEqual([R + 12, 0, 0]);
    expect(c.height.to).toEqual([R + 12, H, 0]);
    expect(c.height.label).toEqual([R + 12, H / 2, 0]);
  });

  it('starts the height extension lines 1.5 mm off the rim edges and runs them past the line', () => {
    expect(c.height.extensions).toEqual([
      [R + 1.5, 0, 0],
      [R + 14.5, 0, 0],
      [R + 1.5, H, 0],
      [R + 14.5, H, 0],
    ]);
  });

  it('leads the thread note from the camera-facing base rim, outward and down, to a label outside the silhouette', () => {
    const [rim, knee, end] = c.thread.points;
    expect(Math.hypot(rim![0], rim![2])).toBeCloseTo(R);
    expect(rim![1]).toBe(0);
    expect(rim![2]).toBeGreaterThan(0);
    expect(knee![1]).toBeLessThan(0);
    expect(Math.abs(knee![0])).toBeGreaterThan(R);
    expect(Math.abs(end![0])).toBeGreaterThan(Math.abs(knee![0]));
    expect(end![1]).toBe(knee![1]);
    expect(c.thread.label).toEqual(end);
  });
});

describe('filterCotes on a domed top', () => {
  it('puts the Ø on the face plane at the rim, under the dome, while the height still spans the whole part', () => {
    const c = filterCotes(R, H, { faceY: 81, lift: 3 });
    expect(c.diameter.from).toEqual([-R, 81, 0]);
    expect(c.diameter.to).toEqual([R, 81, 0]);
    expect(c.diameter.label).toEqual([0, 84, 0]);
    expect(c.height.to).toEqual([R + 12, H, 0]);
  });
});

describe('rimTop', () => {
  it('finds the highest point still at full radius: the shoulder where a domed top starts', () => {
    const profile: [number, number][] = [[34.9, 1], [33.6, 40], [33.3, 62], [32.2, 63], [21.6, 67], [12, 68]];
    expect(rimTop(profile)).toBe(62);
  });

  it('falls back to the top of a flat part', () => {
    expect(rimTop([[30, 0], [30, 50]])).toBe(50);
  });
});

describe('facingAngle', () => {
  it('turns the annotation frame so its +Z points at the camera azimuth', () => {
    for (const [x, z] of [[0, 600], [600, 0], [-300, -300], [120, -500]] as const) {
      const a = facingAngle(x, z);
      const len = Math.hypot(x, z);
      expect(Math.sin(a)).toBeCloseTo(x / len);
      expect(Math.cos(a)).toBeCloseTo(z / len);
    }
  });
});

describe('arrowSide', () => {
  const dot = (a: readonly number[], b: readonly number[]) => a[0]! * b[0]! + a[1]! * b[1]! + a[2]! * b[2]!;

  it('opens the arrowhead across the line and across the view, so it never shows edge-on', () => {
    const view = [0, Math.cos(1.1), Math.sin(1.1)] as const;
    const s = arrowSide([1, 0, 0], view);
    expect(Math.hypot(...s)).toBeCloseTo(1);
    expect(dot(s, [1, 0, 0])).toBeCloseTo(0);
    expect(dot(s, view)).toBeCloseTo(0);
  });

  it('still returns a unit side perpendicular to the line when the line points at the camera', () => {
    const s = arrowSide([0, 1, 0], [0, 1, 0]);
    expect(Math.hypot(...s)).toBeCloseTo(1);
    expect(dot(s, [0, 1, 0])).toBeCloseTo(0);
  });
});

describe('visibleLabels', () => {
  const rect = (x: number, y: number, w = 60, h = 18) => ({ x, y, w, h });

  it('keeps every label that does not collide', () => {
    expect(visibleLabels([{ key: 'diameter', rect: rect(0, 0) }, { key: 'height', rect: rect(100, 0) }, { key: 'thread', rect: rect(0, 40) }])).toEqual(['diameter', 'height', 'thread']);
  });

  it('hides the lower-priority label of a colliding pair: Ø, then H, then thread, then seal', () => {
    expect(visibleLabels([{ key: 'thread', rect: rect(10, 5) }, { key: 'diameter', rect: rect(0, 0) }])).toEqual(['diameter']);
    expect(visibleLabels([{ key: 'seal', rect: rect(0, 0) }, { key: 'height', rect: rect(30, 10) }, { key: 'thread', rect: rect(200, 0) }])).toEqual(['height', 'thread']);
  });

  it('checks a label only against labels still shown', () => {
    expect(visibleLabels([{ key: 'diameter', rect: rect(0, 0) }, { key: 'height', rect: rect(50, 0) }, { key: 'thread', rect: rect(100, 0) }])).toEqual(['diameter', 'thread']);
  });
});
