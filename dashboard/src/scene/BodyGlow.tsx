import { useMemo, useRef } from 'react';
import { useFrame } from '@react-three/fiber';
import * as THREE from 'three';
import type { ForecastDue, Signal } from '../types';
import { dueSignal } from '../derive';
import { regionAnchors, regionForType, type Anchor, type BodyRegion } from './anchors';
import { SIGNAL_HEX } from './signalColors';
import type { BodyClass } from './CarProxy';

// §2 "telemetry reads OFF the model" / §9 "the hood breathes red". An ADDITIVE bloom over the sick
// regions of the blueprint car: amber = due soon, red = overdue. Green is NEVER drawn — a healthy car
// stays pure steel-blue blueprint. Honesty (§8): the glow only signals URGENCY; the exact value lives
// in the truth-panel. Colour is never the sole signal here — every glowing region also carries a
// shape-bearing text pin (the Hotspots leaf) at the SAME anchor, and red vs amber differ by motion.

const SIGNAL_RANK: Record<Signal, number> = { green: 0, amber: 1, red: 2 };

export interface BodyGlowSpec {
  region: BodyRegion;
  signal: Signal;
  pos: Anchor;
}

/** amber/red dues only, one halo per region carrying the worst signal, anchored on CarProxy DIMS. */
export function sickGlows(bodyClass: BodyClass, due: ForecastDue[]): BodyGlowSpec[] {
  const anchors = regionAnchors(bodyClass);
  const worst = new Map<BodyRegion, Signal>();
  for (const d of due) {
    const signal = dueSignal(d);
    if (signal === 'green') continue;
    const region = regionForType(d.type);
    const prev = worst.get(region);
    if (!prev || SIGNAL_RANK[signal] > SIGNAL_RANK[prev]) worst.set(region, signal);
  }
  return [...worst].map(([region, signal]) => ({ region, signal, pos: anchors[region] }));
}

// Per-signal breathing: red is the urgent ~1Hz pulse (§9), amber a gentler, slower swell.
const BREATHE: Record<'amber' | 'red', { hz: number; base: number; amp: number }> = {
  red: { hz: 1.0, base: 0.24, amp: 0.1 },
  amber: { hz: 0.4, base: 0.16, amp: 0.05 },
};

interface HaloProps {
  spec: BodyGlowSpec;
  reduced: boolean;
}

const IGNITE_S = 0.6; // amber→red "ignite" fade-in (§9 'the hood breathes red'); instant when reduced

function Halo({ spec, reduced }: HaloProps) {
  const inner = useRef<THREE.MeshBasicMaterial>(null);
  const outer = useRef<THREE.MeshBasicMaterial>(null);
  const light = useRef<THREE.PointLight>(null);
  const group = useRef<THREE.Group>(null);
  const t = useRef(0); // ignite ramp 0→1
  const color = SIGNAL_HEX[spec.signal];
  const breathe = BREATHE[spec.signal as 'amber' | 'red'];
  const lightMax = spec.signal === 'red' ? 1.0 : 0.55;

  useFrame((state, delta) => {
    if (reduced) t.current = 1;
    else if (t.current < 1) t.current = Math.min(1, t.current + delta / IGNITE_S);
    const phase = reduced ? 0 : (Math.sin(state.clock.elapsedTime * breathe.hz * Math.PI * 2) + 1) / 2;
    const o = (breathe.base + phase * breathe.amp) * t.current;
    if (inner.current) inner.current.opacity = o;
    if (outer.current) outer.current.opacity = o * 0.55;
    if (light.current) light.current.intensity = lightMax * t.current;
    if (group.current) group.current.scale.setScalar(1 + (reduced ? 0 : phase * 0.12));
  });

  return (
    <group ref={group} position={spec.pos}>
      <mesh>
        <sphereGeometry args={[0.3, 16, 16]} />
        <meshBasicMaterial ref={inner} color={color} transparent opacity={0} blending={THREE.AdditiveBlending} depthWrite={false} />
      </mesh>
      <mesh>
        <sphereGeometry args={[0.46, 16, 16]} />
        <meshBasicMaterial ref={outer} color={color} transparent opacity={0} blending={THREE.AdditiveBlending} depthWrite={false} />
      </mesh>
      <pointLight ref={light} color={color} intensity={0} distance={1.8} decay={2} />
    </group>
  );
}

export interface BodyGlowProps {
  bodyClass: BodyClass;
  due: ForecastDue[];
  reduced?: boolean;
}

export function BodyGlow({ bodyClass, due, reduced = false }: BodyGlowProps) {
  const glows = useMemo(() => sickGlows(bodyClass, due), [bodyClass, due]);
  return (
    <group>
      {glows.map((spec) => (
        <Halo key={spec.region} spec={spec} reduced={reduced} />
      ))}
    </group>
  );
}
