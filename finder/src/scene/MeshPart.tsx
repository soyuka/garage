import { useGLTF } from '@react-three/drei';
import { useMemo } from 'react';
import { Box3, Vector3 } from 'three';
import { meshFile, type MeshId } from '../meshes';
import { HiddenLine } from './HiddenLine';
import { DRACO_PATH, prepareMesh } from './meshGeometries';

// Scene units the mesh's longest side is drawn at. These meshes carry no cote, so their size on the
// sheet is a framing choice, not a measurement.
export const MESH_SPAN = 120;

export const meshUrl = (id: MeshId): string => `${import.meta.env.BASE_URL}parts/${meshFile(id)}`;

export function MeshPart({ mesh, ghost }: { mesh: MeshId; ghost: boolean }) {
  return <GltfPart url={meshUrl(mesh)} ghost={ghost} />;
}

export function GltfPart({ url, ghost }: { url: string; ghost: boolean }) {
  const { scene } = useGLTF(url, DRACO_PATH);
  const { geometries } = useMemo(() => {
    const size = new Box3().setFromObject(scene).getSize(new Vector3());
    const longest = Math.max(size.x, size.y, size.z);
    return prepareMesh(scene, longest > 0 ? MESH_SPAN / longest : 1, 'center');
  }, [scene]);
  return (
    <group>
      {geometries.map((g, i) => (
        <HiddenLine key={i} geometry={g} ghost={ghost} threshold={28} />
      ))}
    </group>
  );
}
