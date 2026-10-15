import { OrbitControls } from '@react-three/drei';
import { Canvas, useFrame, useThree } from '@react-three/fiber';
import { Suspense, useEffect, useRef } from 'react';
import type { OrthographicCamera } from 'three';
import type { Dimension, Family } from '../types';
import { BrakePart } from './BrakePart';
import { FilterPart } from './FilterPart';

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
const POLAR = 1.12;

function Framing({ focus, extent, reducedMotion, settleKey }: { focus: Focus | null; extent: number; reducedMotion: boolean; settleKey: string }) {
  const { camera, size, invalidate } = useThree();
  const settle = useRef(1);

  useEffect(() => {
    const cam = camera as OrthographicCamera;
    const free = focus ? Math.min(focus.x * 2, size.height) : Math.min(size.width, size.height);
    cam.zoom = (free * (focus ? 0.8 : 0.66)) / extent;
    if (focus) cam.setViewOffset(size.width, size.height, size.width / 2 - focus.x, size.height / 2 - focus.y, size.width, size.height);
    else cam.clearViewOffset();
    cam.updateProjectionMatrix();
    invalidate();
  }, [camera, size, focus, extent, invalidate]);

  useEffect(() => {
    settle.current = reducedMotion ? 1 : 0;
  }, [settleKey, reducedMotion]);

  useFrame((_, delta) => {
    if (settle.current >= 1) return;
    settle.current = Math.min(1, settle.current + delta / 1.4);
    const e = 1 - Math.pow(1 - settle.current, 3);
    const az = START_AZIMUTH + 0.32 * (1 - e);
    camera.position.set(DISTANCE * Math.sin(POLAR) * Math.sin(az), DISTANCE * Math.cos(POLAR), DISTANCE * Math.sin(POLAR) * Math.cos(az));
    camera.lookAt(0, 0, 0);
  });

  return null;
}

export function Blueprint({ family, dimensions, ghost, reducedMotion, focus, extent }: Props) {
  const settleKey = family.kind === 'brake' ? `brake-${family.axle}` : 'filter';
  return (
    <Canvas
      orthographic
      flat
      dpr={[1, 2]}
      gl={{ antialias: true, alpha: true }}
      camera={{
        position: [DISTANCE * Math.sin(POLAR) * Math.sin(START_AZIMUTH), DISTANCE * Math.cos(POLAR), DISTANCE * Math.sin(POLAR) * Math.cos(START_AZIMUTH)],
        near: 1,
        far: 3000,
        zoom: 3,
      }}
      aria-hidden="true"
    >
      <Framing focus={focus} extent={extent} reducedMotion={reducedMotion} settleKey={settleKey} />
      <Suspense fallback={null}>
        {family.kind === 'filter' ? (
          <FilterPart dimensions={dimensions} ghost={ghost} reducedMotion={reducedMotion} />
        ) : (
          <BrakePart axle={family.axle} ghost={ghost} />
        )}
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
        target={[0, 0, 0]}
      />
    </Canvas>
  );
}
