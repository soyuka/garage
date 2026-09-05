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
  REGION_VIEWS,
  REVEAL_VIEWS,
  HISTORY_VIEW,
  DATA_VIEW,
  FLUIDS_VIEW,
  LEGEND_SHIFT,
  DOLLY_RADIUS_FACTOR,
  clampPolar,
  sineEaseInOut,
  toSpherical,
  fromSpherical,
  derivePreset,
  presetForRegion,
  presetForFocus,
  historyPose,
  dataPose,
  fluidsPose,
  gateReduce,
  dragBreaksHold,
  deriveCameraFocus,
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

/** The spherical offset of a pose from its own target — the framing, stripped of where the car is. */
function framing(pose: { position: Vec3; target: Vec3 }) {
  return toSpherical([pose.position[0] - pose.target[0], pose.position[1] - pose.target[1], pose.position[2] - pose.target[2]]);
}

describe('derivePreset — a closer framing centred on the region anchor', () => {
  const anchor: Vec3 = [1.2, 0.7, 0];
  const view = { azimuth: 0, polar: toSpherical([HOME.position[0], HOME.position[1] - HOME.target[1], HOME.position[2]]).polar, factor: DOLLY_RADIUS_FACTOR };
  const preset = derivePreset(anchor, view);

  it('targets the region anchor exactly (the view goes TO the thing)', () => {
    expect(preset.target).toEqual(anchor);
  });

  it('sits closer than the home orbit, at the view’s radius factor', () => {
    const homeRadius = dist(HOME.position, HOME.target);
    expect(framing(preset).radius).toBeCloseTo(homeRadius * DOLLY_RADIUS_FACTOR);
    expect(framing(preset).radius).toBeLessThan(homeRadius);
  });

  it('keeps the tilt within the polar clamps', () => {
    const { polar } = framing(preset);
    expect(polar).toBeGreaterThanOrEqual(POLAR.min);
    expect(polar).toBeLessThanOrEqual(POLAR.max);
  });

  it('applies the view’s azimuth as an OFFSET from the home viewing angle', () => {
    const homeAz = toSpherical([HOME.position[0] - HOME.target[0], HOME.position[1] - HOME.target[1], HOME.position[2] - HOME.target[2]]).azimuth;
    const swung = derivePreset(anchor, { ...view, azimuth: 0.3 });
    expect(framing(swung).azimuth).toBeCloseTo(homeAz + 0.3);
  });
});

// C2 — the angle IS the region (CHOREOGRAPHY § "The region pose"). A preset that kept the home azimuth
// AND the home polar for every region produced one shot with five targets: the framing carried no datum.

