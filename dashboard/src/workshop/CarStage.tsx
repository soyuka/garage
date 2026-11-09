import { memo, Suspense, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type RefObject } from 'react';
import { Canvas, useFrame, useThree } from '@react-three/fiber';
import { Html, OrbitControls } from '@react-three/drei';
import * as THREE from 'three';
import { useT } from '../i18n/LocaleProvider';
import type { BodyRegion } from '../scene/anchors';
import { MeshErrorBoundary } from '../scene/VehicleBody';
import type { VehicleSummary } from '../types';
import { EdgeModel, useTokenColor } from './EdgeModel';
import { parcThumbUrl, vehicleMesh } from './genericBody';
import { fitCentered } from './edgeDraw';
import { regionPoint, type BodyBox } from './regionPoint';
import { vehicleName } from './TopBar';
import { autoRotateSpeed, easeOutExpo, FACE_MS, facingAzimuth, RESET_MS, RESUME_MS, shortestTurn, TURN_SECONDS, turntableSpins } from './turntable';

// Every mesh is normalised to one length so a single camera frames the parc's cars, the moto and the
// mower alike; the ring anchors read off that normalised box.
export const LENGTH = 4.4;
const CAMERA: [number, number, number] = [-5.6, 2.3, 6.4];
const TARGET: [number, number, number] = [0, 0.72, 0];
const VIEW_DIR = new THREE.Vector3(...CAMERA).sub(new THREE.Vector3(...TARGET)).normalize();
const HOME = { theta: Math.atan2(VIEW_DIR.x, VIEW_DIR.z), phi: Math.acos(VIEW_DIR.y) };

/** Edge threshold per mesh: dense full meshes need a coarser angle than the decimated ones to stay legible. */
export function carEdgeAngle(url: string): number {
  if (url.includes('moto')) return 48;
  if (url.endsWith('-thumb.glb')) return 26;
  return 36;
}

/**
 * Vehicles keep only confident strokes: no soft creases, no hatching shorter than 3% of their length.
 * Only closed bodies (cars, vans) hide their interior behind the hull; an open frame (moto, mower) is
 * mostly not on its hull and would lose its own lines.
 */
export function vehicleLines(vehicleType: string): { secondary: false; minStroke: number; occludeInterior: boolean; wheels: boolean } {
  const open = /moto|motorcycle|groundscare|mower/i.test(vehicleType);
  return { secondary: false, minStroke: open ? 0.015 : 0.03, occludeInterior: !open, wheels: !open };
}

/**
 * Back the camera off along the 3/4 view until every corner of the mesh's box fits the frame, using the
 * element's real aspect (a drei View reports the shared canvas's size, so thumbnails pass their own).
 */
export function FitCamera({ box, points, aspect, margin = 1.18 }: { box: BodyBox | null; points?: Float32Array | null; aspect?: number; margin?: number }) {
  const { camera, size } = useThree();
  const controls = useThree((state) => state.controls) as { target: THREE.Vector3; update: () => void } | null;
  useLayoutEffect(() => {
    const cam = camera as THREE.PerspectiveCamera;
    const a = aspect ?? size.width / Math.max(1, size.height);
    const b = box ?? { length: LENGTH, height: 1.6, width: 1.8 };
    const dir: [number, number, number] = [VIEW_DIR.x, VIEW_DIR.y, VIEW_DIR.z];
    // The mesh's own points, else its box corners: the silhouette is centred in the frame with `margin`.
    const cloud = points && points.length > 0 ? points : boxCorners(b);
    const { target, distance } = fitCentered(cloud, dir, cam.fov, a, margin);
    const t = new THREE.Vector3(...target);
    cam.position.copy(t).addScaledVector(VIEW_DIR, distance);
    cam.aspect = a;
    cam.lookAt(t);
    cam.updateProjectionMatrix();
    if (controls) {
      controls.target.copy(t);
      controls.update();
    }
  }, [camera, controls, size.width, size.height, box, points, aspect, margin]);
  return null;
}

function boxCorners(b: BodyBox): Float32Array {
  const out: number[] = [];
  for (const sx of [-1, 1]) for (const y of [0, b.height]) for (const sz of [-1, 1]) out.push((sx * b.length) / 2, y, (sz * b.width) / 2);
  return new Float32Array(out);
}

function RegionRing({ region, box, label, onClosed }: { region: BodyRegion; box: BodyBox; label: string; onClosed?: () => void }) {
  const point = regionPoint(region, box);
  return (
    <Html position={point} center zIndexRange={[20, 0]} className="w-ring-anchor">
      <div className="w-ring" aria-hidden="true">
        <svg className="w-ring-circle" width="56" height="56" viewBox="0 0 56 56">
          <circle cx="28" cy="28" r="27" pathLength={100} transform="rotate(-90 28 28)" onAnimationEnd={onClosed} />
        </svg>
        <span className="w-ring-leader" />
        <span className="w-ring-label">{label}</span>
      </div>
    </Html>
  );
}

interface Ease {
  from: { theta: number; phi: number };
  turn: { theta: number; phi: number };
  elapsed: number;
  duration: number;
  done?: () => void;
}

