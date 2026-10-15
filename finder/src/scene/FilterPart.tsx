import { Html, useGLTF } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useMemo, useRef } from 'react';
import { BufferGeometry, Float32BufferAttribute, Group } from 'three';
import { NNBSP, formatNumber } from '../format';
import type { Dimension } from '../types';
import { DimensionLine } from './Dimension';
import { filterScale } from './filterScale';
import { HiddenLine } from './HiddenLine';
import { prepareMesh } from './meshGeometries';
import { DRAFT_BLUE } from './palette';

export const FILTER_URL = `${import.meta.env.BASE_URL}parts/oil-filter.glb`;
const METRES_TO_MM = 1000;

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

  const axis = useMemo(() => {
    const g = new BufferGeometry();
    g.setAttribute('position', new Float32BufferAttribute([0, -14, 0, 0, mesh.size.y + 14 / scale.y, 0], 3));
    return g;
  }, [mesh, scale.y]);

  const mm = (n: number) => formatNumber(Math.round(n));
  const thread = dimensions.find((d) => d.key === 'thread')?.value;

  return (
    <group position={[0, -H / 2, 0]}>
      <group ref={group}>
        {mesh.geometries.map((g, i) => (
          <HiddenLine key={i} geometry={g} ghost={ghost} threshold={24} />
        ))}
        <lineSegments geometry={axis} onUpdate={(l) => l.computeLineDistances()}>
          <lineDashedMaterial color={DRAFT_BLUE} dashSize={7} gapSize={2.5} transparent opacity={0.55} />
        </lineSegments>
      </group>
      {!ghost && (
        <>
          <DimensionLine
            from={[-R, H + 12, 0]}
            to={[R, H + 12, 0]}
            extension={[0, 8, 0]}
            label={scale.diameter !== null ? `Ø${NNBSP}${formatNumber(scale.diameter)}` : `Ø${NNBSP}≈${NNBSP}${mm(R * 2)}`}
          />
          <DimensionLine
            from={[R + 14, 0, 0]}
            to={[R + 14, H, 0]}
            extension={[9, 0, 0]}
            label={scale.height !== null ? formatNumber(scale.height) : `≈${NNBSP}${mm(H)}`}
          />
          {thread && (
            <Html position={[0, -10, 0]} center zIndexRange={[5, 0]} className="dim-label dim-label--note">
              {thread}
            </Html>
          )}
        </>
      )}
    </group>
  );
}

useGLTF.preload(FILTER_URL);
