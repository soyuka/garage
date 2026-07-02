import { describe, it, expect } from 'vitest';
import { breathe01, podiumGlow } from './holo';
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
