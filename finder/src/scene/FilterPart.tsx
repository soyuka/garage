import { Line, useGLTF } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useMemo, useRef, useState } from 'react';
import type { Group } from 'three';
import { threadOf } from '../dims';
import { NNBSP, formatNumber } from '../format';
import type { Dimension } from '../types';
import { chainSegments, DimensionLine, Leader } from './Dimension';
import { filterScale } from './filterScale';
import { HiddenLine } from './HiddenLine';
import { prepareMesh } from './meshGeometries';
import { DRAFT_BLUE, LINE_THIN } from './palette';

export const FILTER_URL = `${import.meta.env.BASE_URL}parts/oil-filter.glb`;
const METRES_TO_MM = 1000;
const CLEARANCE = 12;
const POLAR_STEP = 0.03;

interface Props {
  dimensions: readonly Dimension[];
  ghost: boolean;
  reducedMotion: boolean;
}

export function FilterPart({ dimensions, ghost, reducedMotion }: Props) {
  const { scene } = useGLTF(FILTER_URL);
  const mesh = useMemo(() => prepareMesh(scene, METRES_TO_MM, 'base'), [scene]);
  const scale = useMemo(() => filterScale(mesh.size, dimensions), [mesh, dimensions]);

  const group = useRef<Group>(null);
  const facing = useRef<Group>(null);
  const shown = useRef({ x: scale.x, y: scale.y });
  const [polar, setPolar] = useState(1.12);
  useFrame(({ camera }, delta) => {
    if (facing.current) facing.current.rotation.y = Math.atan2(camera.position.x, camera.position.z);
    const p = Math.acos(camera.position.y / camera.position.length());
    if (Math.abs(p - polar) > POLAR_STEP) setPolar(p);
    if (!group.current) return;
    const k = reducedMotion ? 1 : 1 - Math.exp(-delta * 9);
    shown.current.x += (scale.x - shown.current.x) * k;
    shown.current.y += (scale.y - shown.current.y) * k;
    group.current.scale.set(shown.current.x, shown.current.y, shown.current.x);
  });

  const H = mesh.size.y * scale.y;
  const R = (Math.max(mesh.size.x, mesh.size.z) * scale.x) / 2;
  // World rise above the top rim that clears its projected ellipse by CLEARANCE mm on screen.
  const theta = Math.min(Math.max(polar, 0.35), Math.PI / 2);
  const rise = R / Math.tan(theta) + CLEARANCE / Math.sin(theta);

  const mm = (n: number) => formatNumber(Math.round(n));
  const thread = threadOf(dimensions);
  const axis = useMemo(() => chainSegments([0, -9, 0], [0, H + 9, 0]), [H]);
  const foot = R * Math.SQRT1_2;

  return (
    <group position={[0, -H / 2, 0]}>
      <group ref={group}>
        {mesh.geometries.map((g, i) => (
          <HiddenLine key={i} geometry={g} ghost={ghost} threshold={24} rings />
        ))}
      </group>
      <group ref={facing}>
        <Line points={axis} segments color={DRAFT_BLUE} lineWidth={LINE_THIN} transparent opacity={ghost ? 0.28 : 1} depthTest={false} renderOrder={9} />
        {!ghost && (
          <>
            <DimensionLine
              from={[-R, H, 0]}
              to={[R, H, 0]}
              offset={[0, rise, 0]}
              placement="above"
              label={`Ø${NNBSP}${scale.diameter?.text ?? `≈${NNBSP}${mm(R * 2)}`}`}
            />
            <DimensionLine
              from={[R, 0, 0]}
              to={[R, H, 0]}
              offset={[14, 0, 0]}
              placement="right"
              label={scale.height?.text ?? `≈${NNBSP}${mm(H)}`}
            />
            {thread && (
              <Leader
                points={[
                  [-foot, 0, foot],
                  [-R - 8, -16, foot],
                  [-R - 26, -16, foot],
                ]}
                label={thread}
              />
            )}
          </>
        )}
      </group>
    </group>
  );
}

useGLTF.preload(FILTER_URL);
