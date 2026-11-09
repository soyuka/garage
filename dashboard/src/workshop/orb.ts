import type { DictationPhase } from '../noyau/dictation';
import type { NoyauPhase } from '../noyau/state';

export type OrbMode = 'idle' | 'listening' | 'working' | 'settle';

export interface OrbState {
  mode: OrbMode;
  tone: 'ink' | 'blue';
}

/** The dock orb's motion and colour: a running turn owns it, then an open mic, then the done settle. */
export function orbState(phase: NoyauPhase, mic: DictationPhase): OrbState {
  if (phase === 'working') return { mode: 'working', tone: 'blue' };
  if (phase === 'listening' || mic !== 'idle') return { mode: 'listening', tone: 'blue' };
  if (phase === 'done') return { mode: 'settle', tone: 'ink' };
  return { mode: 'idle', tone: 'ink' };
}

const SEGMENTS = 44;
/** Past a full turn, as a pen does when it closes a circle by hand. */
const OVERSHOOT = 0.35;
const WOBBLE = 0.035;

function random(seed: number): () => number {
  let s = seed >>> 0 || 1;
  return () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0;
    return s / 0x100000000;
  };
}

/**
 * The orb as pen strokes: `count` great circles of a sphere of `radius`, each tilted at random, drawn as an
 * open polyline with a slight wobble and an overshoot, centred on 0,0. Deterministic per seed.
 */
export function orbStrokes(seed: number, count: number, radius: number): string[] {
  const rand = random(seed);
  const strokes: string[] = [];
  for (let k = 0; k < count; k++) {
    const theta = rand() * Math.PI * 2;
    const phi = Math.acos(2 * rand() - 1);
    const n = [Math.sin(phi) * Math.cos(theta), Math.sin(phi) * Math.sin(theta), Math.cos(phi)];
    const a = Math.abs(n[0]) < 0.9 ? [1, 0, 0] : [0, 1, 0];
    const u = normalize(cross(n, a));
    const v = cross(n, u);
    const start = rand() * Math.PI * 2;
    const phase = rand() * Math.PI * 2;
    const ripple = 2 + Math.floor(rand() * 3);
    const pts: string[] = [];
    for (let i = 0; i <= SEGMENTS; i++) {
      const t = start + ((Math.PI * 2 + OVERSHOOT) * i) / SEGMENTS;
      const r = radius * (1 + WOBBLE * Math.sin(ripple * t + phase));
      const x = r * (Math.cos(t) * u[0] + Math.sin(t) * v[0]);
      const y = r * (Math.cos(t) * u[1] + Math.sin(t) * v[1]);
      pts.push(`${x.toFixed(2)} ${y.toFixed(2)}`);
    }
    strokes.push(`M${pts[0]} L${pts.slice(1).join(' L')}`);
  }
  return strokes;
}

function cross(a: number[], b: number[]): number[] {
  return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
}

function normalize(a: number[]): number[] {
  const l = Math.hypot(a[0], a[1], a[2]) || 1;
  return [a[0] / l, a[1] / l, a[2] / l];
}
