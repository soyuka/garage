import { useMemo, useRef } from 'react';
import { useFrame } from '@react-three/fiber';
import { Edges } from '@react-three/drei';
import type { Group, Material, Mesh } from 'three';
import { assemblyDelay, assemblyFor, type AssemblyPart } from './assembly';
import { regionAnchors } from './anchors';
import type { BodyRegion } from './anchors';
import type { BodyClass } from './CarProxy';
import { EDGE, FILL_OPACITY } from './CarProxy';
import { OPERATOR_HEX } from './signalColors';
import { MOTION } from '../tokens/motion';

// C3 — the schematic assembly. Draws the region's authored diagram (assembly.ts) in the region's anchor
// space, under the ghosted shell. Same material language as the car so the two read as one drawing:
// a faint tinted fill under a glowing edge line. The callout hue is the OPERATOR orange, never cyan —
// cyan is rationed to "the agent is acting", and these parts are standing state, not an action.

const RISE = MOTION.rise / 1000; // the surface-arrives step; a part rises over exactly one of them
const RISE_FROM = -0.07; // metres below its resting place — a part settles INTO the diagram

/** One primitive. Owns its own rise so a five-part diagram costs five tiny useFrame subscribers rather
 * than one parent re-rendering the whole group every frame. Never allocates in the loop. */
function Part({ part, delay, reduced }: { part: AssemblyPart; delay: number; reduced: boolean }) {
  const group = useRef<Group>(null);
  const mesh = useRef<Mesh>(null);
  const elapsed = useRef(0);
  const color = part.highlighted ? OPERATOR_HEX : EDGE;

  useFrame((_, d) => {
    const g = group.current;
    if (!g) return;
    if (reduced) {
      if (g.position.y !== part.offset[1]) {
        g.position.y = part.offset[1];
        g.visible = true;
        setOpacity(mesh.current, FILL_OPACITY);
      }
      return;
    }
    if (elapsed.current >= delay + RISE) return; // settled — nothing to write
    elapsed.current += Math.min(d, 0.05); // clamp a tab-restore hitch
    const t = Math.min(1, Math.max(0, (elapsed.current - delay) / RISE));
    g.visible = t > 0;
    g.position.y = part.offset[1] + RISE_FROM * (1 - t);
    setOpacity(mesh.current, FILL_OPACITY * t);
  });

  return (
    <group ref={group} position={[part.offset[0], part.offset[1] + (reduced ? 0 : RISE_FROM), part.offset[2]]} visible={reduced}>
      <mesh ref={mesh}>
        <PartGeometry part={part} />
        <meshBasicMaterial color={color} transparent opacity={reduced ? FILL_OPACITY : 0} depthWrite={false} />
        <Edges color={color} />
      </mesh>
    </group>
  );
}

function setOpacity(mesh: Mesh | null, value: number): void {
  const mat = mesh?.material as Material & { opacity: number };
  if (mat) mat.opacity = value;
}

/** The five-shape vocabulary, as three.js primitives. A `line` is a long thin box rather than a
 * LineSegments so it takes the same <Edges> treatment as everything else and reads at projector
 * distance, where a 1px line does not. */
function PartGeometry({ part }: { part: AssemblyPart }) {
  switch (part.shape) {
    case 'canister':
      return <cylinderGeometry args={[part.size, part.size, part.size * 2.4, 16]} />;
    case 'disc':
      return <cylinderGeometry args={[part.size, part.size, part.size * 0.16, 28]} />;
    case 'housing':
      return <boxGeometry args={[part.size, part.size * 0.62, part.size * 0.78]} />;
    case 'line':
      return <boxGeometry args={[part.size, 0.012, 0.012]} />;
    case 'node':
      return <sphereGeometry args={[part.size, 12, 10]} />;
  }
}

/**
 * The reveal's diagram for one region. Mounted only while a reveal holds — unmounting is the dismiss,
 * so there is no lingering hidden geometry and no second state to keep in sync with the ghost.
 *
 * `onPartsLanded` reports how many parts have finished rising. That number is the ghost's denominator:
 * the shell dissolves as the diagram arrives (CHOREOGRAPHY, reveal table), so the dissolve is paced by
 * the diagram and never by a clock.
 */
export function Assembly({
  bodyClass,
  region,
  partKeys = [],
  reduced = false,
  onPartsLanded,
}: {
  bodyClass: BodyClass;
  region: BodyRegion;
  partKeys?: string[];
  reduced?: boolean;
  onPartsLanded?: (landed: number, total: number) => void;
}) {
  const parts = useMemo(() => assemblyFor(region, partKeys), [region, partKeys]);
  const anchor = useMemo(() => regionAnchors(bodyClass)[region], [bodyClass, region]);
  const elapsed = useRef(0);
  const reported = useRef(-1);

  // One clock for the whole diagram drives the landed count (the parts run their own rises). Reporting
  // is edge-triggered: a count that has not changed writes nothing, so a settled reveal is free.
  useFrame((_, d) => {
    if (!onPartsLanded) return;
    if (reduced) {
      if (reported.current !== parts.length) {
        reported.current = parts.length;
        onPartsLanded(parts.length, parts.length);
      }
      return;
    }
    if (reported.current >= parts.length) return;
    elapsed.current += Math.min(d, 0.05);
    let landed = 0;
    for (let i = 0; i < parts.length; i++) if (elapsed.current >= assemblyDelay(i) + RISE) landed++;
    if (landed !== reported.current) {
      reported.current = landed;
      onPartsLanded(landed, parts.length);
    }
  });

  return (
    <group position={[anchor[0], anchor[1], anchor[2]]}>
      {parts.map((part, i) => (
        <Part key={part.key} part={part} delay={assemblyDelay(i)} reduced={reduced} />
      ))}
    </group>
  );
}
