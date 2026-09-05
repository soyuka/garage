import { describe, it, expect } from 'vitest';
import {
  AGENT_LIGHT_DISTANCE,
  AGENT_LIGHT_LEVELS,
  DISC,
  EMITTER,
  LENS_HDR,
  ROOM_AMBIENT,
  ROOM_AMBIENT_COLOR,
  SPILL_DISTANCE,
  agentLightOf,
  blend01,
  irradianceAt,
  lightIntensity,
  lightTarget,
} from './agentLight';
import { HOLO_CYAN, NEON_RED } from './signalColors';

// The hologram is the light (CHOREOGRAPHY § "The hologram is the light"): Le Noyau reaches the Canvas as
// ONE primitive, the agent light carries phase as intensity and result as colour, and its reach is pinned
// so the pool stays inside the rim — the rim is the car's surface, the pool is the agent's.

describe('agentLightOf', () => {
  it('maps every Noyau phase to the one primitive the Canvas receives', () => {
    expect(agentLightOf({ phase: 'idle', result: null, quiet: false })).toBe('rest');
    expect(agentLightOf({ phase: 'listening', result: null, quiet: false })).toBe('rest');
    expect(agentLightOf({ phase: 'working', result: null, quiet: false })).toBe('working');
    expect(agentLightOf({ phase: 'working', result: null, quiet: true })).toBe('quiet');
    expect(agentLightOf({ phase: 'done', result: 'amber', quiet: false })).toBe('done:amber');
    expect(agentLightOf({ phase: 'done', result: null, quiet: false })).toBe('done:green');
  });
});

describe('lightTarget', () => {
  it('rests cyan at the rest lens level', () => {
    const t = lightTarget('rest');
    expect(t.hex).toBe(HOLO_CYAN);
    expect(t.lens).toBe(LENS_HDR.rest);
    expect(t.base).toBe(AGENT_LIGHT_LEVELS.rest.base);
  });
  it('holds a red result steady in the podium red — the filament does not breathe', () => {
    const t = lightTarget('done:red');
    expect(t.hex).toBe(NEON_RED);
    expect(t.amp).toBe(0);
    expect(t.lens).toBe(LENS_HDR.done);
  });
  it('orders the levels: working above quiet above rest', () => {
    expect(lightTarget('working').base).toBeGreaterThan(lightTarget('quiet').base);
    expect(lightTarget('quiet').base).toBeGreaterThan(lightTarget('rest').base);
  });
  it('is a lookup, not a build: the same frozen object every call (the rig reads it per frame)', () => {
    expect(lightTarget('rest')).toBe(lightTarget('rest'));
    expect(lightTarget('done:amber')).toBe(lightTarget('done:amber'));
    expect(Object.isFrozen(lightTarget('working'))).toBe(true);
  });
});

// Seen 2026-09-04 against the dark room (review-rest.jpeg): at metalness 0.6 the disc gave the agent light
// only a specular lobe, and an overhead light's lobe points away from the HOME camera — the pool read as
// black. The pool is a DIFFUSE term: a matte dark disc, and levels that put its centre at a visible
// ≈ 0.05 linear (CHOREOGRAPHY § The hologram is the light, "Also in this change", corrected 2026-09-04).
describe('the pool is diffuse', () => {
  it('pins the matte disc', () => {
    expect(DISC).toEqual({ color: '#232a33', metalness: 0.2, roughness: 0.7 });
  });
  it('pins the levels that make the pool visible on it', () => {
    expect(AGENT_LIGHT_LEVELS).toEqual({
      rest: { base: 28, amp: 4 },
      quiet: { base: 36, amp: 4 },
      working: { base: 50, amp: 8 },
      done: { base: 50, amp: 0 },
    });
  });
  it('puts the resting lens just over the bloom threshold (0.5) so the emitters read as emitters', () => {
    // HOLO_CYAN #5fe0d9 luminance ≈ 0.77 (Rec. 709 weights on the sRGB channels, as Bloom measures it); 0.55 × 0.77 = 0.42 sat under it
    expect(LENS_HDR.rest).toBe(0.75);
    expect(LENS_HDR.rest * 0.77).toBeGreaterThan(0.5);
    expect(LENS_HDR.rest).toBeLessThan(LENS_HDR.quiet);
  });
  it('lifts the housing so it catches the spill instead of vanishing into the disc', () => {
    expect(EMITTER.housing.color).toBe('#2c3440');
    expect(EMITTER.housing.metalness).toBe(0.3);
  });
});

describe('blend01', () => {
  it('cross-fades over the filament step, and cuts under reduced motion', () => {
    expect(blend01(0, false)).toBe(0);
    expect(blend01(0.3, false)).toBeCloseTo(0.5);
    expect(blend01(9, false)).toBe(1);
    expect(blend01(0, true)).toBe(1);
  });
});

describe('lightIntensity', () => {
  it('is base + amp · breathe', () => {
    expect(lightIntensity({ hex: HOLO_CYAN, base: 16, amp: 4, lens: 1 }, 0.5)).toBe(18);
  });
});

describe('the dark room', () => {
  it('pins the cool, dim ambient — the mat reads as black mass, its grid faintly', () => {
    expect(ROOM_AMBIENT).toBe(0.5);
    expect(ROOM_AMBIENT_COLOR).toBe('#c8d4e0');
  });
});

describe('confinement', () => {
  it('the agent light dies at the rim: irradiance at r 3.11 is under 6 % of r 1', () => {
    const rim = irradianceAt(3.11, 1.6, AGENT_LIGHT_DISTANCE);
    const inner = irradianceAt(1, 1.6, AGENT_LIGHT_DISTANCE);
    expect(rim / inner).toBeLessThan(0.06);
  });
  it('the spill reaches the mat ring softly, never bleaching it', () => {
    const ratio = irradianceAt(6, 3, SPILL_DISTANCE) / irradianceAt(3.3, 3, SPILL_DISTANCE);
    expect(ratio).toBeGreaterThan(0.2);
    expect(ratio).toBeLessThan(0.6);
  });
  it('seats the emitters inside the rim', () => {
    expect(EMITTER.radius).toBeLessThan(3.11);
  });
});
