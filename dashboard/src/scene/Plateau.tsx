import { Suspense, useEffect, useRef } from 'react';
import { Canvas, useFrame, useThree } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { Bloom, EffectComposer, SMAA } from '@react-three/postprocessing';
import { Vector3, type MeshStandardMaterial } from 'three';
import type { ForecastDue, Signal, VehicleSummary } from '../types';
import { bodyClassFor, type BodyClass } from './CarProxy';
import { SIGNAL_HEX, NEUTRAL_HEX, NEON_RED } from './signalColors';
import { computeRingSegments } from './ringSegments';
import { breathe01, podiumGlow } from './holo';
import { HoloPodium } from './HoloPodium';
import { CuttingMat } from './CuttingMat';
import { VehicleBody } from './VehicleBody';
import type { PulseRegion } from './VehicleMesh';
import {
  HOME,
  DOLLY_SECONDS,
  GATE_INIT,
  gateReduce,
  dragBreaksHold,
  presetForRegion,
  sineEaseInOut,
  type CameraFocus,
  type GateState,
} from './cameraRig';
import { SilhouetteCar } from './SilhouetteCar';
import './scene.css';

// The minimal imperative surface of the default OrbitControls (registered via makeDefault → r3f
// state.controls). CameraRig drives target + autoRotate and lerps the camera along the eased dolly.
interface OrbitLike {
  target: Vector3;
  autoRotate: boolean;
  update: () => void;
  addEventListener: (type: string, fn: () => void) => void;
  removeEventListener: (type: string, fn: () => void) => void;
}

// The earned camera (D1): dolly TO the focused region instead of dimming the stage. A held cameraFocus
// eases the camera + controls.target onto that region's preset (~900ms sine) and pauses autoRotate;
// release eases back to HOME and resumes it. Intent gates (cameraRig.gateReduce): a request arriving
// mid-drag is queued to pointerup; a user drag during a hold breaks it (onRelease clears the parent's
// focus, their input wins). Reduced motion CUTS (instant set). Renders nothing — a useFrame side-effect.
function CameraRig({
  bodyClass,
  cameraFocus,
  reduced,
  onRelease,
}: {
  bodyClass: BodyClass;
  cameraFocus: CameraFocus | null;
  reduced: boolean;
  onRelease: () => void;
}) {
  const controls = useThree((s) => s.controls) as unknown as OrbitLike | null;
  const camera = useThree((s) => s.camera);
  const gate = useRef<GateState>(GATE_INIT);
  const onReleaseRef = useRef(onRelease);
  onReleaseRef.current = onRelease;

  const startPos = useRef(new Vector3());
  const startTgt = useRef(new Vector3());
  const goalPos = useRef(new Vector3());
  const goalTgt = useRef(new Vector3());
  const progress = useRef(1); // [0,1] along the current dolly; 1 = settled
  const sig = useRef('home'); // the current destination signature — a change re-captures + re-eases

  // Reconcile the incoming focus into the gate (queued while dragging, adopted otherwise).
  useEffect(() => {
    gate.current = gateReduce(gate.current, { type: 'request', focus: cameraFocus });
  }, [cameraFocus]);

  // OrbitControls drag lifecycle. A drag that interrupts a hold clears the parent's cameraFocus first
  // (their input wins), then the gate drops the hold; a plain orbit just pauses/resumes autoRotate.
  useEffect(() => {
    if (!controls) return;
    const onStart = () => {
      if (dragBreaksHold(gate.current)) onReleaseRef.current();
      gate.current = gateReduce(gate.current, { type: 'dragStart' });
    };
    const onEnd = () => {
      gate.current = gateReduce(gate.current, { type: 'dragEnd' });
      // No queued follow-up → leave the camera where the user left it (don't yank it home); just let
      // autoRotate resume from here. A queued request instead dollies on the next frame (sig differs).
      if (gate.current.held == null) {
        sig.current = 'home';
        progress.current = 1;
      }
    };
    controls.addEventListener('start', onStart);
    controls.addEventListener('end', onEnd);
    return () => {
      controls.removeEventListener('start', onStart);
      controls.removeEventListener('end', onEnd);
    };
  }, [controls]);

  useFrame((_, delta) => {
    if (!controls) return;
    const g = gate.current;
    // autoRotate spins only at rest: no hold, not mid-drag, not reduced-motion.
    controls.autoRotate = !reduced && g.held == null && !g.dragging;
    if (g.dragging) {
      sig.current = '__drag__'; // the user owns the camera — re-arm so the next settle re-captures
      return;
    }
    const held = g.held;
    const nextSig = held ? `${held.region}:${held.key}` : 'home';
    if (nextSig !== sig.current) {
      sig.current = nextSig;
      // Compute the preset ONLY on a sig change (a fresh Record + Vec3 tuples per call) — a settled hold
      // re-enters this frame with nextSig === sig.current and skips it, so it costs zero allocation.
      const pose = held ? presetForRegion(bodyClass, held.region) : HOME;
      goalPos.current.set(pose.position[0], pose.position[1], pose.position[2]);
      goalTgt.current.set(pose.target[0], pose.target[1], pose.target[2]);
      if (reduced) {
        camera.position.copy(goalPos.current);
        controls.target.copy(goalTgt.current);
        controls.update();
        progress.current = 1;
        return;
      }
      startPos.current.copy(camera.position);
      startTgt.current.copy(controls.target);
      progress.current = 0;
    }
    if (progress.current >= 1) return;
    progress.current = Math.min(1, progress.current + delta / DOLLY_SECONDS);
    const e = sineEaseInOut(progress.current);
    camera.position.lerpVectors(startPos.current, goalPos.current, e);
    controls.target.lerpVectors(startTgt.current, goalTgt.current, e);
    controls.update();
  });

  return null;
}

