import { useMemo, useRef, useEffect } from 'react';
import { useFrame } from '@react-three/fiber';
import { useGLTF } from '@react-three/drei';
import * as THREE from 'three';
import { buildBlueprint } from './blueprintBuild';
import type { MeshSpec } from './vehicleMesh';

// Parc-grid thumbnail leaf — the low-poly blueprint spinning in its own drei <View>. Same look as the
// hero (buildBlueprint: steel-blue edges over a faint fill), but loads the `-thumb.glb` and, unlike the
// hero, NORMALIZES each vehicle to a unit box centred at the origin so one shared Canvas camera frames
// every card identically (a moto and a van each fill their own card). Spins via useFrame; skips the spin
// when off-screen (`active`) or reduced-motion (still renders, static). Edges and fill are both unlit
// (MeshBasicMaterial), so the shared Canvas needs no light for this leaf.

// Warm the drei cache for the parc grid (small thumbs; the cache dedups across same-type cards).
['/mazda6-gh-thumb.glb', '/moto-default-thumb.glb', '/cv-default-thumb.glb', '/groundscare-default-thumb.glb', '/renault-espace-iv-thumb.glb', '/peugeot-206-thumb.glb'].forEach(
  (u) => useGLTF.preload(u),
);

const SPIN = 0.5; // rad/s — slow turntable
const FIT = 2.2; // target max-dimension after normalization (matches the shared camera framing)

export function ParcThumb({
  spec,
  paint,
  active,
  reduced,
}: {
  spec: MeshSpec;
  paint: string;
  active: boolean;
  reduced: boolean;
}) {
  const { scene } = useGLTF(spec.thumbUrl);
  const built = useMemo(
    () => buildBlueprint(scene, { edgeAngle: spec.edgeAngle, rotationY: spec.rotationY, paint, cacheKey: spec.thumbUrl }),
    [scene, spec.thumbUrl, spec.edgeAngle, spec.rotationY, paint],
  );
  useEffect(() => built.dispose, [built]);

  // Normalize: scale to a unit box + recentre on the origin (the builder put the base at y=0; pull the
  // centre back to 0 so the turntable spins about the vehicle's middle and the shared camera frames it).
  const { scale, offset } = useMemo(() => {
    const box = new THREE.Box3().setFromObject(built.clone);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    const maxDim = Math.max(size.x, size.y, size.z) || 1;
    const s = FIT / maxDim;
    return { scale: s, offset: [-center.x * s, -center.y * s, -center.z * s] as [number, number, number] };
  }, [built]);

  const spin = useRef<THREE.Group>(null);
  useFrame((_, dt) => {
    if (active && !reduced && spin.current) spin.current.rotation.y += dt * SPIN;
  });

  return (
    <group ref={spin}>
      <group scale={scale} position={offset}>
        <primitive object={built.clone} />
        <primitive object={built.edges} />
      </group>
    </group>
  );
}
