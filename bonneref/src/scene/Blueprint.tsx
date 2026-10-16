import { OrbitControls } from '@react-three/drei';
import { Canvas, useFrame, useThree } from '@react-three/fiber';
import { useEffect, useRef, useState, type MutableRefObject } from 'react';
import { Box3, Mesh, Vector3, type Group, type PerspectiveCamera } from 'three';
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib';
import type { Dimension, Family } from '../types';
import { BrakePart } from './BrakePart';
import type { Carousel } from './carousel';
import { CAROUSEL_HOLD_S, CarouselPart, useCarousel, type CarouselEntry } from './CarouselPart';
import { FOV, HOME, POLAR_MARGIN, fitDistance, orbitPosition } from './camera';
import { FilterPart } from './FilterPart';
import { MeshPart } from './MeshPart';
import { SweepLayers } from './SweepLayers';
import type { Layer } from './sweep';
import { turntableStep } from './turntable';

type DrawnFamily = Exclude<Family, { kind: 'none' } | { kind: 'photo' }>;

interface Props {
  // null while searching for a part that has no mesh (photo or « ? »): only the carousel draws.
  family: DrawnFamily | null;
  dimensions: readonly Dimension[];
  ghost: boolean;
  reducedMotion: boolean;
  extent: number;
  // A search is running: random small parts turn and redraw in turn until the result hands off.
  searching?: boolean;
  // false: a display (home example) that turns on its own and never takes the pointer or the page scroll.
  interactive?: boolean;
}

type Drawn = { kind: 'carousel'; entry: CarouselEntry } | { kind: 'part'; family: DrawnFamily; dimensions: readonly Dimension[]; ghost: boolean };

const FILL = 0.62;
const UP = new Vector3(0, 1, 0);
const CAROUSEL_EXTENT = 150;
const SWEEP_CAROUSEL_S = 1.1;
const SWEEP_PART_S = 0.62;
// Camera reframe rate (1/s): about 0.5 s to settle on a new part.
const REFRAME_RATE = 7;

// Once the user takes the view, the turntable stays off for the rest of the visit, every part included.
let taken = false;

const familyKey = (f: DrawnFamily): string =>
  f.kind === 'brake' ? `brake-${f.axle}-${f.component}` : f.kind === 'filter' ? `filter-${f.filter}` : `mesh-${f.mesh}`;

interface Fit {
  group: Group | null;
  version: number;
}

// Orbit target = the drawn part's bounding-box centre (the paper fills HiddenLine tags `fit`), camera
// distance = the framing extent fitted to the drawing area's aspect. Measured on the first frame after
// a change, once the part's own frame hooks (the filter's scale) have run; later parts are eased to
// along the current view direction, the first one is placed at the home pose.
function Rig({ fit, extent, display, reducedMotion }: { fit: MutableRefObject<Fit>; extent: number; display: boolean; reducedMotion: boolean }) {
  const camera = useThree((s) => s.camera) as PerspectiveCamera;
  const size = useThree((s) => s.size);
  const controls = useThree((s) => s.controls) as OrbitControlsImpl | null;
  const measured = useRef(-1);
  const placed = useRef(false);
  const goal = useRef<{ target: Vector3; distance: number } | null>(null);
  const resized = useRef(true);

  useEffect(() => {
    resized.current = true;
  }, [extent, size.width, size.height]);

  useFrame((_, delta) => {
    const group = fit.current.group;
    if (!controls || !group) return;
    if (measured.current !== fit.current.version || resized.current) {
      measured.current = fit.current.version;
      const snap = !placed.current || reducedMotion;
      resized.current = false;
      group.updateWorldMatrix(true, true);
      const box = new Box3();
      group.traverse((o) => {
        if (o instanceof Mesh && o.userData.fit) box.expandByObject(o);
      });
      const target = box.isEmpty() ? new Vector3() : box.getCenter(new Vector3());
      const distance = fitDistance(extent / 2, FOV, size.width / Math.max(size.height, 1), FILL);
      goal.current = { target, distance };
      if (snap) {
        placed.current = true;
        const dir = taken && !display ? camera.position.clone().sub(controls.target).normalize() : new Vector3(...orbitPosition([0, 0, 0], 1, HOME.azimuth, HOME.polar));
        controls.target.copy(target);
        camera.position.copy(target).addScaledVector(dir, distance);
        camera.near = distance / 50;
        camera.far = distance * 50;
        camera.updateProjectionMatrix();
        controls.update();
        goal.current = null;
        return;
      }
    }
    const g = goal.current;
    if (!g) return;
    const k = 1 - Math.exp(-REFRAME_RATE * Math.min(delta, 0.1));
    const offset = camera.position.clone().sub(controls.target);
    const distance = offset.length() + (g.distance - offset.length()) * k;
    controls.target.lerp(g.target, k);
    camera.position.copy(controls.target).addScaledVector(offset.normalize(), distance);
    camera.near = distance / 50;
    camera.far = distance * 50;
    camera.updateProjectionMatrix();
    controls.update();
    if (Math.abs(g.distance - distance) < g.distance * 1e-3 && controls.target.distanceTo(g.target) < g.distance * 1e-3) goal.current = null;
  });
  return null;
}
function Turntable({ reducedMotion, display }: { reducedMotion: boolean; display: boolean }) {
  const camera = useThree((s) => s.camera);
  const controls = useThree((s) => s.controls) as OrbitControlsImpl | null;
  const stopped = useRef(taken && !display);
  const offset = useRef(new Vector3());

  useEffect(() => {
    if (!controls || display) return;
    const take = () => {
      taken = true;
      stopped.current = true;
    };
    controls.addEventListener('start', take);
    return () => controls.removeEventListener('start', take);
  }, [controls, display]);

  useFrame((_, delta) => {
    const step = turntableStep(delta, { reducedMotion, stopped: stopped.current });
    if (step === 0 || !controls) return;
    offset.current.copy(camera.position).sub(controls.target).applyAxisAngle(UP, step);
    camera.position.copy(controls.target).add(offset.current);
    controls.update();
  });
  return null;
}

