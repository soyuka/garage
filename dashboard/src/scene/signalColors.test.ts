import { describe, it, expect } from 'vitest';
import { SIGNAL_HEX, NEUTRAL_HEX, BLUEPRINT_EDGE, HOLO_CYAN } from './signalColors';
import type { Signal } from '../types';

// The JS-side colour map for THREE materials (RimGauge / BodyGlow), mirroring the --at-*-hi CSS tokens
// so 3D glow and the glass panels read the same signal palette. (CSS can't reach into a material.)

describe('SIGNAL_HEX', () => {
  it('covers every signal with a 6-digit hex', () => {
    (['green', 'amber', 'red'] as Signal[]).forEach((s) => {
      expect(SIGNAL_HEX[s], s).toMatch(/^#[0-9a-f]{6}$/i);
    });
  });
  it('pins the blueprint edge to the locked electric blue (NOT the agent cyan)', () => {
    expect(BLUEPRINT_EDGE).toBe('#6d9eff');
    expect(NEUTRAL_HEX).toMatch(/^#[0-9a-f]{6}$/i);
  });
  it('pins the holo cyan — the agent\'s light (scan, pulse, the stage lights, the emitter lenses)', () => {
    expect(HOLO_CYAN).toBe('#5fe0d9');
  });
});
