import { useMemo, useEffect, useRef } from 'react';
import { useGLTF } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { buildBlueprint, type ScanControl } from './blueprintBuild';
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

// Drives the holo scan band: ping-pongs the cyan brightening front↔tail along the car while `active`, and
// eases it on/off (so it fades in on turn:start, out on turn:end). No-op under reduced motion. A pure
// useFrame side-effect on shared uniforms — renders nothing.
const SWEEP_SPEED = 2.3; // rad/s → ~2.7s per there-and-back cycle (a visible travelling wave)
const RAMP = 3.5; // on/off ease rate

function ScanRunner({ scan, active, reduced }: { scan: ScanControl; active: boolean; reduced: boolean }) {
  const phase = useRef(0);
  const on = useRef(0);
  useFrame((_, delta) => {
    const d = Math.min(delta, 0.05); // clamp a tab-restore hitch
    const target = active && !reduced ? 1 : 0;
    on.current += (target - on.current) * Math.min(1, d * RAMP);
    scan.uniforms.uScanOn.value = on.current;
    if (on.current < 0.002) return;
    phase.current += d * SWEEP_SPEED;
    // triangle wave in [-1,1] → constant-speed sweep with sharp turnarounds (a wave, not a slow pulse)
    const tri = (2 / Math.PI) * Math.asin(Math.sin(phase.current));
    scan.uniforms.uScanX.value = tri * scan.halfLen;
  });
  return null;
}

export function VehicleMesh({
  spec,
  paint,
  scanning = false,
  reduced = false,
}: {
  spec: MeshSpec;
  paint: string;
  scanning?: boolean;
  reduced?: boolean;
}) {
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
      <ScanRunner scan={built.scan} active={scanning} reduced={reduced} />
    </>
  );
}
