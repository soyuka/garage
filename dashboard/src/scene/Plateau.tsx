import { memo, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { Canvas, useFrame, useThree } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import { Bloom, EffectComposer, SMAA, Vignette } from '@react-three/postprocessing';
import { Vector3, type MeshStandardMaterial } from 'three';
import type { ForecastDue, Signal, VehicleSummary } from '../types';
import { bodyClassFor, type BodyClass } from './CarProxy';
import { SIGNAL_HEX, NEUTRAL_HEX, NEON_RED } from './signalColors';
import { computeRingSegments } from './ringSegments';
import { breathe01, ghostTarget, podiumGlow } from './holo';
import { Assembly } from './Assembly';
import type { RevealState } from './reveal';
import { HoloPodium } from './HoloPodium';
import { CuttingMat } from './CuttingMat';
import { AgentLightRig } from './AgentLightRig';
import { DISC, ROOM_AMBIENT, ROOM_AMBIENT_COLOR, ROOM_BACKGROUND, type AgentLight } from './agentLight';
import { VehicleBody } from './VehicleBody';
import type { PulseRegion } from './VehicleMesh';
import {
  HOME,
  POLAR,
  DOLLY_SECONDS,
  GATE_INIT,
  gateReduce,
  dragBreaksHold,
  presetForFocus,
  sineEaseInOut,
  type CameraFocus,
  type GateState,
} from './cameraRig';
import { SilhouetteCar } from './SilhouetteCar';
import { VisitRing } from './VisitRing';
import type { VisitSlab } from './visitRing';
import { FactConstellation } from './FactConstellation';
import type { FactNode } from './factConstellation';
import { FluidVolumes } from './FluidVolumes';
import type { FluidCylinder } from './fluidVolumes';
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
    const nextSig = held ? `${held.region}:${held.mode ?? 'focus'}:${held.legend ?? 'column'}:${held.avoidProjection ? 'projection' : 'stage'}:${held.key}` : 'home';
    if (nextSig !== sig.current) {
      sig.current = nextSig;
      // Compute the preset ONLY on a sig change (a fresh Record + Vec3 tuples per call) — a settled hold
      // re-enters this frame with nextSig === sig.current and skips it, so it costs zero allocation.
      const pose = held ? presetForFocus(bodyClass, held.region, held.mode, held.legend, held.avoidProjection) : HOME;
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
  // The room is dark; the hologram lights the disc and the mat ring (CHOREOGRAPHY § The hologram is the
  // light). No key: the car is a projection, and a lit floor under it was a desk lamp left on.
  return <ambientLight intensity={ROOM_AMBIENT} color={ROOM_AMBIENT_COLOR} />;
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

// The rim + podium's additive glow — seen against the dark room and kept (CHOREOGRAPHY § Stage look, 2026-09-04).
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
        <meshStandardMaterial color={DISC.color} metalness={DISC.metalness} roughness={DISC.roughness} />
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
  agent,
  pulseRegion,
  cameraFocus,
  reveal,
  visits,
  hoveredVisit,
  onHoverVisit,
  onPickVisit,
  facts,
  hoveredFact,
  onHoverFact,
  onPickFact,
  fluids,
  hoveredFluid,
  onHoverFluid,
  onPickFluid,
  onCameraRelease,
}: {
  vehicle: VehicleSummary;
  due: ForecastDue[];
  reduced: boolean;
  scanning: boolean;
  agent: AgentLight;
  pulseRegion: PulseRegion | null;
  cameraFocus: CameraFocus | null;
  reveal: RevealState | null;
  visits: VisitSlab[] | null;
  hoveredVisit: string | null;
  onHoverVisit?: (key: string | null) => void;
  onPickVisit?: (key: string) => void;
  facts: FactNode[] | null;
  hoveredFact: string | null;
  onHoverFact?: (key: string | null) => void;
  onPickFact?: (key: string) => void;
  fluids: FluidCylinder[] | null;
  hoveredFluid: string | null;
  onHoverFluid?: (key: string | null) => void;
  onPickFluid?: (key: string) => void;
  onCameraRelease: () => void;
}) {
  const bodyClass = bodyClassFor(vehicle.vehicleType);
  // Unlike the reveal (unmount IS the dismiss), the ring sinks before it unmounts: keep it mounted a beat
  // after `visits` goes null, frozen on the last layout, until the ring itself reports the reverse settled.
  const [ringMounted, setRingMounted] = useState(visits != null);
  const lastVisits = useRef<VisitSlab[]>(visits ?? []);
  if (visits) lastVisits.current = visits;
  if (visits != null && !ringMounted) setRingMounted(true);
  const sinkingRing = visits == null && ringMounted;
  const onRingSunk = useCallback(() => setRingMounted(false), []);
  // Same keep-mounted-while-sinking pattern for the fact constellation (§ "Data — the fact constellation").
  const [constellationMounted, setConstellationMounted] = useState(facts != null);
  const lastFacts = useRef<FactNode[]>(facts ?? []);
  if (facts) lastFacts.current = facts;
  if (facts != null && !constellationMounted) setConstellationMounted(true);
  const sinkingConstellation = facts == null && constellationMounted;
  const onConstellationSunk = useCallback(() => setConstellationMounted(false), []);
  // Same keep-mounted-while-sinking pattern for the fluid volumes (§ "Fluids — the volumes").
  const [fluidsMounted, setFluidsMounted] = useState(fluids != null);
  const lastFluids = useRef<FluidCylinder[]>(fluids ?? []);
  if (fluids) lastFluids.current = fluids;
  if (fluids != null && !fluidsMounted) setFluidsMounted(true);
  const sinkingFluids = fluids == null && fluidsMounted;
  const onFluidsSunk = useCallback(() => setFluidsMounted(false), []);
  // C1↔C3: the shell's ghost depth is the fraction of the diagram that has arrived, so the dissolve is
  // paced by the reveal's own parts. Kept in a ref-backed state that only changes on a landed-count
  // change (Assembly reports edge-triggered), so a settled reveal re-renders nothing.
  const [landed, setLanded] = useState({ landed: 0, total: 0 });
  const revealKey = reveal ? `${reveal.region}:${reveal.key}` : null;
  const lastKey = useRef<string | null>(null);
  if (revealKey !== lastKey.current) {
    lastKey.current = revealKey;
    if (landed.landed !== 0 || landed.total !== 0) setLanded({ landed: 0, total: 0 });
  }
  const onPartsLanded = useCallback((l: number, total: number) => setLanded({ landed: l, total }), []);
  const ghostDepth = reveal ? ghostTarget(landed.landed, landed.total) : 0;
  return (
    <Canvas shadows dpr={[1, 1.75]} camera={{ position: HOME.position, fov: 33 }} gl={{ antialias: true }}>
      <color attach="background" args={[ROOM_BACKGROUND]} />
      <SceneLights />
      <CuttingMat />
      <Suspense fallback={null}>
        <group position={[0, 0.04, 0]}>
          <VehicleBody key={vehicle['@id']} vehicle={vehicle} bodyClass={bodyClass} paint={paintFor(vehicle)} scanning={scanning} reduced={reduced} pulseRegion={pulseRegion} ghostDepth={ghostDepth} />
          {/* The reveal's diagram. Mounted only while it holds — unmount IS the dismiss, so there is no
              hidden geometry and no second state to keep in step with the ghost. */}
          {reveal && (
            <Assembly
              key={`${reveal.region}:${reveal.key}`}
              bodyClass={bodyClass}
              region={reveal.region}
              partKeys={reveal.partKeys}
              reduced={reduced}
              onPartsLanded={onPartsLanded}
            />
          )}
          {/* on-car sick-region orbs removed — health now reads on the segmented base ring + the échéance
              panels around the disc, so the body stays a clean blueprint (no floating red halos). */}
          {ringMounted && (
            <VisitRing
              slabs={visits ?? lastVisits.current}
              hovered={hoveredVisit}
              reduced={reduced}
              sinking={sinkingRing}
              onSunk={onRingSunk}
              onHover={onHoverVisit}
              onPick={onPickVisit}
            />
          )}
          {constellationMounted && (
            <FactConstellation
              nodes={facts ?? lastFacts.current}
              hovered={hoveredFact}
              reduced={reduced}
              sinking={sinkingConstellation}
              onSunk={onConstellationSunk}
              onHover={onHoverFact}
              onPick={onPickFact}
            />
          )}
          {fluidsMounted && (
            <FluidVolumes
              cylinders={fluids ?? lastFluids.current}
              hovered={hoveredFluid}
              reduced={reduced}
              sinking={sinkingFluids}
              onSunk={onFluidsSunk}
              onHover={onHoverFluid}
              onPick={onPickFluid}
            />
          )}
        </group>
        <Turntable status={vehicle.health?.status ?? 'unknown'} due={due} reduced={reduced} />
        <AgentLightRig agent={agent} reduced={reduced} />
      </Suspense>
      {/* Bloom is what makes "light is meaning" physical: emissive arcs, the electric-blue edges and
          the shaft cage all bleed into the void, and the shared breathe reads as a visible glow swell
          (bare emissiveIntensity clamps invisibly without it). Threshold keeps the dim disc/fill out.
          multisampling MUST stay 0: the MSAA resolve path renders black on this stack (r169 +
          postprocessing 6.39) — SMAA stands in for line anti-aliasing instead. */}
      <EffectComposer multisampling={0}>
        <SMAA />
        <Bloom mipmapBlur intensity={1.05} luminanceThreshold={0.5} luminanceSmoothing={0.25} radius={0.72} />
        <Vignette eskil={false} offset={0.32} darkness={0.55} />
      </EffectComposer>
      <OrbitControls
        makeDefault
        enablePan={false}
        enableZoom={false}
        autoRotateSpeed={0.55}
        minPolarAngle={POLAR.min}
        maxPolarAngle={POLAR.max}
        target={HOME.target}
      />
      {/* autoRotate is owned by CameraRig (it pauses during a dolly/hold, resumes at home) — a single
          owner avoids the prop fighting the imperative override each render. */}
      <CameraRig bodyClass={bodyClass} cameraFocus={cameraFocus} reduced={reduced} onRelease={onCameraRelease} />
    </Canvas>
  );
}

