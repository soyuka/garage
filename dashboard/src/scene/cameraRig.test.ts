import { describe, it, expect } from 'vitest';
import { holdIntent } from './cameraRig';

describe('holdIntent — the rail head is a toggle', () => {
  it('a click at rest holds the region', () => {
    expect(holdIntent(null, 'hood')).toBe('hold');
  });

  it('clicking the SAME region again goes back — "click again to return" (D1)', () => {
    expect(holdIntent({ region: 'hood', key: 3 }, 'hood')).toBe('release');
  });

  it('clicking a DIFFERENT region moves the camera rather than releasing it', () => {
    expect(holdIntent({ region: 'hood', key: 3 }, 'frontAxle')).toBe('hold');
  });
});

import {
  HOME,
  POLAR,
  DOLLY_RADIUS_FACTOR,
  clampPolar,
  sineEaseInOut,
  toSpherical,
  fromSpherical,
  derivePreset,
  presetForRegion,
  gateReduce,
  dragBreaksHold,
  GATE_INIT,
  type Vec3,
  type GateState,
} from './cameraRig';
import { regionAnchors, BODY_REGIONS } from './anchors';

// The earned camera (D1): the view dollies TO a region instead of dimming the stage. cameraRig is the
// pure core — preset derivation (region → a closer framing within the polar clamps) and the intent-gate
// reducer (queue mid-drag, a user drag breaks the hold). The imperative lerp lives in Plateau/CameraRig.

function dist(a: Vec3, b: Vec3): number {
  return Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]);
}

describe('clampPolar — keep the tilt inside the OrbitControls clamps', () => {
  it('clamps below the minimum and above the maximum', () => {
    expect(clampPolar(0)).toBe(POLAR.min);
    expect(clampPolar(Math.PI)).toBe(POLAR.max);
  });
  it('leaves an in-range polar untouched', () => {
    const mid = (POLAR.min + POLAR.max) / 2;
    expect(clampPolar(mid)).toBe(mid);
  });
});

describe('sineEaseInOut — the ~900ms dolly easing', () => {
  it('pins the ends and the midpoint', () => {
    expect(sineEaseInOut(0)).toBeCloseTo(0);
    expect(sineEaseInOut(1)).toBeCloseTo(1);
    expect(sineEaseInOut(0.5)).toBeCloseTo(0.5);
  });
  it('rises monotonically and stays within [0,1]', () => {
    let prev = -1;
    for (let t = 0; t <= 1; t += 0.05) {
      const v = sineEaseInOut(t);
      expect(v).toBeGreaterThanOrEqual(prev);
      expect(v).toBeGreaterThanOrEqual(0);
      expect(v).toBeLessThanOrEqual(1);
      prev = v;
    }
  });
  it('is flat outside [0,1]', () => {
    expect(sineEaseInOut(-2)).toBe(0);
    expect(sineEaseInOut(3)).toBe(1);
  });
});

describe('spherical round-trip', () => {
  it('recovers an offset vector through toSpherical → fromSpherical', () => {
    for (const v of [[6.2, 3.85, 9.2], [1, 2, 3], [-4, 1, 2]] as Vec3[]) {
      const s = toSpherical(v);
      const back = fromSpherical(s.radius, s.polar, s.azimuth);
      expect(back[0]).toBeCloseTo(v[0]);
      expect(back[1]).toBeCloseTo(v[1]);
      expect(back[2]).toBeCloseTo(v[2]);
    }
  });
});

describe('derivePreset — a closer framing centred on the region anchor', () => {
  const anchor: Vec3 = [1.2, 0.7, 0];
  const preset = derivePreset(anchor);

  it('targets the region anchor exactly (the view goes TO the thing)', () => {
    expect(preset.target).toEqual(anchor);
  });

  it('sits closer than the home orbit, at the dolly radius', () => {
    const homeRadius = dist(HOME.position, HOME.target);
    const presetRadius = dist(preset.position, preset.target);
    expect(presetRadius).toBeCloseTo(homeRadius * DOLLY_RADIUS_FACTOR);
    expect(presetRadius).toBeLessThan(homeRadius);
  });

  it('keeps the tilt within the polar clamps', () => {
    const offset: Vec3 = [preset.position[0] - anchor[0], preset.position[1] - anchor[1], preset.position[2] - anchor[2]];
    const { polar } = toSpherical(offset);
    expect(polar).toBeGreaterThanOrEqual(POLAR.min);
    expect(polar).toBeLessThanOrEqual(POLAR.max);
  });
});

describe('presetForRegion — every region on every body class', () => {
  const classes = ['hatchback', 'berline', 'suv', 'utilitaire', 'moto'] as const;

  it('targets that region’s 3D anchor and stays within the clamps', () => {
    for (const bc of classes) {
      const anchors = regionAnchors(bc);
      for (const region of BODY_REGIONS) {
        const preset = presetForRegion(bc, region);
        expect(preset.target).toEqual(anchors[region]);
        const offset: Vec3 = [preset.position[0] - preset.target[0], preset.position[1] - preset.target[1], preset.position[2] - preset.target[2]];
        const { polar } = toSpherical(offset);
        expect(polar).toBeGreaterThanOrEqual(POLAR.min - 1e-9);
        expect(polar).toBeLessThanOrEqual(POLAR.max + 1e-9);
      }
    }
  });

  it('gives head-to-tail regions distinct camera targets (the move reads as spatial)', () => {
    const hood = presetForRegion('berline', 'hood');
    const rear = presetForRegion('berline', 'rearAxle');
    expect(hood.target).not.toEqual(rear.target);
    expect(hood.position).not.toEqual(rear.position);
  });
});

describe('gateReduce — earned-adaptation intent gates', () => {
  const focusA = { region: 'hood' as const, key: 1 };
  const focusB = { region: 'rearAxle' as const, key: 2 };

  it('adopts a request as the held target when idle', () => {
    const s = gateReduce(GATE_INIT, { type: 'request', focus: focusA });
    expect(s.held).toEqual(focusA);
    expect(s.queued).toBeNull();
  });

  it('never moves mid-drag — it queues the request instead', () => {
    const dragging: GateState = { held: null, queued: null, dragging: true };
    const s = gateReduce(dragging, { type: 'request', focus: focusA });
    expect(s.held).toBeNull(); // no move while the user orbits
    expect(s.queued).toEqual(focusA);
  });

  it('a user drag breaks an active hold (their input wins)', () => {
    const held: GateState = { held: focusA, queued: null, dragging: false };
    expect(dragBreaksHold(held)).toBe(true);
    const s = gateReduce(held, { type: 'dragStart' });
    expect(s.held).toBeNull();
    expect(s.dragging).toBe(true);
  });

  it('does not report a broken hold when nothing was held or queued', () => {
    expect(dragBreaksHold(GATE_INIT)).toBe(false);
  });

  it('promotes a queued request when the drag ends', () => {
    const midDrag = gateReduce({ held: null, queued: null, dragging: true }, { type: 'request', focus: focusB });
    const released = gateReduce(midDrag, { type: 'dragEnd' });
    expect(released.dragging).toBe(false);
    expect(released.held).toEqual(focusB);
    expect(released.queued).toBeNull();
  });

  it('returns home when a drag ends with nothing queued', () => {
    const released = gateReduce({ held: null, queued: null, dragging: true }, { type: 'dragEnd' });
    expect(released.held).toBeNull();
  });
});