// Le Plateau — the hero stage. The degradation ladder lives behind a manual `rung` prop (the seam
// Stage D's auto-detector plugs into): a=real .glb (Stage D; falls back to the proxy here) · b=R3F
// body-class proxy on the turntable · c=flat SVG silhouette · d=list-only (the scene shows the flat
// plan instead). WebGL is lazy-init'd by mounting the Canvas only on the 3D rungs.
export type Rung = 'a' | 'b' | 'c' | 'd';

// A tasteful default paint per make (the backend has no paint color yet — Stage C/D may resolve one).
// The family is deep blues: under the electric-blue edges the fill must read as tinted night glass,
// not a grey ghost — per-make variation is a hue lean, not a different color.
const PAINT: Record<string, string> = {
  Renault: '#1d3a5c',
  Peugeot: '#2c3060',
  Citroën: '#1f4550',
  Yamaha: '#16294d',
};
function paintFor(v: VehicleSummary): string {
  return PAINT[v.make] ?? '#20334f';
}

function SceneLights() {
  // Blueprint edges are unlit; only the turntable disc needs light. A warm key keeps the §2 mood.
  return (
    <>
      <ambientLight intensity={0.6} />
      <directionalLight position={[-5, 6, 4]} intensity={0.9} color="#ffe0b0" />
    </>
  );
}

// A thin tron-like neon rim (was a fat 0.12-wide band that read too "slick"). Kept just wide enough to
// glow without banding.
const RING_IN = 3.11;
const RING_OUT = 3.16;
const RING_SEGMENTS = 96;

// Apple sleep-LED breathe (breathe01, shared with the whole HoloPodium so the stage inhales in phase) on
// emissive — the same idle heartbeat as Le Noyau's veille. NOT a hard blink. Overdue (red) arcs run
// HOTTER and pulse HARDER than the healthy rim so a late car reads as urgent/alive at a glance; a green
// rim is a calm, faint swell.
const CALM = { base: 1.4, amp: 0.9 };
const URGENT = { base: 2.6, amp: 2.0 }; // red: much brighter floor + a bigger heartbeat swing

// The rim + podium's additive glow was tuned to read against a dark void; on the cutting-mat floor it
// must read as a reflection ON the mat, not fog flooding a lit floor — hence the flat cut to 0.55.
const PODIUM_INTENSITY = 0.55;

function RingSegment({
  color,
  overdue,
  thetaStart,
  thetaLength,
  reduced,
  intensity,
}: {
  color: string;
  overdue: boolean;
  thetaStart: number;
  thetaLength: number;
  reduced: boolean;
  intensity: number;
}) {
  const mat = useRef<MeshStandardMaterial>(null);
  const b = overdue ? URGENT : CALM;
  useFrame((state) => {
    if (!mat.current) return;
    if (reduced) {
      mat.current.emissiveIntensity = (b.base + b.amp * 0.5) * intensity;
      return;
    }
    mat.current.emissiveIntensity = (b.base + breathe01(state.clock.elapsedTime) * b.amp) * intensity;
  });
  return (
    <mesh position={[0, 0.03, 0]} rotation={[-Math.PI / 2, 0, 0]}>
      <ringGeometry args={[RING_IN, RING_OUT, RING_SEGMENTS, 1, thetaStart, thetaLength]} />
      <meshStandardMaterial ref={mat} color={color} emissive={color} emissiveIntensity={b.base * intensity} toneMapped={false} />
    </mesh>
  );
}