// memo'd: the R3F <Canvas> subtree is expensive to reconcile, and the live orchestrator re-renders on
// every composer keystroke (a `typing` dispatch) with the SAME vehicle/due/camera props. Skipping those
// renders keeps the scene idle unless a prop that concerns it actually changes. Requires the parent to
// pass a stable onCameraRelease (Atelier wraps it in useCallback).
function PlateauImpl({
  vehicle,
  rung,
  due,
  reduced = false,
  scanning = false,
  agent = 'rest',
  pulseRegion = null,
  cameraFocus = null,
  reveal = null,
  visits = null,
  hoveredVisit = null,
  onHoverVisit,
  onPickVisit,
  facts = null,
  hoveredFact = null,
  onHoverFact,
  onPickFact,
  fluids = null,
  hoveredFluid = null,
  onHoverFluid,
  onPickFluid,
  onCameraRelease,
}: {
  vehicle: VehicleSummary;
  rung: Rung;
  due: ForecastDue[];
  reduced?: boolean;
  scanning?: boolean;
  /** Le Noyau's state on the stage lights (CHOREOGRAPHY § The hologram is the light). A primitive, so the
   * memo below stays effective. Absent → rest (playground). */
  agent?: AgentLight;
  pulseRegion?: PulseRegion | null;
  /** D1 earned camera: the region the view is dollied to (null = home framing). Absent → no dolly (playground). */
  cameraFocus?: CameraFocus | null;
  /** C1–C4 reveal: the region's schematic assembly to raise under a ghosted shell. Absent → no reveal. */
  reveal?: RevealState | null;
  /** The visit ring's slabs (§ "History — the visit ring") — non-null raises the ring. Absent → no ring. */
  visits?: VisitSlab[] | null;
  /** The Carnet entry hovered/focused in the DOM — that slab's edges take the operator hue. */
  hoveredVisit?: string | null;
  /** A slab hovered/unhovered on the stage (§ "Picking — the body points back"). Absent → no wiring. */
  onHoverVisit?: (key: string | null) => void;
  /** A slab clicked on the stage. Absent → no wiring. */
  onPickVisit?: (key: string) => void;
  /** The fact constellation's nodes (§ "Data — the fact constellation") — non-null raises it. Absent → none. */
  facts?: FactNode[] | null;
  /** The fiche row hovered/focused in the DOM — that node's edges take the operator hue. */
  hoveredFact?: string | null;
  /** A node hovered/unhovered on the stage (§ "Picking — the body points back"). Absent → no wiring. */
  onHoverFact?: (key: string | null) => void;
  /** A node clicked on the stage. Absent → no wiring. */
  onPickFact?: (key: string) => void;
  /** The fluid volumes' cylinders (§ "Fluids — the volumes") — non-null raises the row. Absent → none. */
  fluids?: FluidCylinder[] | null;
  /** The specs constellation node hovered/focused in the DOM — that cylinder's edges take the operator hue. */
  hoveredFluid?: string | null;
  /** A cylinder hovered/unhovered on the stage (§ "Picking — the body points back"). Absent → no wiring. */
  onHoverFluid?: (key: string | null) => void;
  /** A cylinder clicked on the stage. Absent → no wiring. */
  onPickFluid?: (key: string) => void;
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
        agent={agent}
        pulseRegion={pulseRegion}
        cameraFocus={cameraFocus}
        reveal={reveal}
        visits={visits}
        hoveredVisit={hoveredVisit}
        onHoverVisit={onHoverVisit}
        onPickVisit={onPickVisit}
        facts={facts}
        hoveredFact={hoveredFact}
        onHoverFact={onHoverFact}
        onPickFact={onPickFact}
        fluids={fluids}
        hoveredFluid={hoveredFluid}
        onHoverFluid={onHoverFluid}
        onPickFluid={onPickFluid}
        onCameraRelease={onCameraRelease ?? (() => {})}
      />
    </div>
  );
}

export const Plateau = memo(PlateauImpl);
