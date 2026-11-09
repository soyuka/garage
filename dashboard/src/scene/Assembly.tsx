import { Suspense, useEffect, useMemo, useRef, type MutableRefObject } from 'react';
import { useLocale } from '../i18n/LocaleProvider';
import { useFrame } from '@react-three/fiber';
import { Edges, useGLTF } from '@react-three/drei';
import * as THREE from 'three';
import type { Group, Material, Mesh } from 'three';
import {
  ASSEMBLY_RISE,
  DISC_UPRIGHT,
  RISE_FROM,
  assemblyDelay,
  assemblyFor,
  meshFit,
  partMeshUrl,
  riseT,
  schematicBox,
  type AssemblyPart,
} from './assembly';
import { regionAnchors } from './anchors';
import type { BodyRegion } from './anchors';
import type { BodyClass } from './CarProxy';
import { EDGE, FILL_OPACITY } from './CarProxy';
import { OPERATOR_HEX } from './signalColors';
import { MeshErrorBoundary } from './VehicleBody';
import { HoloRunner } from './VehicleMesh';
import { buildBlueprint } from './blueprintBuild';

// C3 — the schematic assembly. Draws the region's authored diagram (assembly.ts) in the region's anchor
// space, under the ghosted shell. Same material language as the car so the two read as one drawing:
// a faint tinted fill under a glowing edge line — literally true for a part with a generic mesh, which
// goes through the SAME buildBlueprint pass as the shell. The
// callout hue is the OPERATOR orange, never cyan — cyan is rationed to "the agent is acting", and these
// parts are standing state, not an action.
//
// ONE diagram clock (`clock`, owned by Assembly and read by every part) rather than a per-part elapsed
// counter: a glb that resolves mid-rise (Suspense swapping the primitive out) must join the rise at the
// CURRENT instant instead of replaying it from 0. Children's
// useFrame subscribe before the parent's, so a part reads the previous frame's clock value — one frame of
// lag, never a skipped write; do not reorder Assembly's clock advance relative to its children.

// Decimated Sketchfab parts (300–8000 tris): 30° (the car's) scribbles the 6000-tri plug. Unchanged after live check 2026-09-05.
const PART_EDGE_ANGLE = 40;
// The shell's boost (1.7) unless the bloomed orange stops reading as the primitive's orange. Unchanged after live check 2026-09-05.
const PART_EDGE_BOOST = 1.7;

interface PartProps {
  part: AssemblyPart;
  delay: number;
  reduced: boolean;
  clock: MutableRefObject<number>;
}

/** One primitive. Owns its own rise so a five-part diagram costs five tiny useFrame subscribers rather
 * than one parent re-rendering the whole group every frame. Never allocates in the loop. */
function Part({ part, delay, reduced, clock }: PartProps) {
  const group = useRef<Group>(null);
  const mesh = useRef<Mesh>(null);
  const last = useRef(-1);
  const color = part.highlighted ? OPERATOR_HEX : EDGE;
  const t0 = reduced ? 1 : riseT(clock.current, delay); // a part mounting mid-rise starts where the rise IS

  useFrame(() => {
    const g = group.current;
    if (!g) return;
    const t = reduced ? 1 : riseT(clock.current, delay);
    if (t === last.current) return; // settled — nothing to write
    last.current = t;
    g.visible = t > 0;
    g.position.y = part.offset[1] + RISE_FROM * (1 - t);
    setOpacity(mesh.current, FILL_OPACITY * t);
  });

  return (
    <group ref={group} position={[part.offset[0], part.offset[1] + RISE_FROM * (1 - t0), part.offset[2]]} visible={t0 > 0}>
      <mesh ref={mesh} rotation={part.shape === 'disc' ? [DISC_UPRIGHT[0], DISC_UPRIGHT[1], DISC_UPRIGHT[2]] : [0, 0, 0]}>
        <PartGeometry part={part} />
        <meshBasicMaterial color={color} transparent opacity={FILL_OPACITY * t0} depthWrite={false} />
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
      // upright — DISC_UPRIGHT on the mesh
      return <cylinderGeometry args={[part.size, part.size, part.size * 0.16, 28]} />;
    case 'housing':
      return <boxGeometry args={[part.size, part.size * 0.62, part.size * 0.78]} />;
    case 'line':
      return <boxGeometry args={[part.size, 0.012, 0.012]} />;
    case 'node':
      return <sphereGeometry args={[part.size, 12, 10]} />;
  }
}

/** A part whose kind has a generic mesh (`PART_MESHES`): the SAME buildBlueprint pass the shell uses, so
 * it is visibly the same matter — a hologram of the thing you order. `paint` = `edge` = `color` so a
 * highlighted part is operator-hued on edges, fill, fresnel AND scanlines exactly as the primitive's
 * fill+Edges are (§ Truth before spectacle, corrected). No ScanRunner/PulseRunner/GhostRunner mounted —
 * those uniforms stay inert at 0 and a part is never ghosted (it is what appears as the shell ghosts).
 * HoloRunner gives the arrival flicker + the scanline clock (the row says why). */
