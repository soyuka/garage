import { useGLTF } from '@react-three/drei';
import { useMemo } from 'react';
import type { BufferGeometry } from 'three';
import { measure } from '../dims';
import type { Dimension } from '../types';
import { brakeAnnotations, type BrakeComponent } from './annotations';
import { DimensionLine, Leader } from './Dimension';
import { HiddenLine } from './HiddenLine';
import { DRACO_PATH, prepareMesh } from './meshGeometries';

const BASE = import.meta.env.BASE_URL;
const URLS = { front: `${BASE}parts/front-brake-web.glb`, rear: `${BASE}parts/rear-brake-web.glb` } as const;
const METRES_TO_MM = 1000;
const RIM = 0.985;

interface Props {
  axle: 'front' | 'rear';
  component: BrakeComponent;
  dimensions: readonly Dimension[];
  ghost: boolean;
}

// Mean height of the vertices on the outer rim: where the disc itself sits along its axis (Y).
function rimHeight(geometries: BufferGeometry[], radius: number): number {
  let sum = 0;
  let n = 0;
  for (const g of geometries) {
    const p = g.getAttribute('position');
    for (let i = 0; i < p.count; i++) {
      if (Math.hypot(p.getX(i), p.getZ(i)) >= radius * RIM) {
        sum += p.getY(i);
        n++;
      }
    }
  }
  return n > 0 ? sum / n : 0;
}

// The disc axis is Y and its rim is the widest thing in X: the assembly is scaled so the disc reads at
// the catalogue diameter when there is one, at the mesh's own size otherwise (and then no cote is drawn).
export function BrakePart({ axle, component, dimensions, ghost }: Props) {
  const { scene } = useGLTF(URLS[axle], DRACO_PATH);
  const labels = useMemo(() => brakeAnnotations(dimensions, component), [dimensions, component]);
  const target = component === 'disc' ? measure(dimensions, 'outer')?.mid ?? null : null;
  const { geometries, size, R, rimY } = useMemo(() => {
    const raw = prepareMesh(scene, METRES_TO_MM, 'center');
    const k = target !== null && raw.size.x > 0 ? target / raw.size.x : 1;
    const mesh = k === 1 ? raw : prepareMesh(scene, METRES_TO_MM * k, 'center');
    const radius = mesh.size.x / 2;
    return { ...mesh, R: radius, rimY: rimHeight(mesh.geometries, radius) };
  }, [scene, target]);
  const top = size.y / 2;

  return (
    <group>
      {geometries.map((g, i) => (
        <HiddenLine key={i} geometry={g} ghost={ghost} threshold={30} />
      ))}
      {!ghost && labels.diameter && <DimensionLine from={[-R, rimY, 0]} to={[R, rimY, 0]} offset={[0, top - rimY + 18, 0]} placement="above" label={labels.diameter} />}
      {!ghost && labels.thickness && (
        <Leader
          points={[
            [R * Math.SQRT1_2, rimY, R * Math.SQRT1_2],
            [R * Math.SQRT1_2 + 22, rimY - 26, R * Math.SQRT1_2],
            [R * Math.SQRT1_2 + 46, rimY - 26, R * Math.SQRT1_2],
          ]}
          label={labels.thickness}
        />
      )}
    </group>
  );
}
