import * as THREE from 'three';
import { EDGE, FILL_OPACITY } from './CarProxy';

// Shared blueprint builder for any loaded glb scene — the faint paint-tinted volume under glowing
// steel-blue edges. Extracted from VehicleMesh so the hero leaf and the parc-grid thumb share ONE copy
// of the subtle bits: clone-before-mutate (never touch the drei cache), re-centre to origin+floor
// (theming/viewer.html:117-135), per-mesh EdgesGeometry (drei <Edges> is single-mesh; a glb is many).
//
// EdgesGeometry is O(triangles) — recomputing it on every mount made parc↔hero navigation lag. So when a
// `cacheKey` is given, the per-mesh edge geometries are computed ONCE and cached for the session: the
// world transform (from the deterministic recentre) is BAKED into the geometry, so the cached geometry is
// shared read-only across remounts AND across concurrent parc cards of the same vehicle. Cached edge
// geometries are never disposed (bounded: a handful of meshes); only the per-instance materials are.

const EDGES_CACHE = new Map<string, THREE.BufferGeometry[]>();

export interface Blueprint {
  /** The cloned, re-centred, fill-overridden mesh group. Render via <primitive>. */
  clone: THREE.Object3D;
  /** Steel-blue edge LineSegments (geometry world-baked). Render via <primitive>. */
  edges: THREE.Group;
  /** Free per-instance resources on unmount. Never the cached edge geometry or the drei-cached glb. */
  dispose: () => void;
}

export function buildBlueprint(
  scene: THREE.Object3D,
  { edgeAngle, paint, rotationY, cacheKey }: { edgeAngle: number; paint: string; rotationY?: number; cacheKey?: string },
): Blueprint {
  const clone = scene.clone(true);
  if (rotationY) clone.rotation.y = rotationY;

  // Re-centre to origin + floor (deterministic → cached edges line up across instances).
  clone.updateMatrixWorld(true);
  const box = new THREE.Box3().setFromObject(clone);
  const center = box.getCenter(new THREE.Vector3());
  clone.position.x -= center.x;
  clone.position.z -= center.z;
  clone.position.y -= box.min.y;
  clone.updateMatrixWorld(true);

  const fillMat = new THREE.MeshBasicMaterial({ color: paint, transparent: true, opacity: FILL_OPACITY, depthWrite: false });
  const edgeMat = new THREE.LineBasicMaterial({ color: EDGE });

  const key = cacheKey ? `${cacheKey}|${edgeAngle}` : undefined;
  const cached = key ? EDGES_CACHE.get(key) : undefined;
  const geoms: THREE.BufferGeometry[] = cached ?? [];

  const edges = new THREE.Group();
  let i = 0;
  clone.traverse((o) => {
    if ((o as THREE.Mesh).isMesh) {
      const mesh = o as THREE.Mesh;
      mesh.material = fillMat;
      let geom = cached?.[i];
      if (!geom) {
        // Bake the world transform into the edge geometry so it is position-independent and shareable.
        geom = new THREE.EdgesGeometry(mesh.geometry, edgeAngle);
        geom.applyMatrix4(mesh.matrixWorld);
        if (!cached) geoms.push(geom);
      }
      edges.add(new THREE.LineSegments(geom, edgeMat));
      i++;
    }
  });
  if (key && !cached) EDGES_CACHE.set(key, geoms);

  const dispose = () => {
    fillMat.dispose();
    edgeMat.dispose();
    // Only free edge geometries we built AND did not cache (uncached one-offs).
    if (!key) geoms.forEach((g) => g.dispose());
  };
  return { clone, edges, dispose };
}
