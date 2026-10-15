import { OrbitControls } from '@react-three/drei';
import { Canvas, useFrame, useThree } from '@react-three/fiber';
import { Suspense, useEffect, useRef } from 'react';
import { Vector3, type OrthographicCamera } from 'three';
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib';
import type { Dimension, Family } from '../types';
import { BrakePart } from './BrakePart';
import { FilterPart } from './FilterPart';
import { GenericPart } from './GenericPart';
import { turntableStep } from './turntable';

export interface Focus {
  x: number;
  y: number;
}

interface Props {
  family: Exclude<Family, { kind: 'none' }>;
  dimensions: readonly Dimension[];
  ghost: boolean;
  reducedMotion: boolean;
  focus: Focus | null;
  extent: number;
}

const DISTANCE = 600;
const START_AZIMUTH = 0.62;
const START_POLAR = 1.12;
const POLAR_MARGIN = 0.06;
const UP = new Vector3(0, 1, 0);

// Once the user takes the view, the turntable stays off for the rest of the visit, every part included.
let taken = false;

function Fit({ focus, extent }: { focus: Focus | null; extent: number }) {
  const { camera, size, invalidate } = useThree();
  useEffect(() => {
    const cam = camera as OrthographicCamera;
    const free = focus ? Math.min(focus.x * 2, size.height) : Math.min(size.width, size.height);
    cam.zoom = (free * (focus ? 0.8 : 0.66)) / extent;
    if (focus) cam.setViewOffset(size.width, size.height, size.width / 2 - focus.x, size.height / 2 - focus.y, size.width, size.height);
    else cam.clearViewOffset();
    cam.updateProjectionMatrix();
    invalidate();
  }, [camera, size, focus, extent, invalidate]);
  return null;
}

function Turntable({ reducedMotion }: { reducedMotion: boolean }) {
  const camera = useThree((s) => s.camera);
  const controls = useThree((s) => s.controls) as OrbitControlsImpl | null;
  const stopped = useRef(taken);

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
    if (step === 0) return;
    camera.position.applyAxisAngle(UP, step);
    camera.lookAt(0, 0, 0);
    controls?.update();
  });
  return null;
}

export function Blueprint({ family, dimensions, ghost, reducedMotion, focus, extent }: Props) {
  return (
    <Canvas
      orthographic
      flat
      dpr={[1, 2]}
      gl={{ antialias: true, alpha: true }}
      camera={{
        position: [DISTANCE * Math.sin(START_POLAR) * Math.sin(START_AZIMUTH), DISTANCE * Math.cos(START_POLAR), DISTANCE * Math.sin(START_POLAR) * Math.cos(START_AZIMUTH)],
        near: 1,
        far: 3000,
        zoom: 3,
      }}
      aria-hidden="true"
    >
      <Fit focus={focus} extent={extent} />
      <Suspense fallback={null}>
        {family.kind === 'filter' && <FilterPart dimensions={dimensions} ghost={ghost} reducedMotion={reducedMotion} />}
        {family.kind === 'brake' && <BrakePart axle={family.axle} component={family.component} dimensions={dimensions} ghost={ghost} />}
        {family.kind === 'generic' && <GenericPart ghost={ghost} />}
      </Suspense>
      <OrbitControls
        makeDefault
        enablePan={false}
        enableDamping={!reducedMotion}
        dampingFactor={0.08}
        rotateSpeed={0.45}
        zoomSpeed={0.6}
        minZoom={0.5}
        maxZoom={20}
        minPolarAngle={POLAR_MARGIN}
        maxPolarAngle={Math.PI - POLAR_MARGIN}
        target={[0, 0, 0]}
      />
      <Turntable reducedMotion={reducedMotion} />
    </Canvas>
  );
}