function MeshPart({ part, url, delay, reduced, clock }: PartProps & { url: string }) {
  const { scene } = useGLTF(url);
  const color = part.highlighted ? OPERATOR_HEX : EDGE;
  const spec = part.mesh!; // PartSlot only renders MeshPart when partMeshUrl gave a url
  const built = useMemo(() => {
    // Cache key per FILE: the baked edge geometry is colour-independent and the scale lives on the wrapping
    // group, so highlighted and plain instances share one EdgesGeometry (≤5 new EDGES_CACHE entries a session).
    const b = buildBlueprint(scene, {
      edgeAngle: PART_EDGE_ANGLE,
      edgeBoost: PART_EDGE_BOOST,
      paint: color,
      edge: color,
      rotationX: spec.rotationX,
      rotationY: spec.rotationY,
      cacheKey: `part/${spec.file}`,
    });
    b.holo.uniforms.uRise.value = reduced ? 1 : riseT(clock.current, delay);
    return b;
  }, [scene, color, spec, reduced, clock, delay]);
  useEffect(() => built.dispose, [built]);

  const fit = useMemo(() => {
    const box = new THREE.Box3().setFromObject(built.clone);
    return meshFit([box.max.x - box.min.x, box.max.y - box.min.y, box.max.z - box.min.z], schematicBox(part.shape, part.size));
  }, [built, part.shape, part.size]);

  const group = useRef<Group>(null);
  const last = useRef(-1);
  const t0 = reduced ? 1 : riseT(clock.current, delay);

  useFrame(() => {
    const g = group.current;
    if (!g) return;
    const t = reduced ? 1 : riseT(clock.current, delay);
    if (t === last.current) return;
    last.current = t;
    g.visible = t > 0;
    g.position.y = part.offset[1] + RISE_FROM * (1 - t);
    built.holo.uniforms.uRise.value = t;
  });

  return (
    <group ref={group} position={[part.offset[0], part.offset[1] + RISE_FROM * (1 - t0), part.offset[2]]} visible={t0 > 0}>
      <group scale={fit.scale} position-y={fit.lift}>
        <primitive object={built.clone} />
        <primitive object={built.edges} />
      </group>
      <HoloRunner holo={built.holo} reduced={reduced} />
    </group>
  );
}

/** Chooses the primitive or its generic mesh. The primitive is BOTH the loading and the error fallback: a
 * slow or missing glb degrades to today's diagram, never to nothing. The fetch starts with MeshPart's
 * first render (`useGLTF`) — a preload could only be earlier by running on idle, which the row forbids.
 * `key={url}` resets a failed boundary if the mapping ever changes; the loader caches a rejection, so a
 * 404 falls back for the session. */
function PartSlot(props: PartProps) {
  const url = partMeshUrl(props.part);
  if (!url) return <Part {...props} />;
  const primitive = <Part {...props} />;
  return (
    <MeshErrorBoundary key={url} fallback={primitive}>
      <Suspense fallback={primitive}>
        <MeshPart {...props} url={url} />
      </Suspense>
    </MeshErrorBoundary>
  );
}

/**
 * The reveal's diagram for one region. Mounted only while a reveal holds — unmounting is the dismiss,
 * so there is no lingering hidden geometry and no second state to keep in sync with the ghost.
 *
 * `onPartsLanded` reports how many parts have finished rising. That number is the ghost's denominator:
 * the shell dissolves as the diagram arrives, so the dissolve is paced by
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
  const [locale] = useLocale();
  const parts = useMemo(() => assemblyFor(region, locale, partKeys), [region, locale, partKeys]);
  const anchor = useMemo(() => regionAnchors(bodyClass)[region], [bodyClass, region]);
  const clock = useRef(0);
  const reported = useRef(-1);
  const settleAt = parts.length ? assemblyDelay(parts.length - 1) + ASSEMBLY_RISE : 0;

  // One clock for the whole diagram drives the landed count (the parts read it, never their own elapsed
  // counter). Reporting is edge-triggered: a count that has not changed writes nothing, so a settled
  // reveal is free.
  useFrame((_, d) => {
    if (reduced) {
      if (onPartsLanded && reported.current !== parts.length) {
        reported.current = parts.length;
        onPartsLanded(parts.length, parts.length);
      }
      return;
    }
    if (clock.current >= settleAt) return; // every slot has landed — the clock stops, late glbs read t = 1
    clock.current += Math.min(d, 0.05); // clamp a tab-restore hitch
    if (!onPartsLanded) return;
    let landed = 0;
    for (let i = 0; i < parts.length; i++) if (riseT(clock.current, assemblyDelay(i)) >= 1) landed++;
    if (landed !== reported.current) {
      reported.current = landed;
      onPartsLanded(landed, parts.length);
    }
  });

  return (
    <group position={[anchor[0], anchor[1], anchor[2]]}>
      {parts.map((part, i) => (
        <PartSlot key={part.key} part={part} delay={assemblyDelay(i)} reduced={reduced} clock={clock} />
      ))}
    </group>
  );
}
