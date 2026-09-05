import { useEffect, useMemo, useRef } from 'react';
import { useFrame } from '@react-three/fiber';
import * as THREE from 'three';
import {
  AGENT_LIGHT_DISTANCE,
  AGENT_LIGHT_LEVELS,
  AGENT_LIGHT_POS,
  EMITTER,
  LIGHT_DECAY,
  SPILL_DISTANCE,
  SPILL_POS,
  SPILL_RATIO,
  blend01,
  lightIntensity,
  lightTarget,
  type AgentLight,
} from './agentLight';
import { breathe01 } from './holo';
import { HOLO_CYAN } from './signalColors';

// The hologram lights the stage (CHOREOGRAPHY § "The hologram is the light", points 1 and 3). Two point
// lights on Le Noyau's breath: the agent light at the car (short reach, dies at the rim — phase as
// intensity, result as colour) and the spill above it (wide reach, always cyan, a fixed ratio). Four
// emitter housings on the disc root the projection; their lenses wear the agent light's colour. ONE
// useFrame, zero allocation, and once a blend has settled on a steady target nothing is written.

const DISC_TOP = 0.02;
const HOUSING_Y = DISC_TOP + EMITTER.housing.height / 2;
const LENS_Y = DISC_TOP + EMITTER.housing.height + EMITTER.lens.height / 2;

const EMITTER_POSITIONS: [number, number, number][] = Array.from({ length: EMITTER.count }, (_, i) => {
  const theta = EMITTER.azimuth(i);
  return [EMITTER.radius * Math.cos(theta), 0, EMITTER.radius * Math.sin(theta)];
});

export function AgentLightRig({ agent, reduced }: { agent: AgentLight; reduced: boolean }) {
  const agentRef = useRef<THREE.PointLight>(null);
  const spillRef = useRef<THREE.PointLight>(null);
  // One material for the four lenses — JSX <meshBasicMaterial> children would create four.
  const lensMat = useMemo(() => new THREE.MeshBasicMaterial({ color: HOLO_CYAN, toneMapped: false }), []);
  useEffect(() => () => lensMat.dispose(), [lensMat]);

  // The target is resolved when `agent` changes, never per frame: `spec` is a frozen module constant and
  // `target` is parsed from its hex once, so the frame body only reads numbers and lerps two Colors. The
  // JSX props below stay mount constants on purpose — a prop that tracked `spec` would be re-applied by
  // the reconciler on any re-render and fight the frame writes.
  const spec = useRef(lightTarget(agent));
  const from = useRef(new THREE.Color(spec.current.hex));
  const target = useRef(new THREE.Color(spec.current.hex));
  const scratch = useRef(new THREE.Color(spec.current.hex));
  const fromIntensity = useRef<number>(spec.current.base);
  const since = useRef(Infinity); // a mount lands directly on its target — nothing to blend from
  const lastAgent = useRef<AgentLight>(agent);
  const lastReduced = useRef(reduced);
  const settled = useRef(false);

  useFrame((state, delta) => {
    const light = agentRef.current;
    const spill = spillRef.current;
    if (!light || !spill) return;
    if (agent !== lastAgent.current) {
      from.current.copy(scratch.current);
      fromIntensity.current = light.intensity;
      since.current = 0;
      lastAgent.current = agent;
      spec.current = lightTarget(agent);
      target.current.set(spec.current.hex);
      settled.current = false;
    }
    if (reduced !== lastReduced.current) {
      lastReduced.current = reduced;
      settled.current = false;
    }
    if (settled.current) return;
    since.current += delta;
    const t = spec.current;
    const k = blend01(since.current, reduced);
    const b = reduced ? 0.5 : breathe01(state.clock.elapsedTime);
    scratch.current.lerpColors(from.current, target.current, k);
    const intensity = THREE.MathUtils.lerp(fromIntensity.current, lightIntensity(t, b), k);
    light.intensity = intensity;
    light.color.copy(scratch.current);
    spill.intensity = intensity * SPILL_RATIO;
    lensMat.color.copy(scratch.current).multiplyScalar(t.lens * (0.85 + 0.3 * b));
    // Cheap idle: a held result (amp 0) or a frozen breathe has nowhere left to go.
    if (k === 1 && (t.amp === 0 || reduced)) settled.current = true;
  });

  return (
    <group>
      <pointLight
        ref={agentRef}
        position={AGENT_LIGHT_POS}
        distance={AGENT_LIGHT_DISTANCE}
        decay={LIGHT_DECAY}
        color={HOLO_CYAN}
        intensity={AGENT_LIGHT_LEVELS.rest.base}
        castShadow={false}
      />
      <pointLight
        ref={spillRef}
        position={SPILL_POS}
        distance={SPILL_DISTANCE}
        decay={LIGHT_DECAY}
        color={HOLO_CYAN}
        intensity={AGENT_LIGHT_LEVELS.rest.base * SPILL_RATIO}
        castShadow={false}
      />
      {EMITTER_POSITIONS.map((pos, i) => (
        <group key={i} position={pos}>
          <mesh position-y={HOUSING_Y}>
            <cylinderGeometry args={[EMITTER.housing.radiusTop, EMITTER.housing.radiusBottom, EMITTER.housing.height, 24]} />
            <meshStandardMaterial color={EMITTER.housing.color} metalness={EMITTER.housing.metalness} roughness={EMITTER.housing.roughness} />
          </mesh>
          <mesh position-y={LENS_Y} material={lensMat}>
            <cylinderGeometry args={[EMITTER.lens.radius, EMITTER.lens.radius, EMITTER.lens.height, 24]} />
          </mesh>
        </group>
      ))}
    </group>
  );
}
