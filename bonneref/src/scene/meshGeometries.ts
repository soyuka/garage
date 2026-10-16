import { Box3, BufferGeometry, Mesh, Object3D, Vector3 } from 'three';

export const DRACO_PATH = `${import.meta.env.BASE_URL}draco/`;

export interface PreparedMesh {
  geometries: BufferGeometry[];
  size: Vector3;
}

// Bakes every mesh of a glTF scene into world space, then rescales and recentres the whole set:
// `scale` multiplies glTF metres into scene units, `anchor` says where the origin lands.
export function prepareMesh(scene: Object3D, scale: number, anchor: 'center' | 'base'): PreparedMesh {
  scene.updateMatrixWorld(true);
  const geometries: BufferGeometry[] = [];
  scene.traverse((o) => {
    if (o instanceof Mesh && o.geometry instanceof BufferGeometry) geometries.push(o.geometry.clone().applyMatrix4(o.matrixWorld));
  });
  const box = new Box3();
  for (const g of geometries) {
    g.computeBoundingBox();
    if (g.boundingBox) box.union(g.boundingBox);
  }
  const center = box.getCenter(new Vector3());
  const offset = new Vector3(-center.x, anchor === 'base' ? -box.min.y : -center.y, -center.z);
  for (const g of geometries) {
    g.translate(offset.x, offset.y, offset.z);
    g.scale(scale, scale, scale);
    if (!g.getAttribute('normal')) g.computeVertexNormals();
  }
  return { geometries, size: box.getSize(new Vector3()).multiplyScalar(scale) };
}
