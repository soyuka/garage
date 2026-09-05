import { describe, it, expect } from 'vitest';
import { DOLLY_SECONDS } from '../scene/cameraRig';
import { FLICKER_SECONDS, PULSE_DURATION } from '../scene/holo';
import { FILAMENT_SECONDS } from '../scene/agentLight';
import atelier from './atelier.css?raw';
import { MOTION } from './motion';

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

  it('keeps the JS-side motions (they run in useFrame, not CSS) on the same scale', () => {
    expect(DOLLY_SECONDS * 1000).toBe(MOTION.dolly);
    expect(PULSE_DURATION * 1000).toBe(MOTION.pulse);
    expect(FILAMENT_SECONDS * 1000).toBe(MOTION.filament);
    expect(FLICKER_SECONDS * 1000).toBe(MOTION.etch);
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
