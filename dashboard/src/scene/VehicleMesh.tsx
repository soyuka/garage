import { useMemo, useEffect, useRef } from 'react';
import { useGLTF } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { buildBlueprint, type PulseControl, type ScanControl } from './blueprintBuild';
import type { MeshSpec } from './vehicleMesh';
import type { BodyRegion } from './anchors';
import type { StageVariant } from './stageVariant';
import { PULSE_DURATION, pulseEnvelope, regionAxisFraction } from './holo';

/** A one-shot region flare (PULSE verb): when `region`+`key` change, play the eased cyan envelope ONCE on
 * that region's slice, then rest. Idempotent per key — even if the prop lingers, it fires once. No-op
 * under reduced motion (attention, not state → nothing static). A useFrame side-effect; renders nothing. */
export interface PulseRegion {
  region: BodyRegion;
  key: number;
}

function PulseRunner({ pulse, pulseRegion, reduced }: { pulse: PulseControl; pulseRegion: PulseRegion | null; reduced: boolean }) {
  const elapsed = useRef(Infinity); // at rest = past the end of the window
  const lastKey = useRef(pulseRegion?.key ?? -1); // seed so a mount does not replay a stale pulse
  const centerFrac = useRef(0);
  useFrame((_, delta) => {
    if (reduced) {
      pulse.uniforms.uPulseOn.value = 0;
      return;
    }
    if (pulseRegion && pulseRegion.key !== lastKey.current) {
      lastKey.current = pulseRegion.key;
      elapsed.current = 0;
      centerFrac.current = regionAxisFraction(pulseRegion.region);
      pulse.uniforms.uPulseCenter.value = centerFrac.current * pulse.halfLen;
    }
    if (elapsed.current >= PULSE_DURATION) {
      pulse.uniforms.uPulseOn.value = 0;
      return;
    }
    elapsed.current += Math.min(delta, 0.05); // clamp a tab-restore hitch
    pulse.uniforms.uPulseOn.value = pulseEnvelope(elapsed.current);
  });
  return null;
}

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
  pulseRegion = null,
  stageVariant = 'mat',
}: {
  spec: MeshSpec;
  paint: string;
  scanning?: boolean;
  reduced?: boolean;
  pulseRegion?: PulseRegion | null;
  /** Stage look (stageVariant.ts) — closes the .glb gap: reuses CarProxy's porcelain fill under 'mat'. */
  stageVariant?: StageVariant;
}) {
  const { scene } = useGLTF(spec.url);
  const built = useMemo(
    () => buildBlueprint(scene, { edgeAngle: spec.edgeAngle, rotationY: spec.rotationY, paint, cacheKey: spec.url, stageVariant }),
    [scene, spec.url, spec.edgeAngle, spec.rotationY, paint, stageVariant],
  );
  useEffect(() => built.dispose, [built]);

  return (
    <>
      <primitive object={built.clone} />
      <primitive object={built.edges} />
      <ScanRunner scan={built.scan} active={scanning} reduced={reduced} />
      <PulseRunner pulse={built.pulse} pulseRegion={pulseRegion} reduced={reduced} />
    </>
  );
}
