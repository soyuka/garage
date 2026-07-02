import { useMemo, useRef } from 'react';
import { useFrame } from '@react-three/fiber';
import * as THREE from 'three';
import { breathe01 } from './holo';

// The holographic dressing of Le Plateau: etched concentric rings on the disc, echo halo rings on the
// floor beyond it, a cage of vertical light shafts rising off the rim, and slow upward ember particles.
// Everything luminous samples breathe01 off the shared clock — one apple sleep-LED inhale for the whole
// stage. Color comes in as a single `glow` (podiumGlow): the podium is the room-light of the car's
// overall state, while the segmented rim stays the per-item gauge. Under reduced motion every element
// freezes at mid-breath and the particles stop drifting.

const SHAFT_R = 3.13; // just inside the rim ring (3.11–3.16)
const SHAFT_H = 2.3;

/** Machined concentric grooves on the disc top — static, sub-bloom, structure not signal. */
export function EtchedRings() {
  const radii: Array<[number, number]> = [
    [1.02, 1.045],
    [1.82, 1.845],
    [2.56, 2.59],
  ];
  return (
    <group>
      {radii.map(([inner, outer], i) => (
        <mesh key={i} position={[0, 0.021, 0]} rotation={[-Math.PI / 2, 0, 0]}>
          <ringGeometry args={[inner, outer, 96]} />
          <meshBasicMaterial color="#2b3a4a" transparent opacity={0.8} depthWrite={false} />
        </mesh>
      ))}
    </group>
  );
}

/** Thin echo rings on the floor beyond the disc, fading outward — the podium's light spilling out. */
export function EchoRings({ glow, reduced }: { glow: string; reduced: boolean }) {
  const near = useRef<THREE.MeshBasicMaterial>(null);
  const far = useRef<THREE.MeshBasicMaterial>(null);
  useFrame((state) => {
    const b = reduced ? 0.5 : breathe01(state.clock.elapsedTime);
    if (near.current) near.current.opacity = 0.22 + 0.3 * b;
    if (far.current) far.current.opacity = 0.08 + 0.16 * b;
  });
  return (
    <group>
      <mesh position={[0, 0.002, 0]} rotation={[-Math.PI / 2, 0, 0]}>
        <ringGeometry args={[3.42, 3.47, 128]} />
        <meshBasicMaterial ref={near} color={glow} transparent opacity={0.37} blending={THREE.AdditiveBlending} depthWrite={false} toneMapped={false} />
      </mesh>
      <mesh position={[0, 0.001, 0]} rotation={[-Math.PI / 2, 0, 0]}>
        <ringGeometry args={[3.74, 3.765, 128]} />
        <meshBasicMaterial ref={far} color={glow} transparent opacity={0.16} blending={THREE.AdditiveBlending} depthWrite={false} toneMapped={false} />
      </mesh>
    </group>
  );
}

const SHAFT_VERT = /* glsl */ `
  varying vec2 vUv;
  void main() {
    vUv = uv;
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
  }
`;

// Vertical light rays rising off the rim: alpha falls off with height, broken into discrete shafts
// around the circumference (the hologram-podium look), slowly drifting. Additive, so black is "off".
const SHAFT_FRAG = /* glsl */ `
  uniform vec3 uColor;
  uniform float uBreathe;
  uniform float uTime;
  varying vec2 vUv;
  void main() {
    float fall = pow(1.0 - vUv.y, 2.2);
    float a1 = sin(vUv.x * 96.0 + uTime * 0.22);
    float a2 = sin(vUv.x * 42.0 - uTime * 0.13 + 1.7);
    float rays = 0.42 + 0.36 * a1 + 0.24 * a2;
    rays = clamp(rays, 0.0, 1.0);
    float alpha = fall * rays * (0.14 + 0.2 * uBreathe);
    gl_FragColor = vec4(uColor * (1.1 + 0.9 * uBreathe) * alpha, alpha);
  }
`;

export function LightShafts({ glow, reduced }: { glow: string; reduced: boolean }) {
  const mat = useRef<THREE.ShaderMaterial>(null);
  const uniforms = useMemo(
    () => ({
      uColor: { value: new THREE.Color(glow) },
      uBreathe: { value: 0.5 },
      uTime: { value: 0 },
    }),
    [],
  );
  uniforms.uColor.value.set(glow);
  useFrame((state) => {
    if (!mat.current) return;
    if (reduced) {
      uniforms.uBreathe.value = 0.5;
      return;
    }
    uniforms.uBreathe.value = breathe01(state.clock.elapsedTime);
    uniforms.uTime.value = state.clock.elapsedTime;
  });
  return (
    <mesh position={[0, SHAFT_H / 2 + 0.02, 0]}>
      <cylinderGeometry args={[SHAFT_R, SHAFT_R, SHAFT_H, 128, 1, true]} />
      <shaderMaterial
        ref={mat}
        uniforms={uniforms}
        vertexShader={SHAFT_VERT}
        fragmentShader={SHAFT_FRAG}
        transparent
        depthWrite={false}
        side={THREE.BackSide}
        blending={THREE.AdditiveBlending}
        toneMapped={false}
      />
    </mesh>
  );
}

export function HoloPodium({ glow, reduced }: { glow: string; reduced: boolean }) {
  return (
    <group>
      <EtchedRings />
      <EchoRings glow={glow} reduced={reduced} />
      <LightShafts glow={glow} reduced={reduced} />
    </group>
  );
}