export function Blueprint({ family, dimensions, ghost, reducedMotion, extent, searching = false, interactive = true }: Props) {
  const fit = useRef<Fit>({ group: null, version: 0 });
  const pen = useRef<HTMLDivElement>(null);
  const { carousel, shown } = useCarousel(searching);
  const [hold, setHold] = useState(0);

  const layer: Layer<Drawn> | null = searching
    ? shown
      ? { key: `carousel-${shown.id}`, item: { kind: 'carousel', entry: shown } }
      : null
    : family
      ? { key: familyKey(family), item: { kind: 'part', family, dimensions, ghost } }
      : null;
  const shownExtent = layer?.item.kind === 'part' ? extent : CAROUSEL_EXTENT;

  return (
    <>
      <Canvas
        flat
        dpr={[1, 2]}
        gl={{ antialias: true, alpha: true }}
        onCreated={({ gl }) => {
          gl.localClippingEnabled = true;
        }}
        camera={{ fov: FOV, near: 1, far: 20000, position: orbitPosition([0, 0, 0], 1000, HOME.azimuth, HOME.polar) as [number, number, number] }}
        aria-hidden="true"
      >
        <SweepLayers<Drawn>
          layer={layer}
          pen={pen}
          duration={(l) => (reducedMotion ? 0 : l.item.kind === 'carousel' ? SWEEP_CAROUSEL_S : SWEEP_PART_S)}
          render={(item) =>
            item.kind === 'carousel' ? (
              <CarouselPart entry={item.entry} reducedMotion={reducedMotion} />
            ) : item.family.kind === 'filter' ? (
              <FilterPart dimensions={item.dimensions} ghost={item.ghost} reducedMotion={reducedMotion} />
            ) : item.family.kind === 'brake' ? (
              <BrakePart axle={item.family.axle} component={item.family.component} dimensions={item.dimensions} ghost={item.ghost} />
            ) : (
              <MeshPart mesh={item.family.mesh} ghost={item.ghost} />
            )
          }
          onDropped={(l) => {
            if (l.item.kind === 'carousel') carousel.drop(l.item.entry.id);
          }}
          onSettled={(l) => {
            if (l.item.kind === 'carousel') setHold((n) => n + 1);
          }}
          onShown={(group) => {
            fit.current = { group, version: fit.current.version + 1 };
          }}
        />
        <Rig fit={fit} extent={shownExtent} display={!interactive} reducedMotion={reducedMotion} />
        {searching && !reducedMotion && hold > 0 && <Advance key={hold} carousel={carousel} />}
        <OrbitControls
          makeDefault
          enabled={interactive}
          enablePan={false}
          enableZoom={false}
          enableDamping={!reducedMotion}
          dampingFactor={0.08}
          rotateSpeed={0.6}
          minPolarAngle={POLAR_MARGIN}
          maxPolarAngle={Math.PI - POLAR_MARGIN}
        />
        <Turntable reducedMotion={reducedMotion} display={!interactive} />
      </Canvas>
      <div ref={pen} className="sweep-pen" aria-hidden="true" />
    </>
  );
}

// Swaps the carousel's preloaded part in once the current one has been drawn for the hold time.
function Advance({ carousel }: { carousel: Carousel<CarouselEntry['item']> }) {
  const waited = useRef(0);
  useFrame((_, delta) => {
    waited.current += Math.min(delta, 0.1);
    if (waited.current >= CAROUSEL_HOLD_S && carousel.advance()) waited.current = -Infinity;
  });
  return null;
}
