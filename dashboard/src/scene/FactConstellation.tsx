import { useEffect, useMemo, useRef, type MutableRefObject } from 'react';
import { useFrame } from '@react-three/fiber';
import * as THREE from 'three';
import type { Group } from 'three';
import { buildHoloFill, type HoloFillControl } from './holoFill';
import { HOLO_CYAN, OPERATOR_HEX } from './signalColors';
import { MOTION } from '../tokens/motion';
import { usePickHandlers } from './picking';
import { FIRST_OFFSET, type FactNode, type FactRegion } from './factConstellation';

// The fact constellation's nodes. Mirrors
// VisitRing.tsx file-for-file: one phase clock, a rise/etch curve, keep-mounted-while-sinking.

// Twins of the existing etch / fan intents (`motion.test.ts` pins the pair).
export const ETCH_SECONDS = MOTION.etch / 1000;
export const FAN_SECONDS = MOTION.fan / 1000;

const NODE_RADIUS = 0.09;
const LEADER_WIDTH = 0.012;

// Fixed dimensions (the node radius and the anchor→first-node gap are both constants), so these are
// built once at module scope rather than re-allocated per instance.
const nodeGeometry = new THREE.OctahedronGeometry(NODE_RADIUS);
const nodeEdgesGeometry = new THREE.EdgesGeometry(nodeGeometry);
const leaderGeometry = new THREE.BoxGeometry(LEADER_WIDTH, FIRST_OFFSET, LEADER_WIDTH);
const leaderEdgesGeometry = new THREE.EdgesGeometry(leaderGeometry);

function staggerDelay(indexInColumn: number, columnCount: number): number {
  return columnCount > 1 ? (indexInColumn * FAN_SECONDS) / (columnCount - 1) : 0;
}

/** Per-node etch delay: index WITHIN its own column over `FAN_SECONDS`, all columns running in
 * parallel (every column restarts its own 0→1 stagger rather than sharing one global scale). */
function columnDelays(nodes: FactNode[]): Map<string, number> {
  const groups = new Map<FactRegion, FactNode[]>();
  for (const n of nodes) {
    const arr = groups.get(n.region);
    if (arr) arr.push(n);
    else groups.set(n.region, [n]);
  }
  const delays = new Map<string, number>();
  for (const arr of groups.values()) {
    arr.forEach((n, i) => delays.set(n.key, staggerDelay(i, arr.length)));
  }
  return delays;
}

