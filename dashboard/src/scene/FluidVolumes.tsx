import { useEffect, useMemo, useRef, type MutableRefObject } from 'react';
import { useFrame } from '@react-three/fiber';
import * as THREE from 'three';
import type { Group } from 'three';
import { buildHoloFill, type HoloFillControl } from './holoFill';
import { HOLO_CYAN, OPERATOR_HEX } from './signalColors';
import { MOTION } from '../tokens/motion';
import { usePickHandlers } from './picking';
import type { FluidCylinder } from './fluidVolumes';

// The fluid volumes' cylinders. Mirrors VisitRing.tsx
// file-for-file: one phase clock, a rise/stagger curve, keep-mounted-while-sinking.

// No existing pair covers rise+fan (VisitRing twins rise+cascade, FactConstellation twins
// etch+fan) — declared here per the spec's own fallback, registered alongside the others in
// motion.test.ts.
export const FLUID_RISE_SECONDS = MOTION.rise / 1000;
export const FLUID_FAN_SECONDS = MOTION.fan / 1000;

function staggerDelay(index: number, count: number): number {
  return count > 1 ? (index * FLUID_FAN_SECONDS) / (count - 1) : 0;
}

// The sink phase reuses this with delay=0 (no stagger).
function riseT(phaseClock: number, delay: number): number {
  return Math.min(1, Math.max(0, (phaseClock - delay) / FLUID_RISE_SECONDS));
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

interface CylinderProps {
  cylinder: FluidCylinder;
  hovered: boolean;
  reduced: boolean;
  delay: number;
  phaseClock: MutableRefObject<number>;
  sinking: boolean;
  onHover?: (key: string | null) => void;
  onPick?: (key: string) => void;
}

// scale.y around the group origin keeps the bottom pinned to the disc, top grows — same trick as
// VisitRing's Slab. A mass reading (hasVolume:false) is wire only: no fill mesh, edges alone (truth
// first — unknown/non-volume is not zero).
function Cylinder({ cylinder, hovered, reduced, delay, phaseClock, sinking, onHover, onPick }: CylinderProps) {
  const group = useRef<Group>(null);
  const last = useRef(-1);

  const geometry = useMemo(
    () => new THREE.CylinderGeometry(cylinder.radius, cylinder.radius, cylinder.height, 24),
    [cylinder.radius, cylinder.height],
  );
  const edgesGeometry = useMemo(() => new THREE.EdgesGeometry(geometry), [geometry]);
  useEffect(
    () => () => {
      geometry.dispose();
      edgesGeometry.dispose();
    },
    [geometry, edgesGeometry],
  );

  const fill = useMemo<{ material: THREE.ShaderMaterial; holo: HoloFillControl } | null>(
    () => (cylinder.hasVolume ? buildHoloFill(HOLO_CYAN, INERT_SCAN, INERT_GHOST, HOLO_CYAN) : null),
    [cylinder.hasVolume],
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
  const pick = usePickHandlers(cylinder.key, onHover, onPick);

  return (
    <group
      ref={group}
      position={cylinder.position}
      scale={[1, t0, 1]}
      visible={t0 > 0.0005}
      onPointerOver={pick.onPointerOver}
      onPointerOut={pick.onPointerOut}
      onClick={pick.onClick}
    >
      {fill ? (
        <mesh position={[0, cylinder.height / 2, 0]} geometry={geometry}>
          <primitive object={fill.material} attach="material" />
        </mesh>
      ) : (
        // A mass reading has no fill, so the wire alone is what would be hit — hard to raycast. Same
        // geometry, invisible (opacity 0), so a wire body picks exactly like a filled one.
        <mesh position={[0, cylinder.height / 2, 0]} geometry={geometry}>
          <meshBasicMaterial transparent opacity={0} depthWrite={false} />
        </mesh>
      )}
      <lineSegments position={[0, cylinder.height / 2, 0]} geometry={edgesGeometry}>
        <lineBasicMaterial color={edgeColor} toneMapped={false} />
      </lineSegments>
    </group>
  );
}

export interface FluidVolumesProps {
  cylinders: FluidCylinder[];
  /** The specs constellation node hovered/focused — that cylinder's edges take the operator hue. */
  hovered: string | null;
  reduced: boolean;
  /** Caller keeps FluidVolumes mounted through this and unmounts on `onSunk`. */
  sinking?: boolean;
  onSunk?: () => void;
  /** A cylinder hovered/unhovered (§ "Picking — the body points back"). Absent → no hover wiring back to the specs column. */
  onHover?: (key: string | null) => void;
  /** A cylinder clicked — scrolls the matching specs node into view. Absent → no pick wiring. */
  onPick?: (key: string) => void;
}

// Owns a single phase clock so a cylinder mounting mid-fan joins the current instant instead of
// replaying from 0 (mirrors VisitRing's/FactConstellation's rationale).
export function FluidVolumes({ cylinders, hovered, reduced, sinking = false, onSunk, onHover, onPick }: FluidVolumesProps) {
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
    if (sinking && !reduced && !sunkFired.current && phaseClock.current >= FLUID_RISE_SECONDS) {
      sunkFired.current = true;
      onSunk?.();
    }
  });

  // A closing row under reduced motion is gone on the same frame, so the caller unmounts it right away.
  useEffect(() => {
    if (sinking && reduced) onSunk?.();
  }, [sinking, reduced, onSunk]);

  return (
    <group>
      {cylinders.map((cylinder) => (
        <Cylinder
          key={cylinder.key}
          cylinder={cylinder}
          hovered={hovered === cylinder.key}
          reduced={reduced}
          delay={staggerDelay(cylinder.index, cylinders.length)}
          phaseClock={phaseClock}
          sinking={sinking}
          onHover={onHover}
          onPick={onPick}
        />
      ))}
    </group>
  );
}