describe('REGION_VIEWS — one view per region, all reachable', () => {
  it('covers every region', () => {
    for (const region of BODY_REGIONS) expect(REGION_VIEWS[region]).toBeDefined();
  });

  it('stays inside the resting polar band — a focus never leaves the operator’s own orbit', () => {
    for (const region of BODY_REGIONS) {
      expect(REGION_VIEWS[region].polar).toBeGreaterThanOrEqual(POLAR.min);
      expect(REGION_VIEWS[region].polar).toBeLessThanOrEqual(POLAR.max);
    }
  });

  it('frames a wheel closer than the greenhouse — the radius encodes the part’s size', () => {
    expect(REGION_VIEWS.frontAxle.factor).toBeLessThan(REGION_VIEWS.windshield.factor);
  });

  it('reads the bay from higher up than an axle — polar encodes "looking into" vs "looking at"', () => {
    expect(REGION_VIEWS.bay.polar).toBeLessThan(REGION_VIEWS.frontAxle.polar);
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
        const { polar } = framing(preset);
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

  it('gives them distinct FRAMINGS too, not just distinct targets — the shot says which region', () => {
    const hood = framing(presetForRegion('berline', 'hood'));
    const rear = framing(presetForRegion('berline', 'rearAxle'));
    expect(Math.abs(hood.azimuth - rear.azimuth)).toBeGreaterThan(0.15);
    expect(Math.abs(hood.polar - rear.polar)).toBeGreaterThan(0.15);
  });

  it('swings toward the end of the car it names — front regions one way, rear the other', () => {
    const homeAz = toSpherical([HOME.position[0] - HOME.target[0], HOME.position[1] - HOME.target[1], HOME.position[2] - HOME.target[2]]).azimuth;
    expect(framing(presetForRegion('berline', 'frontAxle')).azimuth).toBeGreaterThan(homeAz);
    expect(framing(presetForRegion('berline', 'rearAxle')).azimuth).toBeLessThan(homeAz);
  });
});

describe('presetForFocus — the reveal pose family', () => {
  // C2 planned a widened polar floor for the reveal. The readable pose turned out to sit inside the
  // resting band, so the widening was deleted rather than kept as headroom — this asserts it STAYS
  // deleted: a reveal that drifts back outside the band silently re-introduces the OrbitControls snap
  // the widening existed to avoid, and nothing else in the app would notice.
  it('every reveal view is reachable without widening the resting band', () => {
    for (const region of BODY_REGIONS) {
      expect(REVEAL_VIEWS[region].polar).toBeGreaterThanOrEqual(POLAR.min);
      expect(REVEAL_VIEWS[region].polar).toBeLessThanOrEqual(POLAR.max);
    }
  });

  it('descends over the bay — steeper than the same region’s focus pose', () => {
    const focus = framing(presetForFocus('berline', 'bay', 'focus'));
    const reveal = framing(presetForFocus('berline', 'bay', 'reveal'));
    expect(reveal.polar).toBeLessThan(focus.polar);
  });

  it('stands FURTHER back than the focus pose — a reveal keeps the car, it does not crop to the part', () => {
    for (const region of BODY_REGIONS) {
      expect(REVEAL_VIEWS[region].factor).toBeGreaterThan(REGION_VIEWS[region].factor);
    }
  });

  it('a reveal still targets the region anchor — the diagram sits where the part does', () => {
    const anchors = regionAnchors('berline');
    expect(presetForFocus('berline', 'hood', 'reveal').target).toEqual(anchors.hood);
  });

  it('presetForRegion is the focus mode (the existing call sites keep their meaning)', () => {
    expect(presetForRegion('suv', 'hood')).toEqual(presetForFocus('suv', 'hood', 'focus'));
  });
});

describe('historyPose — the visit-ring station camera', () => {
  const homeRadius = dist(HOME.position, HOME.target);
  const homeAz = toSpherical([HOME.position[0] - HOME.target[0], HOME.position[1] - HOME.target[1], HOME.position[2] - HOME.target[2]]).azimuth;
  const pose = historyPose();

  it('pulls back to distanceFactor × the home radius', () => {
    expect(framing(pose).radius).toBeCloseTo(homeRadius * HISTORY_VIEW.distanceFactor);
  });

  it('keeps the raised polar within the OrbitControls band', () => {
    const { polar } = framing(pose);
    expect(polar).toBeGreaterThanOrEqual(POLAR.min);
    expect(polar).toBeLessThanOrEqual(POLAR.max);
  });

  it('keeps the same azimuth as home — the ring does not swing around the car', () => {
    expect(framing(pose).azimuth).toBeCloseTo(homeAz);
  });

  it('shifts the target along the RIGHT vector so the car lands left of centre (Carnet docks right)', () => {
    const right: Vec3 = [Math.cos(homeAz), 0, -Math.sin(homeAz)];
    const dot = pose.target[0] * right[0] + pose.target[2] * right[2];
    expect(dot).toBeCloseTo(HISTORY_VIEW.rightShift);
  });

  it('is presetForFocus’s history mode', () => {
    expect(presetForFocus('berline', undefined, 'history')).toEqual(historyPose());
  });

  it('defaults to the "column" legend shift — HISTORY_VIEW’s own rightShift', () => {
    expect(HISTORY_VIEW.rightShift).toBe(LEGEND_SHIFT.column);
  });

  it('the "projection" legend narrows the target shift to the fluids row’s width', () => {
    const narrow = historyPose(HOME, 'projection');
    const right: Vec3 = [Math.cos(homeAz), 0, -Math.sin(homeAz)];
    const dot = narrow.target[0] * right[0] + narrow.target[2] * right[2];
    expect(dot).toBeCloseTo(LEGEND_SHIFT.projection);
    expect(presetForFocus('berline', undefined, 'history', 'projection')).toEqual(narrow);
  });
});

describe('dataPose — the fact-constellation station camera', () => {
  const homeRadius = dist(HOME.position, HOME.target);
  const homeAz = toSpherical([HOME.position[0] - HOME.target[0], HOME.position[1] - HOME.target[1], HOME.position[2] - HOME.target[2]]).azimuth;
  const pose = dataPose();

  it('pulls back to distanceFactor × the home radius', () => {
    expect(framing(pose).radius).toBeCloseTo(homeRadius * DATA_VIEW.distanceFactor);
  });

  it('keeps the same azimuth as home — the constellation does not swing around the car', () => {
    expect(framing(pose).azimuth).toBeCloseTo(homeAz);
  });

  it('is presetForFocus’s data mode', () => {
    expect(presetForFocus('berline', undefined, 'data')).toEqual(dataPose());
  });

  it('the "projection" legend narrows the target shift to the fluids row’s width', () => {
    const narrow = dataPose(HOME, 'projection');
    const right: Vec3 = [Math.cos(homeAz), 0, -Math.sin(homeAz)];
    const dot = narrow.target[0] * right[0] + narrow.target[2] * right[2];
    expect(dot).toBeCloseTo(LEGEND_SHIFT.projection);
    expect(presetForFocus('berline', undefined, 'data', 'projection')).toEqual(narrow);
  });
});

describe('fluidsPose — the fluid-volumes station camera', () => {
  const homeRadius = dist(HOME.position, HOME.target);
  const homeAz = toSpherical([HOME.position[0] - HOME.target[0], HOME.position[1] - HOME.target[1], HOME.position[2] - HOME.target[2]]).azimuth;
  const pose = fluidsPose();

  it('pulls back to distanceFactor × the home radius', () => {
    expect(framing(pose).radius).toBeCloseTo(homeRadius * FLUIDS_VIEW.distanceFactor);
  });

  it('keeps the raised polar within the OrbitControls band', () => {
    const { polar } = framing(pose);
    expect(polar).toBeGreaterThanOrEqual(POLAR.min);
    expect(polar).toBeLessThanOrEqual(POLAR.max);
  });

  it('keeps the same azimuth as home — the row does not swing around the car', () => {
    expect(framing(pose).azimuth).toBeCloseTo(homeAz);
  });

  it('shifts the target along the RIGHT vector, less than the legends’ (clears the specs column only)', () => {
    const right: Vec3 = [Math.cos(homeAz), 0, -Math.sin(homeAz)];
    const dot = pose.target[0] * right[0] + pose.target[2] * right[2];
    expect(dot).toBeCloseTo(FLUIDS_VIEW.rightShift);
    expect(FLUIDS_VIEW.rightShift).toBeLessThan(HISTORY_VIEW.rightShift);
  });

  it('is presetForFocus’s fluids mode', () => {
    expect(presetForFocus('berline', undefined, 'fluids')).toEqual(fluidsPose());
  });
});

describe('deriveCameraFocus — a drag releases a station hold', () => {
  const held = { region: 'hood' as const, key: 1 };

  it('an explicit hold always wins over a station', () => {
    expect(deriveCameraFocus(held, true, 'history', false)).toEqual(held);
    expect(deriveCameraFocus(held, true, 'history', true)).toEqual(held);
  });

  it('Historique open with nothing else held earns the history pose', () => {
    expect(deriveCameraFocus(null, true, 'history', false)).toEqual({ key: 0, mode: 'history' });
  });

  it('a drag that already released the hold keeps the camera free while Historique stays open', () => {
    expect(deriveCameraFocus(null, true, 'history', true)).toBeNull();
  });

  it('closed Historique is free regardless of the released flag', () => {
    expect(deriveCameraFocus(null, false, 'history', false)).toBeNull();
    expect(deriveCameraFocus(null, false, 'history', true)).toBeNull();
  });

  it('the Données drawer open with nothing else held earns the data pose', () => {
    expect(deriveCameraFocus(null, true, 'data', false)).toEqual({ key: 0, mode: 'data' });
  });

  it('a drag that already released the hold keeps the camera free while Données stays open', () => {
    expect(deriveCameraFocus(null, true, 'data', true)).toBeNull();
  });

  it('an explicit hold still wins over the data station', () => {
    expect(deriveCameraFocus(held, true, 'data', false)).toEqual(held);
  });

  it('the fluids station with nothing else held earns the fluids pose', () => {
    expect(deriveCameraFocus(null, true, 'fluids', false)).toEqual({ key: 0, mode: 'fluids' });
  });

  it('a drag that already released the hold keeps the camera free while fluids stays open', () => {
    expect(deriveCameraFocus(null, true, 'fluids', true)).toBeNull();
  });

  it('an explicit hold still wins over the fluids station', () => {
    expect(deriveCameraFocus(held, true, 'fluids', false)).toEqual(held);
  });

  it('a carnet focus with nothing else held earns the history pose, narrowed to the projection shift', () => {
    expect(deriveCameraFocus(null, true, 'carnet', false)).toEqual({ key: 0, mode: 'history', legend: 'projection' });
  });

  it('a drag that already released the hold keeps the camera free while a carnet focus stands', () => {
    expect(deriveCameraFocus(null, true, 'carnet', true)).toBeNull();
  });

  it('an explicit hold still wins over the carnet station', () => {
    expect(deriveCameraFocus(held, true, 'carnet', false)).toEqual(held);
  });

  it('a specs-keys focus with nothing else held earns the data pose, narrowed to the projection shift', () => {
    expect(deriveCameraFocus(null, true, 'specs', false)).toEqual({ key: 0, mode: 'data', legend: 'projection' });
  });

  it('a drag that already released the hold keeps the camera free while a specs focus stands', () => {
    expect(deriveCameraFocus(null, true, 'specs', true)).toBeNull();
  });

  it('an explicit hold still wins over the specs station', () => {
    expect(deriveCameraFocus(held, true, 'specs', false)).toEqual(held);
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
