import { OrbitControls } from '@react-three/drei';
import { Canvas, useFrame, useThree } from '@react-three/fiber';
import { Suspense, useEffect, useRef, useState, type RefObject } from 'react';
import { Box3, Mesh, Vector3, type Group, type PerspectiveCamera } from 'three';
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib';
import type { Dimension, Family } from '../types';
import { BrakePart } from './BrakePart';
import { FOV, HOME, POLAR_MARGIN, RESET_S, fitDistance, lerpPose, orbitPosition, resetPose, type Pose, type Vec3 } from './camera';
import { FilterPart } from './FilterPart';
import { GenericPart } from './GenericPart';
import { MeshPart } from './MeshPart';
import { turntableStep } from './turntable';

interface Props {
  family: Exclude<Family, { kind: 'none' }>;
  dimensions: readonly Dimension[];
  ghost: boolean;
  reducedMotion: boolean;
  extent: number;
}

const FILL = 0.62;
const UP = new Vector3(0, 1, 0);

// Once the user takes the view, the turntable stays off for the rest of the visit, every part included.
let taken = false;

const familyKey = (f: Props['family']): string =>
  f.kind === 'brake' ? `brake-${f.axle}-${f.component}` : f.kind === 'filter' ? `filter-${f.filter}` : f.kind === 'mesh' ? `mesh-${f.mesh}` : f.kind;

// Orbit target = the drawn part's bounding-box centre (the paper fills HiddenLine tags `fit`), camera
// distance = the framing extent fitted to the drawing area's aspect. Measured on the first frame after
// a change, once the part's own frame hooks (the filter's scale) have run.
function Rig({ part, fitKey, extent, reset, reducedMotion }: { part: RefObject<Group>; fitKey: string; extent: number; reset: number; reducedMotion: boolean }) {
  const camera = useThree((s) => s.camera) as PerspectiveCamera;
  const size = useThree((s) => s.size);
  const controls = useThree((s) => s.controls) as OrbitControlsImpl | null;
  const home = useRef<{ target: Vec3; distance: number } | null>(null);
  const pending = useRef(true);
  const anim = useRef<{ from: Pose; to: Pose; t: number } | null>(null);

  useEffect(() => {
    pending.current = true;
  }, [fitKey, extent, size.width, size.height]);

  useEffect(() => {
    if (reset === 0 || !home.current || !controls) return;
    const from: Pose = { position: camera.position.toArray() as Vec3, target: controls.target.toArray() as Vec3 };
    anim.current = { from, to: resetPose(home.current.target, home.current.distance), t: reducedMotion ? 1 : 0 };
  }, [reset]);

  useFrame((_, delta) => {
    if (!controls || !part.current) return;
    if (pending.current) {
      pending.current = false;
      part.current.updateWorldMatrix(true, true);
      const box = new Box3();
      part.current.traverse((o) => {
        if (o instanceof Mesh && o.userData.fit) box.expandByObject(o);
      });
      const target: Vec3 = box.isEmpty() ? [0, 0, 0] : (box.getCenter(new Vector3()).toArray() as Vec3);
      const distance = fitDistance(extent / 2, FOV, size.width / Math.max(size.height, 1), FILL);
      home.current = { target, distance };
      const dir = taken ? camera.position.clone().sub(controls.target).normalize() : new Vector3(...orbitPosition([0, 0, 0], 1, HOME.azimuth, HOME.polar));
      controls.target.set(...target);
      camera.position.set(...target).addScaledVector(dir, distance);
      camera.near = distance / 50;
      camera.far = distance * 50;
      camera.updateProjectionMatrix();
      controls.update();
    }
    const a = anim.current;
    if (a) {
      a.t = Math.min(1, a.t + delta / RESET_S);
      const pose = lerpPose(a.from, a.to, a.t);
      camera.position.set(...pose.position);
      controls.target.set(...pose.target);
      controls.update();
      if (a.t >= 1) anim.current = null;
    }
  });
  return null;
}

function Turntable({ reducedMotion }: { reducedMotion: boolean }) {
  const camera = useThree((s) => s.camera);
  const controls = useThree((s) => s.controls) as OrbitControlsImpl | null;
  const stopped = useRef(taken);
  const offset = useRef(new Vector3());

  useEffect(() => {
    if (!controls) return;
    const take = () => {
      taken = true;
      stopped.current = true;
    };
    controls.addEventListener('start', take);
    return () => controls.removeEventListener('start', take);
  }, [controls]);

  useFrame((_, delta) => {
    const step = turntableStep(delta, { reducedMotion, stopped: stopped.current });
    if (step === 0 || !controls) return;
    offset.current.copy(camera.position).sub(controls.target).applyAxisAngle(UP, step);
    camera.position.copy(controls.target).add(offset.current);
    controls.update();
  });
  return null;
}

export function Blueprint({ family, dimensions, ghost, reducedMotion, extent }: Props) {
  const part = useRef<Group>(null);
  const [reset, setReset] = useState(0);
  const fitKey = familyKey(family);
  return (
    <Canvas
      flat
      dpr={[1, 2]}
      gl={{ antialias: true, alpha: true }}
      camera={{ fov: FOV, near: 1, far: 20000, position: orbitPosition([0, 0, 0], 1000, HOME.azimuth, HOME.polar) as [number, number, number] }}
      onDoubleClick={() => setReset((n) => n + 1)}
      aria-hidden="true"
    >
      <Suspense fallback={null}>
        <group ref={part}>
          {family.kind === 'filter' && <FilterPart dimensions={dimensions} ghost={ghost} reducedMotion={reducedMotion} />}
          {family.kind === 'brake' && <BrakePart axle={family.axle} component={family.component} dimensions={dimensions} ghost={ghost} />}
          {family.kind === 'mesh' && <MeshPart mesh={family.mesh} ghost={ghost} />}
          {family.kind === 'generic' && <GenericPart ghost={ghost} />}
        </group>
        <Rig part={part} fitKey={fitKey} extent={extent} reset={reset} reducedMotion={reducedMotion} />
      </Suspense>
      <OrbitControls
        makeDefault
        enablePan
        screenSpacePanning
        zoomToCursor
        enableDamping={!reducedMotion}
        dampingFactor={0.08}
        rotateSpeed={0.6}
        zoomSpeed={0.7}
        minPolarAngle={POLAR_MARGIN}
        maxPolarAngle={Math.PI - POLAR_MARGIN}
      />
      <Turntable reducedMotion={reducedMotion} />
    </Canvas>
  );
}
