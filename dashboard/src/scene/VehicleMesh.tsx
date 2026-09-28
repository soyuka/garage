import { useMemo, useEffect, useRef } from 'react';
import { useGLTF } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { buildBlueprint, type GhostControl, type PulseControl, type ScanControl } from './blueprintBuild';
import type { MeshSpec } from './vehicleMesh';
import type { BodyRegion } from './anchors';
import { FLICKER_SECONDS, PULSE_DURATION, RISE_IN_SECONDS, flickerEnvelope, ghostStep, pulseEnvelope, regionAxisFraction, riseEnvelope } from './holo';
import type { HoloFillControl } from './holoFill';

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
  '/peugeot-206-thumb.glb',
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

// Drives the GHOST (C1): eases the shell toward `depth` so the reveal's diagram is not read through a
// painted body. Unlike the scan and the pulse, this is STATE, not attention — under reduced motion it
// CUTS to the target instead of going inert, because a wireframe shell with an assembly under it IS the
// information and the dissolve was only ever the transition into it (CHOREOGRAPHY, reveal table).
function GhostRunner({ ghost, depth, reduced }: { ghost: GhostControl; depth: number; reduced: boolean }) {
  const current = useRef(0);
  useFrame((_, delta) => {
    const next = reduced ? depth : ghostStep(current.current, depth, delta);
    if (next === current.current) return; // settled — no uniform write, no material dirtying
    current.current = next;
    ghost.apply(next);
  });
  return null;
}

// Drives the hologram fill: the scanline clock, the ARRIVAL flicker — a per-scanline dropout decaying
// over the etch step from the moment this mesh mounted (mount IS the arrival: VehicleBody keys on the
// vehicle id, and ThumbThenFull mounts the thumb then the full glb, each an arrival that fires once) —
// and, when `riseAge` is supplied, the projector-boot RISE (uRise 0→1 over RISE_IN_SECONDS). Flicker age
// runs on wall time in every mode so the window expires under reduced motion too — a later motion-on
// toggle cannot replay it. Under reduced motion uTime is frozen (static scanlines), the flicker never
// fires, and the rise (if driven) cuts straight to 1 (attention, not state — CHOREOGRAPHY row 2).
//
// `riseAge` is an OPTIONAL ref owned by the caller: VehicleBody creates one and keeps it across the
// proxy→thumb→full swap (it is keyed on the vehicle id, not the mesh), so the rise survives a swap that
// would otherwise restart it. Assembly's parts call HoloRunner with no `riseAge` and are unaffected —
// they drive their own uRise for the C1 reveal.
export function HoloRunner({ holo, reduced, riseAge }: { holo: HoloFillControl; reduced: boolean; riseAge?: { current: number } }) {
  const age = useRef(0);
  useFrame((state, delta) => {
    const dt = Math.min(delta, 0.05);
    age.current += dt;
    if (riseAge) riseAge.current += dt;
    if (reduced) {
      if (holo.uniforms.uFlicker.value !== 0) holo.uniforms.uFlicker.value = 0;
      if (riseAge && holo.uniforms.uRise.value !== 1) holo.uniforms.uRise.value = 1;
      return;
    }
    holo.uniforms.uTime.value = state.clock.elapsedTime;
    if (age.current >= FLICKER_SECONDS) {
      if (holo.uniforms.uFlicker.value !== 0) holo.uniforms.uFlicker.value = 0;
    } else {
      holo.uniforms.uFlicker.value = flickerEnvelope(age.current);
    }
    if (riseAge) holo.uniforms.uRise.value = riseEnvelope(riseAge.current, RISE_IN_SECONDS);
  });
  return null;
}

export function VehicleMesh({
  spec,
  paint,
  scanning = false,
  reduced = false,
  pulseRegion = null,
  ghostDepth = 0,
  riseAge,
}: {
  spec: MeshSpec;
  paint: string;
  scanning?: boolean;
  reduced?: boolean;
  pulseRegion?: PulseRegion | null;
  /** C1 reveal: 0 = the untouched blueprint, 1 = fill gone + edges at the floor. */
  ghostDepth?: number;
  /** The projector-boot clock, owned by VehicleBody and kept across a mesh swap (see HoloRunner). */
  riseAge?: { current: number };
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
      <PulseRunner pulse={built.pulse} pulseRegion={pulseRegion} reduced={reduced} />
      <GhostRunner ghost={built.ghost} depth={ghostDepth} reduced={reduced} />
      <HoloRunner holo={built.holo} reduced={reduced} riseAge={riseAge} />
    </>
  );
}
