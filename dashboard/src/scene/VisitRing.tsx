import { useEffect, useMemo, useRef, type MutableRefObject } from 'react';
import { useFrame } from '@react-three/fiber';
import * as THREE from 'three';
import type { Group } from 'three';
import { buildHoloFill, type HoloFillControl } from './holoFill';
import { HOLO_CYAN, OPERATOR_HEX } from './signalColors';
import { MOTION } from '../tokens/motion';
import type { VisitSlab } from './visitRing';

// The visit ring's slabs (CHOREOGRAPHY § "History — the visit ring").

// Twins of the existing surface-arrives / data-cascade intents (`motion.test.ts` pins the pair) rather
// than a third pair of durations invented for this one station.
export const RISE_SECONDS = MOTION.rise / 1000;
export const CASCADE_SECONDS = MOTION.cascade / 1000;

const SLAB_WIDTH = 0.36;
const SLAB_DEPTH = 0.04;

function staggerDelay(index: number, count: number): number {
  return count > 1 ? (index * CASCADE_SECONDS) / (count - 1) : 0;
}

// The sink phase reuses this with delay=0 (no stagger).
function riseT(phaseClock: number, delay: number): number {
  return Math.min(1, Math.max(0, (phaseClock - delay) / RISE_SECONDS));
}

// Shared inert uniforms are never mutated, so sharing them across N materials is safe.
const INERT_SCAN = {
  uScanX: { value: 0 },
  uScanOn: { value: 0 },
  uScanWidth: { value: 0.001 },
  uScanAxis: { value: 0 },
  uScanColor: { value: new THREE.Color(HOLO_CYAN) },
};
const INERT_GHOST = { uGhost: { value: 0 } };

interface SlabProps {
  slab: VisitSlab;
  hovered: boolean;
  reduced: boolean;
  delay: number;
  phaseClock: MutableRefObject<number>;
  sinking: boolean;
}

// scale.y around the group origin keeps the bottom pinned to the disc, top grows.
// A cost-less visit is wire only: no fill mesh, edges alone (truth first — unknown is not zero).
function Slab({ slab, hovered, reduced, delay, phaseClock, sinking }: SlabProps) {
  const group = useRef<Group>(null);
  const last = useRef(-1);

  const geometry = useMemo(() => new THREE.BoxGeometry(SLAB_WIDTH, slab.height, SLAB_DEPTH), [slab.height]);
  const edgesGeometry = useMemo(() => new THREE.EdgesGeometry(geometry), [geometry]);
  useEffect(
    () => () => {
      geometry.dispose();
      edgesGeometry.dispose();
    },
    [geometry, edgesGeometry],
  );

  const fill = useMemo<{ material: THREE.ShaderMaterial; holo: HoloFillControl } | null>(
    () => (slab.hasCost ? buildHoloFill(HOLO_CYAN, INERT_SCAN, INERT_GHOST, HOLO_CYAN) : null),
    [slab.hasCost],
  );
  useEffect(() => () => fill?.material.dispose(), [fill]);

  const t0 = reduced ? (sinking ? 0 : 1) : sinking ? 1 - riseT(phaseClock.current, 0) : riseT(phaseClock.current, delay);

  useFrame((state) => {
    const g = group.current;
    if (!g) return;
    const t = reduced ? (sinking ? 0 : 1) : sinking ? 1 - riseT(phaseClock.current, 0) : riseT(phaseClock.current, delay);
    if (t !== last.current) {
      last.current = t;
      g.scale.y = Math.max(0.0001, t);
      g.visible = t > 0.0005;
    }
    if (fill) fill.holo.uniforms.uTime.value = state.clock.elapsedTime;
  });

  const edgeColor = hovered ? OPERATOR_HEX : HOLO_CYAN;

  return (
    <group ref={group} position={slab.position} rotation={[0, slab.angle, 0]} scale={[1, t0, 1]} visible={t0 > 0.0005}>
      {fill && (
        <mesh position={[0, slab.height / 2, 0]} geometry={geometry}>
          <primitive object={fill.material} attach="material" />
        </mesh>
      )}
      <lineSegments position={[0, slab.height / 2, 0]} geometry={edgesGeometry}>
        <lineBasicMaterial color={edgeColor} toneMapped={false} />
      </lineSegments>
    </group>
  );
}

export interface VisitRingProps {
  slabs: VisitSlab[];
  /** The visit whose Carnet entry is hovered/focused — that slab's edges take the operator hue. */
  hovered: string | null;
  reduced: boolean;
  /** Caller keeps VisitRing mounted through this and unmounts on `onSunk`. */
  sinking?: boolean;
  onSunk?: () => void;
}

// Owns a single phase clock so a slab mounting mid-cascade joins the current instant instead of
// replaying from 0 (mirrors Assembly's one-diagram-clock rationale).
export function VisitRing({ slabs, hovered, reduced, sinking = false, onSunk }: VisitRingProps) {
  const phaseClock = useRef(0);
  const phaseStartWall = useRef<number | null>(null);
  const wasSinking = useRef(sinking);
  const sunkFired = useRef(false);

  useFrame((state) => {
    if (wasSinking.current !== sinking) {
      phaseStartWall.current = state.clock.elapsedTime;
      wasSinking.current = sinking;
      sunkFired.current = false;
    }
    if (phaseStartWall.current === null) phaseStartWall.current = state.clock.elapsedTime;
    phaseClock.current = state.clock.elapsedTime - phaseStartWall.current;
    if (sinking && !reduced && !sunkFired.current && phaseClock.current >= RISE_SECONDS) {
      sunkFired.current = true;
      onSunk?.();
    }
  });

  // A closing ring under reduced motion is gone on the same frame, so the caller unmounts it right away.
  useEffect(() => {
    if (sinking && reduced) onSunk?.();
  }, [sinking, reduced, onSunk]);

  return (
    <group>
      {slabs.map((slab) => (
        <Slab
          key={slab.id}
          slab={slab}
          hovered={hovered === slab.id}
          reduced={reduced}
          delay={staggerDelay(slab.index, slabs.length)}
          phaseClock={phaseClock}
          sinking={sinking}
        />
      ))}
    </group>
  );
}
