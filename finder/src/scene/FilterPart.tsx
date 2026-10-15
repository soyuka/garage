import { Line, useGLTF } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useMemo, useRef } from 'react';
import type { Group } from 'three';
import type { Dimension } from '../types';
import { filterAnnotations } from './annotations';
import { chainSegments, DimensionLine, Leader } from './Dimension';
import { filterScale } from './filterScale';
import { HiddenLine } from './HiddenLine';
import { prepareMesh } from './meshGeometries';
import { DRAFT_BLUE, LINE_THIN } from './palette';

export const FILTER_URL = `${import.meta.env.BASE_URL}parts/oil-filter.glb`;
const METRES_TO_MM = 1000;

interface Props {
  dimensions: readonly Dimension[];
  ghost: boolean;
  reducedMotion: boolean;
}

// Dimensions are fixed to the part and turn with it; only their DOM labels face the viewer.
export function FilterPart({ dimensions, ghost, reducedMotion }: Props) {
  const { scene } = useGLTF(FILTER_URL);
  const mesh = useMemo(() => prepareMesh(scene, METRES_TO_MM, 'base'), [scene]);
  const scale = useMemo(() => filterScale(mesh.size, dimensions), [mesh, dimensions]);
  const labels = useMemo(() => filterAnnotations(dimensions), [dimensions]);

  const group = useRef<Group>(null);
  const shown = useRef({ x: scale.x, y: scale.y });
  useFrame((_, delta) => {
    if (!group.current) return;
    const k = reducedMotion ? 1 : 1 - Math.exp(-delta * 9);
    shown.current.x += (scale.x - shown.current.x) * k;
    shown.current.y += (scale.y - shown.current.y) * k;
    group.current.scale.set(shown.current.x, shown.current.y, shown.current.x);
  });

  const H = mesh.size.y * scale.y;
  const R = (Math.max(mesh.size.x, mesh.size.z) * scale.x) / 2;
  const axis = useMemo(() => chainSegments([0, -9, 0], [0, H + 9, 0]), [H]);
  const foot = R * Math.SQRT1_2;

  return (
    <group position={[0, -H / 2, 0]}>
      <group ref={group}>
        {mesh.geometries.map((g, i) => (
          <HiddenLine key={i} geometry={g} ghost={ghost} threshold={24} rings />
        ))}
      </group>
      <Line points={axis} segments color={DRAFT_BLUE} lineWidth={LINE_THIN} transparent opacity={ghost ? 0.28 : 1} depthTest={false} renderOrder={9} />
      {!ghost && (
        <>
          {labels.diameter && <DimensionLine from={[-R, H, 0]} to={[R, H, 0]} offset={[0, R * 0.6 + 10, 0]} placement="above" label={labels.diameter} />}
          {labels.height && <DimensionLine from={[R, 0, 0]} to={[R, H, 0]} offset={[14, 0, 0]} placement="right" label={labels.height} />}
          {labels.thread && (
            <Leader
              points={[
                [-foot, 0, foot],
                [-R - 8, -16, foot],
                [-R - 26, -16, foot],
              ]}
              label={labels.thread}
            />
          )}
        </>
      )}
    </group>
  );
}

useGLTF.preload(FILTER_URL);