function Turntable({ status, due, reduced }: { status: Signal | 'unknown'; due: ForecastDue[]; reduced: boolean }) {
  // A single thin neon rim, divided into arcs by computeRingSegments so the red proportion matches how
  // much of the due work is overdue. Each arc is coloured PER ITEM, not by the car's overall status: an
  // overdue item = hot neon red, an on-track item = calm green, unknown = grey. So the ring reads as a
  // gauge (how much is red) rather than one flat status colour. Red arcs breathe harder.
  const rest = status === 'unknown' ? NEUTRAL_HEX : SIGNAL_HEX.green;
  const segments = computeRingSegments(due);
  return (
    <group>
      <mesh receiveShadow position={[0, -0.04, 0]}>
        <cylinderGeometry args={[3.2, 3.2, 0.12, 96]} />
        <meshStandardMaterial color="#1a1f26" metalness={0.78} roughness={0.38} />
      </mesh>
      {segments.map((seg, i) => (
        <RingSegment key={i} color={seg.overdue ? NEON_RED : rest} overdue={seg.overdue} thetaStart={seg.thetaStart} thetaLength={seg.thetaLength} reduced={reduced} intensity={PODIUM_INTENSITY} />
      ))}
      <HoloPodium glow={podiumGlow(status, due)} reduced={reduced} intensity={PODIUM_INTENSITY} />
    </group>
  );
}

function Plateau3D({
  vehicle,
  due,
  reduced,
  scanning,
  pulseRegion,
  cameraFocus,
  onCameraRelease,
}: {
  vehicle: VehicleSummary;
  due: ForecastDue[];
  reduced: boolean;
  scanning: boolean;
  pulseRegion: PulseRegion | null;
  cameraFocus: CameraFocus | null;
  onCameraRelease: () => void;
}) {
  const bodyClass = bodyClassFor(vehicle.vehicleType);
  return (
    <Canvas shadows dpr={[1, 1.75]} camera={{ position: HOME.position, fov: 33 }} gl={{ antialias: true }}>
      <color attach="background" args={['#131210']} />
      <SceneLights />
      <CuttingMat />
      <Suspense fallback={null}>
        <group position={[0, 0.04, 0]}>
          <VehicleBody key={vehicle['@id']} vehicle={vehicle} bodyClass={bodyClass} paint={paintFor(vehicle)} scanning={scanning} reduced={reduced} pulseRegion={pulseRegion} />
          {/* on-car sick-region orbs removed — health now reads on the segmented base ring + the échéance
              panels around the disc, so the body stays a clean blueprint (no floating red halos). */}
        </group>
        <Turntable status={vehicle.health?.status ?? 'unknown'} due={due} reduced={reduced} />
      </Suspense>
      {/* Bloom is what makes "light is meaning" physical: emissive arcs, the electric-blue edges and
          the shaft cage all bleed into the void, and the shared breathe reads as a visible glow swell
          (bare emissiveIntensity clamps invisibly without it). Threshold keeps the dim disc/fill out.
          multisampling MUST stay 0: the MSAA resolve path renders black on this stack (r169 +
          postprocessing 6.39) — SMAA stands in for line anti-aliasing instead. */}
      <EffectComposer multisampling={0}>
        <SMAA />
        <Bloom mipmapBlur intensity={1.05} luminanceThreshold={0.5} luminanceSmoothing={0.25} radius={0.72} />
      </EffectComposer>
      <OrbitControls
        makeDefault
        enablePan={false}
        enableZoom={false}
        autoRotateSpeed={0.55}
        minPolarAngle={Math.PI * 0.16}
        maxPolarAngle={Math.PI * 0.49}
        target={HOME.target}
      />
      {/* autoRotate is owned by CameraRig (it pauses during a dolly/hold, resumes at home) — a single
          owner avoids the prop fighting the imperative override each render. */}
      <CameraRig bodyClass={bodyClass} cameraFocus={cameraFocus} reduced={reduced} onRelease={onCameraRelease} />
    </Canvas>
  );
}

export function Plateau({
  vehicle,
  rung,
  due,
  reduced = false,
  scanning = false,
  pulseRegion = null,
  cameraFocus = null,
  onCameraRelease,
}: {
  vehicle: VehicleSummary;
  rung: Rung;
  due: ForecastDue[];
  reduced?: boolean;
  scanning?: boolean;
  pulseRegion?: PulseRegion | null;
  /** D1 earned camera: the region the view is dollied to (null = home framing). Absent → no dolly (playground). */
  cameraFocus?: CameraFocus | null;
  /** Clears the parent's cameraFocus when a user drag breaks the hold. Absent → no-op. */
  onCameraRelease?: () => void;
}) {
  if (rung === 'd') {
    return (
      <div className="at-plateau at-plateau--list">
        <p className="at-plateau-note">Mode liste — le plan d'entretien tient lieu de vue.</p>
      </div>
    );
  }
  if (rung === 'c') {
    return (
      <div className="at-plateau">
        <SilhouetteCar bodyClass={bodyClassFor(vehicle.vehicleType)} paint={paintFor(vehicle)} />
      </div>
    );
  }
  // a (no .glb yet → proxy) and b
  return (
    <div className="at-plateau">
      <Plateau3D
        vehicle={vehicle}
        due={due}
        reduced={reduced}
        scanning={scanning}
        pulseRegion={pulseRegion}
        cameraFocus={cameraFocus}
        onCameraRelease={onCameraRelease ?? (() => {})}
      />
    </div>
  );
}
