import { useFrame } from '@react-three/fiber';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { Box3, BufferGeometry, Mesh, Vector3, type Group, type Material } from 'three';
import { DRACOLoader, GLTFLoader } from 'three-stdlib';
import type { MeshId } from '../meshes';
import { Carousel, type Entry } from './carousel';
import { HiddenLine } from './HiddenLine';
import { DRACO_PATH, prepareMesh } from './meshGeometries';
import { MESH_SPAN, meshUrl } from './MeshPart';

export type CarouselEntry = Entry<BufferGeometry[]>;

// Own loader, outside drei's useGLTF cache: a dropped carousel part must leave the GPU and memory.
let loader: GLTFLoader | null = null;
function gltfLoader(): GLTFLoader {
  if (!loader) {
    const draco = new DRACOLoader();
    draco.setDecoderPath(DRACO_PATH);
    loader = new GLTFLoader();
    loader.setDRACOLoader(draco);
  }
  return loader;
}

async function loadMesh(id: MeshId): Promise<BufferGeometry[]> {
  const { scene } = await gltfLoader().loadAsync(meshUrl(id));
  const size = new Box3().setFromObject(scene).getSize(new Vector3());
  const longest = Math.max(size.x, size.y, size.z);
  const { geometries } = prepareMesh(scene, longest > 0 ? MESH_SPAN / longest : 1, 'center');
  // prepareMesh baked clones: the decoded originals are never drawn.
  scene.traverse((o) => {
    if (!(o instanceof Mesh)) return;
    o.geometry.dispose();
    for (const m of ([] as Material[]).concat(o.material)) m.dispose();
  });
  return geometries;
}

const disposeMesh = (geometries: BufferGeometry[]) => geometries.forEach((g) => g.dispose());

// Seconds a part stays drawn between two sweeps.
export const CAROUSEL_HOLD_S = 2.4;

export function useCarousel(active: boolean) {
  const [carousel] = useState(() => new Carousel<BufferGeometry[]>({ load: loadMesh, dispose: disposeMesh }));
  const shown = useSyncExternalStore(
    (fn) => carousel.subscribe(fn),
    () => carousel.shown,
  );
  useEffect(() => {
    if (active) carousel.start();
    else carousel.stop();
  }, [active, carousel]);
  useEffect(() => () => carousel.release(), [carousel]);
  return { carousel, shown };
}

const SPIN = (2 * Math.PI) / 14;

export function CarouselPart({ entry, reducedMotion }: { entry: CarouselEntry; reducedMotion: boolean }) {
  const group = useRef<Group>(null);
  useFrame((_, delta) => {
    if (group.current && !reducedMotion) group.current.rotation.y += SPIN * Math.min(delta, 0.1);
  });
  return (
    <group ref={group}>
      {entry.item.map((g, i) => (
        <HiddenLine key={i} geometry={g} opacity={0.5} threshold={28} />
      ))}
    </group>
  );
}