type OrbitLike = THREE.EventDispatcher<{ start: object; end: object }> & {
  target: THREE.Vector3;
  object: THREE.Camera;
  autoRotate: boolean;
  autoRotateSpeed: number;
  getAzimuthalAngle: () => number;
  getPolarAngle: () => number;
  update: () => void;
};

/**
 * Drives the orbit from outside the hand: the turntable (autoRotate), the swing that turns a pointed
 * region towards the viewer, and the double-click return to the opening three-quarter view.
 */
function CameraRig({ spin, face, onFaced, resetSeq, reduced, onDrag }: { spin: boolean; face: { seq: number; point: [number, number, number] } | null; onFaced: (seq: number) => void; resetSeq: number; reduced: boolean; onDrag: (dragging: boolean) => void }) {
  const controls = useThree((state) => state.controls) as unknown as OrbitLike | null;
  const ease = useRef<Ease | null>(null);
  const spherical = useMemo(() => new THREE.Spherical(), []);
  const offset = useMemo(() => new THREE.Vector3(), []);

  useEffect(() => {
    if (!controls) return;
    // A hand on the car ends any swing where it stands; the pointing carries on from there.
    const start = () => {
      const e = ease.current;
      ease.current = null;
      e?.done?.();
      onDrag(true);
    };
    const end = () => onDrag(false);
    controls.addEventListener('start', start);
    controls.addEventListener('end', end);
    return () => {
      controls.removeEventListener('start', start);
      controls.removeEventListener('end', end);
    };
  }, [controls, onDrag]);

  const place = useCallback(
    (theta: number, phi: number) => {
      if (!controls) return;
      offset.copy(controls.object.position).sub(controls.target);
      spherical.setFromVector3(offset);
      spherical.theta = theta;
      spherical.phi = phi;
      controls.object.position.copy(controls.target).add(offset.setFromSpherical(spherical));
      controls.update();
    },
    [controls, offset, spherical],
  );

  const startEase = useCallback(
    (theta: number, phi: number, duration: number, done?: () => void) => {
      if (!controls) return;
      const from = { theta: controls.getAzimuthalAngle(), phi: controls.getPolarAngle() };
      if (reduced) {
        place(theta, phi);
        ease.current = null;
        done?.();
        return;
      }
      ease.current = { from, turn: { theta: shortestTurn(from.theta, theta), phi: phi - from.phi }, elapsed: 0, duration, done };
    },
    [controls, place, reduced],
  );

  useEffect(() => {
    if (!face || !controls) return;
    const t = controls.target;
    const theta = facingAzimuth(face.point, [t.x, t.y, t.z], controls.getAzimuthalAngle());
    startEase(theta, controls.getPolarAngle(), FACE_MS, () => onFaced(face.seq));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [face?.seq, controls]);

  const firstReset = useRef(resetSeq);
  useEffect(() => {
    if (resetSeq === firstReset.current) return;
    startEase(HOME.theta, HOME.phi, RESET_MS);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resetSeq]);

  useFrame((_, delta) => {
    if (!controls) return;
    controls.autoRotateSpeed = autoRotateSpeed(TURN_SECONDS);
    controls.autoRotate = spin && ease.current === null;
    const e = ease.current;
    if (!e) return;
    e.elapsed += Math.min(delta, 0.05) * 1000;
    const k = easeOutExpo(e.elapsed / e.duration);
    place(e.from.theta + e.turn.theta * k, e.from.phi + e.turn.phi * k);
    if (k >= 1) {
      ease.current = null;
      e.done?.();
    }
  });
  return null;
}

/** On screen and in a visible tab: the turntable has no business spinning otherwise. */
function useOnScreen(el: RefObject<HTMLElement | null>): boolean {
  const [inView, setInView] = useState(true);
  const [shown, setShown] = useState(() => typeof document === 'undefined' || document.visibilityState !== 'hidden');
  useEffect(() => {
    const node = el.current;
    if (!node) return;
    const io = new IntersectionObserver(([entry]) => setInView(entry.isIntersecting));
    io.observe(node);
    const onVisibility = () => setShown(document.visibilityState !== 'hidden');
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      io.disconnect();
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, [el]);
  return inView && shown;
}

// The pointing sequence a stage last played, across remounts: returning to a section does not point again.
let lastPointed = 0;

function Failed({ onFail }: { onFail: () => void }) {
  useEffect(onFail, [onFail]);
  return null;
}

interface CarStageProps {
  vehicle: VehicleSummary;
  focusRegion: BodyRegion | null;
  ringLabel: string | null;
  reduced: boolean;
  /** The view's focusSeq: a new value makes the stage point at focusRegion (swing, ring, blue redraw). Absent = the ring just shows. */
  pointSeq?: number;
  /** The ring has closed on a pointed region. */
  onPoint?: () => void;
  /** The stored turntable preference; absent = the stage never spins. */
  turntable?: boolean;
}

function CarStageImpl({ vehicle, focusRegion, ringLabel, reduced, pointSeq, onPoint, turntable = false }: CarStageProps) {
  const t = useT();
  const spec = useMemo(() => vehicleMesh(vehicle), [vehicle.make, vehicle.model, vehicle.vehicleType]);
  const url = parcThumbUrl(vehicle);
  const [failed, setFailed] = useState(false);
  const [box, setBox] = useState<BodyBox | null>(null);
  const onBox = useCallback((b: BodyBox) => setBox(b), []);
  const [points, setPoints] = useState<Float32Array | null>(null);
  useEffect(() => setFailed(false), [url]);
  const ink = useTokenColor('--w-ink');
  const blue = useTokenColor('--w-blue');
  const regionLabel = focusRegion ? (ringLabel ?? t.region[focusRegion]) : null;
  const focusPoint = focusRegion && box ? regionPoint(focusRegion, box) : null;

  // ── Pointing: swing the region to the viewer, then close the ring and redraw its strokes in blue. ──
  const [pointing, setPointing] = useState<{ seq: number; stage: 'turning' | 'ring' } | null>(null);
  const [drawSeq, setDrawSeq] = useState<number | undefined>(undefined);
  const onPointRef = useRef(onPoint);
  onPointRef.current = onPoint;
  useEffect(() => {
    if (pointSeq === undefined || !focusRegion || !box || pointSeq <= lastPointed) return;
    lastPointed = pointSeq;
    if (reduced) {
      setPointing(null);
      setDrawSeq(pointSeq);
      onPointRef.current?.();
      return;
    }
    setPointing({ seq: pointSeq, stage: 'turning' });
  }, [pointSeq, focusRegion, box, reduced]);
  const onFaced = useCallback((seq: number) => {
    setPointing((p) => (p && p.seq === seq ? { seq, stage: 'ring' } : p));
    setDrawSeq(seq);
  }, []);
  const onRingClosed = useCallback(() => {
    setPointing(null);
    onPointRef.current?.();
  }, []);
  // Between a new aim arriving and the effect starting the swing, the ring must not flash at its spot.
  const awaiting = !reduced && pointSeq !== undefined && focusRegion !== null && box !== null && pointSeq > lastPointed;
  const turning = pointing?.stage === 'turning' || awaiting;
  const face = useMemo(() => (pointing?.stage === 'turning' && focusPoint ? { seq: pointing.seq, point: focusPoint } : null), [pointing, focusPoint]);
  const focus = focusPoint && !turning ? { point: focusPoint, radius: 0.62 } : null;

  // ── Turntable ──
  const root = useRef<HTMLDivElement>(null);
  const visible = useOnScreen(root);
  const [dragging, setDragging] = useState(false);
  const resume = useRef<number | null>(null);
  const onDrag = useCallback((active: boolean) => {
    if (resume.current !== null) window.clearTimeout(resume.current);
    resume.current = null;
    if (active) setDragging(true);
    else resume.current = window.setTimeout(() => setDragging(false), RESUME_MS);
  }, []);
  useEffect(() => () => void (resume.current !== null && window.clearTimeout(resume.current)), []);
  const spin = turntableSpins({ playing: turntable, reduced, dragging, focused: focusRegion !== null, visible });
  const [resetSeq, setResetSeq] = useState(0);

  return (
    <div className="w-car" ref={root} role="img" aria-label={t.workshop.car.label(vehicleName(vehicle))} title={t.workshop.car.reset} onDoubleClick={() => setResetSeq((n) => n + 1)}>
      {/* offsetSize: measure the layout box, not the transformed one — mounted mid section-switch, the plane
          is still scaled by its z-transition and the canvas would keep that smaller size, off-centre. */}
      <Canvas dpr={[1, 1.75]} camera={{ position: CAMERA, fov: 28 }} gl={{ antialias: true, alpha: true }} resize={{ offsetSize: true }}>
        <FitCamera box={box} points={points} />
        <CameraRig spin={spin} face={face} onFaced={onFaced} resetSeq={resetSeq} reduced={reduced} onDrag={onDrag} />
        <Suspense fallback={null}>
          <MeshErrorBoundary key={url} fallback={<Failed onFail={() => setFailed(true)} />}>
            <EdgeModel
              key={url}
              url={url}
              angle={carEdgeAngle(url)}
              rotationY={spec.rotationY}
              fit={{ mode: 'floor', size: LENGTH, alignLength: true }}
              ink={ink}
              accent={blue}
              focus={focus}
              focusDraw={drawSeq}
              reduced={reduced}
              onBox={onBox}
              onPoints={setPoints}
              {...vehicleLines(vehicle.vehicleType)}
            />
            {focusRegion && regionLabel && box && !turning && (
              <RegionRing key={`${focusRegion}|${drawSeq ?? ''}`} region={focusRegion} box={box} label={regionLabel} onClosed={pointing?.stage === 'ring' ? onRingClosed : undefined} />
            )}
          </MeshErrorBoundary>
        </Suspense>
        <OrbitControls makeDefault enablePan={false} enableZoom={false} enableDamping={!reduced} minPolarAngle={0.9} maxPolarAngle={1.42} />
      </Canvas>
      {failed && <p className="w-car-failed">{t.workshop.car.unavailable}</p>}
    </div>
  );
}

export const CarStage = memo(CarStageImpl);