// The sink phase reuses this with delay=0 (no stagger).
function etchT(phaseClock: number, delay: number): number {
  return Math.min(1, Math.max(0, (phaseClock - delay) / ETCH_SECONDS));
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

interface NodeMeshProps {
  node: FactNode;
  hovered: boolean;
  reduced: boolean;
  delay: number;
  phaseClock: MutableRefObject<number>;
  sinking: boolean;
  onHover?: (key: string | null) => void;
  onPick?: (key: string) => void;
}

// Uniform scale around the node's own centre (unlike VisitRing's scale.y-pinned-to-floor slab, a
// point marker has no "floor end" to pin). A verified fact is a filled holo node; an unverified one
// is wire only — the same truth-first law as a cost-less visit slab.
function NodeMesh({ node, hovered, reduced, delay, phaseClock, sinking, onHover, onPick }: NodeMeshProps) {
  const group = useRef<Group>(null);
  const last = useRef(-1);

  const fill = useMemo<{ material: THREE.ShaderMaterial; holo: HoloFillControl } | null>(
    () => (node.verified ? buildHoloFill(HOLO_CYAN, INERT_SCAN, INERT_GHOST, HOLO_CYAN) : null),
    [node.verified],
  );
  useEffect(() => () => fill?.material.dispose(), [fill]);

  const t0 = reduced ? (sinking ? 0 : 1) : sinking ? 1 - etchT(phaseClock.current, 0) : etchT(phaseClock.current, delay);

  useFrame((state) => {
    const g = group.current;
    if (!g) return;
    const t = reduced ? (sinking ? 0 : 1) : sinking ? 1 - etchT(phaseClock.current, 0) : etchT(phaseClock.current, delay);
    if (t !== last.current) {
      last.current = t;
      g.scale.setScalar(Math.max(0.0001, t));
      g.visible = t > 0.0005;
    }
    if (fill) fill.holo.uniforms.uTime.value = state.clock.elapsedTime;
  });

  const edgeColor = hovered ? OPERATOR_HEX : HOLO_CYAN;
  const pick = usePickHandlers(node.key, onHover, onPick);

  return (
    <group
      ref={group}
      position={node.position}
      scale={[t0, t0, t0]}
      visible={t0 > 0.0005}
      onPointerOver={pick.onPointerOver}
      onPointerOut={pick.onPointerOut}
      onClick={pick.onClick}
    >
      {fill ? (
        <mesh geometry={nodeGeometry}>
          <primitive object={fill.material} attach="material" />
        </mesh>
      ) : (
        // An unverified fact has no fill, so the wire alone is what would be hit — hard to raycast.
        // Same geometry, invisible (opacity 0), so a wire body picks exactly like a filled one.
        <mesh geometry={nodeGeometry}>
          <meshBasicMaterial transparent opacity={0} depthWrite={false} />
        </mesh>
      )}
      <lineSegments geometry={nodeEdgesGeometry}>
        <lineBasicMaterial color={edgeColor} toneMapped={false} />
      </lineSegments>
    </group>
  );
}

interface LeaderProps {
  node: FactNode;
  reduced: boolean;
  phaseClock: MutableRefObject<number>;
  sinking: boolean;
}

// One leader per column, from the anchor to the lowest (first) node — always `FIRST_OFFSET` long,
// since every column's first node sits exactly that far above its anchor. Delay 0: it rises in step
// with its column's first node, never staggered on its own.
function Leader({ node, reduced, phaseClock, sinking }: LeaderProps) {
  const group = useRef<Group>(null);
  const last = useRef(-1);

  const t0 = reduced ? (sinking ? 0 : 1) : sinking ? 1 - etchT(phaseClock.current, 0) : etchT(phaseClock.current, 0);

  useFrame(() => {
    const g = group.current;
    if (!g) return;
    const t = reduced ? (sinking ? 0 : 1) : sinking ? 1 - etchT(phaseClock.current, 0) : etchT(phaseClock.current, 0);
    if (t !== last.current) {
      last.current = t;
      g.scale.y = Math.max(0.0001, t);
      g.visible = t > 0.0005;
    }
  });

  return (
    <group ref={group} position={node.foot} scale={[1, t0, 1]} visible={t0 > 0.0005}>
      <lineSegments position={[0, FIRST_OFFSET / 2, 0]} geometry={leaderEdgesGeometry}>
        <lineBasicMaterial color={HOLO_CYAN} toneMapped={false} />
      </lineSegments>
    </group>
  );
}

export interface FactConstellationProps {
  nodes: FactNode[];
  /** The fiche row hovered/focused — that node's edges take the operator hue. */
  hovered: string | null;
  reduced: boolean;
  /** Caller keeps FactConstellation mounted through this and unmounts on `onSunk`. */
  sinking?: boolean;
  onSunk?: () => void;
  /** A node hovered/unhovered (§ "Picking — the body points back"). Absent → no hover wiring back to the fiche. */
  onHover?: (key: string | null) => void;
  /** A node clicked — scrolls the matching fiche row into view. Absent → no pick wiring. */
  onPick?: (key: string) => void;
}

// Owns a single phase clock so a node mounting mid-fan joins the current instant instead of replaying
// from 0 (mirrors VisitRing's rationale).
export function FactConstellation({ nodes, hovered, reduced, sinking = false, onSunk, onHover, onPick }: FactConstellationProps) {
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
    if (sinking && !reduced && !sunkFired.current && phaseClock.current >= ETCH_SECONDS) {
      sunkFired.current = true;
      onSunk?.();
    }
  });

  // A closing constellation under reduced motion is gone on the same frame, so the caller unmounts it
  // right away.
  useEffect(() => {
    if (sinking && reduced) onSunk?.();
  }, [sinking, reduced, onSunk]);

  const delays = useMemo(() => columnDelays(nodes), [nodes]);
  const leaders = useMemo(() => nodes.filter((n) => n.first), [nodes]);

  return (
    <group>
      {nodes.map((node) => (
        <NodeMesh
          key={node.key}
          node={node}
          hovered={hovered === node.key}
          reduced={reduced}
          delay={delays.get(node.key) ?? 0}
          phaseClock={phaseClock}
          sinking={sinking}
          onHover={onHover}
          onPick={onPick}
        />
      ))}
      {leaders.map((node) => (
        <Leader key={`leader-${node.key}`} node={node} reduced={reduced} phaseClock={phaseClock} sinking={sinking} />
      ))}
    </group>
  );
}
