import { describe, it, expect } from 'vitest';
import { breathe01, podiumGlow, pulseEnvelope, regionAxisFraction, PULSE_DURATION } from './holo';
import { NEON_RED, NEUTRAL_HEX, SIGNAL_HEX } from './signalColors';
import type { ForecastDue } from '../types';

// The holo podium (halo ring, light shafts, particles) and the rim arcs all breathe on ONE shared
// curve — the apple sleep-LED sine (~4s period) — so the whole stage inhales together. breathe01 is
// that curve; podiumGlow picks the podium's ambient color from the car's overall state.

function due(over: Partial<ForecastDue> = {}): ForecastDue {
  return {
    type: 'vidange', action: null, component: null, rule: 'schedule', lastPerformedAt: '2024-11-15',
    dueAt: null, projectedDueAt: null, overdue: false, daysUntilDue: 365, usage: null, ...over,
  };
}

describe('breathe01', () => {
  it('starts mid-swell and completes a 4s period', () => {
    expect(breathe01(0)).toBeCloseTo(0.5);
    expect(breathe01(4)).toBeCloseTo(0.5);
  });

  it('peaks at 1 at the quarter period and troughs at 0 at three quarters', () => {
    expect(breathe01(1)).toBeCloseTo(1);
    expect(breathe01(3)).toBeCloseTo(0);
  });

  it('stays within [0, 1] for arbitrary times', () => {
    for (const t of [0.3, 2.7, 13.37, 111.1]) {
      const v = breathe01(t);
      expect(v).toBeGreaterThanOrEqual(0);
      expect(v).toBeLessThanOrEqual(1);
    }
  });
});

describe('podiumGlow', () => {
  it('burns neon red as soon as any échéance is overdue, whatever the summary status says', () => {
    expect(podiumGlow('green', [due(), due({ overdue: true })])).toBe(NEON_RED);
    expect(podiumGlow('red', [due({ overdue: true })])).toBe(NEON_RED);
  });

  it('warms to amber when the summary says due-soon but nothing is overdue yet', () => {
    expect(podiumGlow('amber', [due({ daysUntilDue: 12 })])).toBe(SIGNAL_HEX.amber);
  });

  it('rests green when healthy', () => {
    expect(podiumGlow('green', [due()])).toBe(SIGNAL_HEX.green);
    expect(podiumGlow('green', [])).toBe(SIGNAL_HEX.green);
  });

  it('stays neutral when the car is untracked', () => {
    expect(podiumGlow('unknown', [])).toBe(NEUTRAL_HEX);
  });
});

// PULSE = the agent acts on a region: one localized cyan flare, eased in and out over ~PULSE_DURATION,
// centred on the region's slice along the car's long axis. pulseEnvelope is that one-shot amplitude
// curve; regionAxisFraction is where along the swept axis (nose=+1, tail=-1) the flare sits.
describe('pulseEnvelope', () => {
  it('is silent at both ends of the pulse and peaks at the midpoint', () => {
    expect(pulseEnvelope(0, PULSE_DURATION)).toBeCloseTo(0);
    expect(pulseEnvelope(PULSE_DURATION, PULSE_DURATION)).toBeCloseTo(0);
    expect(pulseEnvelope(PULSE_DURATION / 2, PULSE_DURATION)).toBeCloseTo(1);
  });

  it('eases in — rises monotonically over the first half', () => {
    let prev = -1;
    for (let t = 0; t <= PULSE_DURATION / 2; t += PULSE_DURATION / 20) {
      const v = pulseEnvelope(t, PULSE_DURATION);
      expect(v).toBeGreaterThanOrEqual(prev);
      prev = v;
    }
  });

  it('is zero outside the pulse window and always within [0,1]', () => {
    expect(pulseEnvelope(-0.5, PULSE_DURATION)).toBe(0);
    expect(pulseEnvelope(PULSE_DURATION + 0.5, PULSE_DURATION)).toBe(0);
    for (const t of [0.1, 0.37, 0.9, 1.3]) {
      const v = pulseEnvelope(t, PULSE_DURATION);
      expect(v).toBeGreaterThanOrEqual(0);
      expect(v).toBeLessThanOrEqual(1);
    }
  });

  it('defaults to PULSE_DURATION when no duration is given', () => {
    expect(pulseEnvelope(PULSE_DURATION / 2)).toBeCloseTo(1);
    expect(pulseEnvelope(PULSE_DURATION)).toBeCloseTo(0);
  });
});

