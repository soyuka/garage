import { describe, it, expect } from 'vitest';
import { SIGNAL_HEX, NEUTRAL_HEX, BLUEPRINT_EDGE } from './signalColors';
import type { Signal } from '../types';

// The JS-side colour map for THREE materials (RimGauge / BodyGlow), mirroring the --at-*-hi CSS tokens
// so 3D glow and the glass panels read the same signal palette. (CSS can't reach into a material.)

describe('SIGNAL_HEX', () => {
  it('covers every signal with a 6-digit hex', () => {
    (['green', 'amber', 'red'] as Signal[]).forEach((s) => {
      expect(SIGNAL_HEX[s], s).toMatch(/^#[0-9a-f]{6}$/i);
    });
  });
  it('pins the blueprint edge to the locked steel-blue (NOT the agent cyan)', () => {
    expect(BLUEPRINT_EDGE).toBe('#a8c4dc');
    expect(NEUTRAL_HEX).toMatch(/^#[0-9a-f]{6}$/i);
  });
});
