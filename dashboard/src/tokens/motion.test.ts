import { describe, it, expect } from 'vitest';
import { DOLLY_SECONDS } from '../scene/cameraRig';
import { FLICKER_SECONDS, PULSE_DURATION, RISE_IN_SECONDS } from '../scene/holo';
import { FILAMENT_SECONDS } from '../scene/agentLight';
import { RISE_SECONDS, CASCADE_SECONDS } from '../scene/VisitRing';
import { ETCH_SECONDS, FAN_SECONDS } from '../scene/FactConstellation';
import { FLUID_RISE_SECONDS, FLUID_FAN_SECONDS } from '../scene/FluidVolumes';
import atelier from './atelier.css?raw';
import noyau from '../noyau/noyau.css?raw';
import { DWELL, MOTION } from './motion';

// Every stylesheet under src/, read as text. The Noyau instrument's own `--nx-*` layer is exempt:
// atelier.css documents it as a separate scale that migrates onto these tokens later.
const SHEETS = import.meta.glob<string>('../**/*.css', { query: '?raw', import: 'default', eager: true });
const COMPONENT_SHEETS = Object.entries(SHEETS).filter(([path]) => !path.endsWith('tokens/atelier.css') && !path.endsWith('noyau/noyau.css'));

/** `140ms` / `0.4s` → milliseconds. */
function ms(literal: string): number {
  return literal.endsWith('ms') ? parseFloat(literal) : parseFloat(literal) * 1000;
}

describe('the motion scale', () => {
  it('declares every --at-dur-* token with the value MOTION names', () => {
    const declared = [...atelier.matchAll(/--at-dur-([a-z]+):\s*([\d.]+m?s);/g)].map(([, name, value]) => [name, ms(value)] as const);
    expect(declared.length).toBeGreaterThan(0);
    for (const [name, value] of declared) {
      expect(MOTION, `--at-dur-${name} has no MOTION twin`).toHaveProperty(name);
      expect(value, `--at-dur-${name}`).toBe(MOTION[name as keyof typeof MOTION]);
    }
  });

  /**
   * `noyau.css` is exempt from the token sweep above (its `--nx-*` layer migrates later), but this one
   * relationship crosses the two files and broke silently: the red fault lamp ran `0.9s infinite`
   * inside a 1600 ms `done` dwell, so it was cut mid-fade at ~half opacity EVERY time — a lamp that
   * could never finish a blink, which reads as a rendering artifact rather than a signal (reported
   * live 2026-09-11). Whichever side moves, the blinks must still land exactly on the dwell.
   */
  it('lets the red fault lamp finish whole blinks inside the done dwell', () => {
    const shorthand = /animation:\s*nx-fault\s+([\d.]+m?s)\s+[a-z-]+\s+(\d+)\s*;/.exec(noyau);

    expect(shorthand, 'the nx-fault animation is no longer `<duration> <easing> <iterations>;`').not.toBeNull();
    const [, duration, iterations] = shorthand!;
    expect(ms(duration) * Number(iterations)).toBe(DWELL.settle);
  });

  it('keeps the JS-side motions (they run in useFrame, not CSS) on the same scale', () => {
    expect(DOLLY_SECONDS * 1000).toBe(MOTION.dolly);
    expect(PULSE_DURATION * 1000).toBe(MOTION.pulse);
    expect(FILAMENT_SECONDS * 1000).toBe(MOTION.filament);
    expect(FLICKER_SECONDS * 1000).toBe(MOTION.etch);
    expect(RISE_IN_SECONDS * 1000).toBe(MOTION.cascade);
    expect(RISE_SECONDS * 1000).toBe(MOTION.rise);
    expect(CASCADE_SECONDS * 1000).toBe(MOTION.cascade);
    expect(ETCH_SECONDS * 1000).toBe(MOTION.etch);
    expect(FAN_SECONDS * 1000).toBe(MOTION.fan);
    expect(FLUID_RISE_SECONDS * 1000).toBe(MOTION.rise);
    expect(FLUID_FAN_SECONDS * 1000).toBe(MOTION.fan);
  });

  it('preserves Le Noyau’s ~4s sleeping-Mac breathe as the signature period', () => {
    expect(MOTION.breathe).toBe(4000);
    expect(atelier).toContain('--at-breathe: 4s;');
  });

  // The map is the contract: a one-shot duration is a scale step, so no component may spell one out.
  // A loop PERIOD is a frequency (a different quantity — the breathe, the scan drift) and is exempt.
  it('leaves no ad-hoc one-shot duration in any component stylesheet', () => {
    const offenders: string[] = [];
    for (const [path, css] of COMPONENT_SHEETS) {
      css.split('\n').forEach((line, i) => {
        for (const [, decl] of line.matchAll(/(?:transition|animation)(?:-duration)?:\s*([^;}]+)/g)) {
          if (decl.includes('infinite')) continue;
          if (/(?<![\w-])[\d.]+m?s\b/.test(decl)) offenders.push(`${path}:${i + 1} ${decl}`);
        }
      });
    }
    expect(offenders).toEqual([]);
  });
});