describe('regionAxisFraction — where a region sits along the car (nose=+1, tail=-1)', () => {
  it('puts front regions ahead of the rear axle', () => {
    expect(regionAxisFraction('frontAxle')).toBeGreaterThan(regionAxisFraction('rearAxle'));
    expect(regionAxisFraction('hood')).toBeGreaterThan(regionAxisFraction('windshield'));
    expect(regionAxisFraction('windshield')).toBeGreaterThan(regionAxisFraction('rearAxle'));
  });

  it('keeps every region within the half-length span [-1,1]', () => {
    for (const r of ['hood', 'bay', 'windshield', 'frontAxle', 'rearAxle'] as const) {
      const f = regionAxisFraction(r);
      expect(f).toBeGreaterThanOrEqual(-1);
      expect(f).toBeLessThanOrEqual(1);
    }
  });
});

// ── C1: the ghost ──────────────────────────────────────────────────────────────

import { GHOST_EDGE_FLOOR, ghostTarget, ghostStep } from './holo';

describe('ghostTarget — the shell dissolves as the assembly arrives', () => {
  it('is 0 with nothing expected (no reveal → no ghost, the parc thumbs are untouched)', () => {
    expect(ghostTarget(0, 0)).toBe(0);
    expect(ghostTarget(3, 0)).toBe(0);
  });

  it('tracks the fraction of parts on screen, so a slow lookup dissolves slowly', () => {
    expect(ghostTarget(0, 4)).toBe(0);
    expect(ghostTarget(1, 4)).toBeCloseTo(0.25);
    expect(ghostTarget(2, 4)).toBeCloseTo(0.5);
    expect(ghostTarget(4, 4)).toBe(1);
  });

  it('never exceeds a full ghost even if more parts land than were expected', () => {
    expect(ghostTarget(9, 4)).toBe(1);
  });

  it('leaves the edges standing at the floor — a fully ghosted shell is still a shape', () => {
    expect(GHOST_EDGE_FLOOR).toBeGreaterThan(0);
    expect(GHOST_EDGE_FLOOR).toBeLessThan(1);
  });
});

describe('ghostStep — the eased approach, clock injected', () => {
  it('moves toward the target and never past it', () => {
    let v = 0;
    for (let i = 0; i < 200; i++) v = ghostStep(v, 1, 1 / 60);
    expect(v).toBeCloseTo(1);
    expect(v).toBeLessThanOrEqual(1);
  });

  it('comes back down the same way', () => {
    let v = 1;
    for (let i = 0; i < 200; i++) v = ghostStep(v, 0, 1 / 60);
    expect(v).toBeCloseTo(0);
    expect(v).toBeGreaterThanOrEqual(0);
  });

  it('is monotone toward the target — no overshoot, ever', () => {
    let v = 0;
    let prev = -1;
    for (let i = 0; i < 40; i++) {
      v = ghostStep(v, 1, 1 / 60);
      expect(v).toBeGreaterThanOrEqual(prev);
      prev = v;
    }
  });

  it('clamps a tab-restore hitch instead of jumping the whole way', () => {
    expect(ghostStep(0, 1, 30)).toBeLessThanOrEqual(1);
    expect(ghostStep(0, 1, 30)).toBeGreaterThan(0);
  });

  it('snaps once it is within a pixel of the target (no infinite asymptote in useFrame)', () => {
    expect(ghostStep(0.9999, 1, 1 / 60)).toBe(1);
  });
});
