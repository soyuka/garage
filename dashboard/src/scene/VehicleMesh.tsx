import { useMemo, useEffect } from 'react';
import { useGLTF } from '@react-three/drei';
import { buildBlueprint } from './blueprintBuild';
import type { MeshSpec } from './vehicleMesh';

// Stage D body leaf — the real optimized glb rendered AS A BLUEPRINT: a faint paint-tinted volume under
// glowing steel-blue edges (the same recipe CarProxy draws on primitives, so the look is identical
// whether the mesh loads or we fall back). useGLTF suspends while loading and THROWS on 404/decode —
// VehicleBody owns the Suspense fallback + the error boundary that catches it. Edges are built per-mesh
// (drei <Edges> wraps a single geometry; a glb scene has many), mirroring theming/viewer.html:117-135.

// Same-origin vendored DRACO decoder (no CDN); must be set before any useGLTF resolves.
useGLTF.setDecoderPath('/draco/');

// Warm the small THUMB variants (not the heavy full glbs): the hero shows the thumb instantly while the
// full mesh streams in on demand (progressive low→high reveal, see VehicleBody.ThumbThenFull). Eagerly
// preloading the full glbs would defeat that — they'd be decoded before the hero mounts.
[
  '/mazda6-gh-thumb.glb',
  '/moto-default-thumb.glb',
  '/cv-default-thumb.glb',
  '/groundscare-default-thumb.glb',
  '/renault-espace-iv-thumb.glb',
].forEach((u) => useGLTF.preload(u));

export function VehicleMesh({ spec, paint }: { spec: MeshSpec; paint: string }) {
  const { scene } = useGLTF(spec.url);
  const built = useMemo(
    () => buildBlueprint(scene, { edgeAngle: spec.edgeAngle, rotationY: spec.rotationY, paint, cacheKey: spec.url }),
    [scene, spec.url, spec.edgeAngle, spec.rotationY, paint],
  );
  useEffect(() => built.dispose, [built]);

  return (
    <>
      <primitive object={built.clone} />
      <primitive object={built.edges} />
    </>
  );
}
