import { useGLTF } from '@react-three/drei';
import { useMemo } from 'react';
import { HiddenLine } from './HiddenLine';
import { prepareMesh } from './meshGeometries';

const BASE = import.meta.env.BASE_URL;
const URLS = { front: `${BASE}parts/front-brake-web.glb`, rear: `${BASE}parts/rear-brake-web.glb` } as const;
const DRACO = `${BASE}draco/`;
const TARGET_SIZE = 150;

interface Props {
  axle: 'front' | 'rear';
  ghost: boolean;
}

export function BrakePart({ axle, ghost }: Props) {
  const { scene } = useGLTF(URLS[axle], DRACO);
  const { geometries } = useMemo(() => {
    const raw = prepareMesh(scene, 1, 'center');
    const fit = TARGET_SIZE / Math.max(raw.size.x, raw.size.y, raw.size.z);
    return prepareMesh(scene, fit, 'center');
  }, [scene]);

  return (
    <group>
      {geometries.map((g, i) => (
        <HiddenLine key={i} geometry={g} ghost={ghost} threshold={30} />
      ))}
    </group>
  );
}
