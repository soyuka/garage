import { useMemo, useEffect } from 'react';
import { useGLTF } from '@react-three/drei';
import * as THREE from 'three';
import { EDGE, FILL_OPACITY } from './CarProxy';
import type { MeshSpec } from './vehicleMesh';

// Stage D body leaf — the real optimized glb rendered AS A BLUEPRINT: a faint paint-tinted volume under
// glowing steel-blue edges (the same recipe CarProxy draws on primitives, so the look is identical
// whether the mesh loads or we fall back). useGLTF suspends while loading and THROWS on 404/decode —
// VehicleBody owns the Suspense fallback + the error boundary that catches it. Edges are built per-mesh
// (drei <Edges> wraps a single geometry; a glb scene has many), mirroring theming/viewer.html:117-135.

// Same-origin vendored DRACO decoder (no CDN); must be set before any useGLTF resolves.
useGLTF.setDecoderPath('/draco/');

const URLS = [
  '/mazda6-gh-web.glb',
  '/moto-default-web.glb',
  '/cv-default-web.glb',
  '/groundscare-default-web.glb',
  '/renault-espace-iv-web.glb',
];
URLS.forEach((u) => useGLTF.preload(u));

export function VehicleMesh({ spec, paint }: { spec: MeshSpec; paint: string }) {
  const { scene } = useGLTF(spec.url);

  const built = useMemo(() => {
    // Clone before mutating — the drei cache hands back a shared object.
    const clone = scene.clone(true);
    if (spec.rotationY) clone.rotation.y = spec.rotationY;

    // Re-centre to the turntable origin + floor (viewer.html:119-123), measured AFTER any yaw.
    clone.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(clone);
    const center = box.getCenter(new THREE.Vector3());
    clone.position.x -= center.x;
    clone.position.z -= center.z;
    clone.position.y -= box.min.y;
    clone.updateMatrixWorld(true);

    const fillMat = new THREE.MeshBasicMaterial({ color: paint, transparent: true, opacity: FILL_OPACITY, depthWrite: false });
    const edgeMat = new THREE.LineBasicMaterial({ color: EDGE });
    const edges = new THREE.Group();
    clone.traverse((o) => {
      if ((o as THREE.Mesh).isMesh) {
        const mesh = o as THREE.Mesh;
        mesh.material = fillMat;
        const seg = new THREE.LineSegments(new THREE.EdgesGeometry(mesh.geometry, spec.edgeAngle), edgeMat);
        seg.applyMatrix4(mesh.matrixWorld);
        edges.add(seg);
      }
    });
    return { clone, edges, fillMat, edgeMat };
  }, [scene, spec.edgeAngle, spec.rotationY, paint]);

  // Dispose only what we created (per-instance edge geometries + the two materials). The source glb
  // geometries/textures belong to the drei cache and are reused across mounts — never dispose them.
  useEffect(() => {
    const { edges, fillMat, edgeMat } = built;
    return () => {
      edges.children.forEach((c) => (c as THREE.LineSegments).geometry.dispose());
      fillMat.dispose();
      edgeMat.dispose();
    };
  }, [built]);

  return (
    <>
      <primitive object={built.clone} />
      <primitive object={built.edges} />
    </>
  );
}
