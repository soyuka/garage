import { useRef } from 'react';
import { useFrame } from '@react-three/fiber';
import * as THREE from 'three';
import type { ForecastDue, Signal } from '../types';
import { dueSignal } from '../derive';
import { SIGNAL_HEX, NEUTRAL_HEX } from './signalColors';
import { RIM } from './anchors';

// §2 "the rim is the global gauge": the turntable rim as a 360° ring of arc-segments, one per tracked
// system, each lit its signal. All-green → whole rim green; one red → that arc burns red. Empty
// forecast → ONE dim neutral ring (honest "rien de suivi", not a healthy green). The signal is the
// SAME dueSignal() the truth-panel reads, so the floor never disagrees with the panel. Red arcs
// breathe, amber gentler, green steady — a SHAPE/motion difference, not colour alone (BUILD-NOTE 4).

const GAP_FRACTION = 0.88;
const BASE_OPACITY = 0.62;

export interface ArcSlice {
  thetaStart: number;
  thetaLength: number;
}

/** Split the full circle into `n` equal slices, each shortened to leave an angular gap. n<=0 → []. */
export function arcLayout(n: number): ArcSlice[] {
  if (n <= 0) return [];
  const slice = (Math.PI * 2) / n;
  return Array.from({ length: n }, (_, i) => ({
    thetaStart: i * slice,
    thetaLength: slice * GAP_FRACTION,
  }));
}

// Per-signal breathing depth + speed (rad/s). Red is the loudest; amber gentler; green steady.
const BREATH: Record<Signal, { amp: number; speed: number }> = {
  red: { amp: 0.34, speed: 3.4 },
  amber: { amp: 0.16, speed: 2.0 },
  green: { amp: 0, speed: 0 },
};

// Per-signal RADIAL THICKNESS — a non-colour, grayscale-legible cue that survives reduced-motion:
// red is the widest band, amber mid, green a thin arc (§8 — signal never by colour/motion alone).
const RADII: Record<Signal, { inner: number; outer: number }> = {
  red: { inner: RIM.inner, outer: RIM.outer },
  amber: { inner: RIM.inner + 0.045, outer: RIM.outer },
  green: { inner: RIM.inner + 0.09, outer: RIM.outer - 0.02 },
};

const IGNITE_S = 0.6; // amber→red "ignite" fade-in (§9), instant when reduced

function Arc({ slice, signal, reduced }: { slice: ArcSlice; signal: Signal; reduced: boolean }) {
  const mat = useRef<THREE.MeshBasicMaterial>(null);
  const phase = useRef(Math.random() * Math.PI * 2);
  const t = useRef(0); // ignite ramp 0→1
  const b = BREATH[signal];
  const r = RADII[signal];

  useFrame((_, delta) => {
    if (!mat.current) return;
    if (reduced) t.current = 1;
    else if (t.current < 1) t.current = Math.min(1, t.current + delta / IGNITE_S);
    const breathe = !reduced && b.amp > 0 ? b.amp * (Math.sin((phase.current += delta * b.speed)) * 0.5 + 0.5) : 0;
    mat.current.opacity = (BASE_OPACITY + breathe) * t.current;
  });

  return (
    <mesh>
      <ringGeometry args={[r.inner, r.outer, 64, 1, slice.thetaStart, slice.thetaLength]} />
      <meshBasicMaterial
        ref={mat}
        color={SIGNAL_HEX[signal]}
        transparent
        opacity={0}
        blending={THREE.AdditiveBlending}
        side={THREE.DoubleSide}
        depthWrite={false}
      />
    </mesh>
  );
}

function NeutralRing() {
  return (
    <mesh>
      <ringGeometry args={[RIM.inner, RIM.outer, 64, 1]} />
      <meshBasicMaterial
        color={NEUTRAL_HEX}
        transparent
        opacity={0.22}
        blending={THREE.AdditiveBlending}
        side={THREE.DoubleSide}
        depthWrite={false}
      />
    </mesh>
  );
}

export function RimGauge({ due, reduced = false }: { due: ForecastDue[]; reduced?: boolean }) {
  const arcs = arcLayout(due.length);
  return (
    <group rotation={[-Math.PI / 2, 0, 0]} position={[0, RIM.y, 0]}>
      {arcs.length === 0 ? (
        <NeutralRing />
      ) : (
        arcs.map((slice, i) => <Arc key={i} slice={slice} signal={dueSignal(due[i])} reduced={reduced} />)
      )}
    </group>
  );
}
