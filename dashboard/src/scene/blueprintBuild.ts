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

/** The holo scan band: a cyan brightening that a driver (ScanRunner) sweeps along the car's x axis while
 * Le Noyau works. Uniforms are shared by the fill + edge materials of ONE blueprint instance; `halfLen`
 * is the object-space x half-extent so the driver ping-pongs `uScanX` across the whole body. */
export interface ScanControl {
  uniforms: {
    uScanX: { value: number }; // band centre, in object space along the swept axis
    uScanOn: { value: number }; // 0..1 intensity (eased on/off by the driver)
    uScanWidth: { value: number }; // half-width of the bright band
    uScanAxis: { value: number }; // 0 = sweep along x, 1 = sweep along z (the longer horizontal axis)
    uScanColor: { value: THREE.Color };
  };
  halfLen: number; // sweep amplitude: the swept axis' half-extent
}

export interface Blueprint {
  /** The cloned, re-centred, fill-overridden mesh group. Render via <primitive>. */
  clone: THREE.Object3D;
  /** Steel-blue edge LineSegments (geometry world-baked). Render via <primitive>. */
  edges: THREE.Group;
  /** The scan-band control (uniforms + extent) — animate via ScanRunner; inert at uScanOn=0. */
  scan: ScanControl;
  /** Free per-instance resources on unmount. Never the cached edge geometry or the drei-cached glb. */
  dispose: () => void;
}

// Inject a moving cyan band into the EDGE material (LineBasicMaterial, `basic` shader). The edge geometry
// is world-baked to car-local space (see below), so `position` IS the car frame — a band along the swept
// axis reads as a holo wave travelling the length of the body. NOT applied to the fill mesh: its geometry
// keeps per-mesh LOCAL coords, which don't line up with the baked edges (would glow uniformly). Inert at
// uScanOn≈0, so parc thumbs (never animated) look unchanged.
function patchScan(mat: THREE.Material, u: ScanControl['uniforms']): void {
  mat.onBeforeCompile = (shader) => {
    shader.uniforms.uScanX = u.uScanX;
    shader.uniforms.uScanOn = u.uScanOn;
    shader.uniforms.uScanWidth = u.uScanWidth;
    shader.uniforms.uScanAxis = u.uScanAxis;
    shader.uniforms.uScanColor = u.uScanColor;
    shader.vertexShader = `uniform float uScanAxis;\nvarying float vScanC;\n${shader.vertexShader}`.replace(
      '#include <begin_vertex>',
      '#include <begin_vertex>\n\tvScanC = mix(position.x, position.z, uScanAxis);',
    );
    shader.fragmentShader = `uniform float uScanX;\nuniform float uScanOn;\nuniform float uScanWidth;\nuniform vec3 uScanColor;\nvarying float vScanC;\n${shader.fragmentShader}`.replace(
      '#include <dithering_fragment>',
      '#include <dithering_fragment>\n\tfloat _band = smoothstep(uScanWidth, 0.0, abs(vScanC - uScanX)) * uScanOn;\n\tgl_FragColor.rgb = mix(gl_FragColor.rgb, uScanColor, _band * 0.85) + uScanColor * _band * 0.9;',
    );
  };
  mat.needsUpdate = true;
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
  // HDR-boosted edge color (>1 channels, toneMapped off): in the hero's half-float composer this is
  // what feeds the bloom pass — the neon-tube core. Parc thumbs have no composer and just clamp to a
  // slightly brighter line, which is fine.
  const edgeMat = new THREE.LineBasicMaterial({ color: new THREE.Color(EDGE).multiplyScalar(1.7), toneMapped: false });

  // Scan-band uniforms: sweep the LONGER horizontal axis (a glb may lie along x or z), band ≈ 15% of it.
  const extentX = box.max.x - box.min.x;
  const extentZ = box.max.z - box.min.z;
  const sweepZ = extentZ > extentX;
  const halfLen = Math.max(0.5, (sweepZ ? extentZ : extentX) / 2);
  const scan: ScanControl = {
    uniforms: {
      uScanX: { value: 0 },
      uScanOn: { value: 0 },
      uScanWidth: { value: Math.max(0.35, halfLen * 0.3) },
      uScanAxis: { value: sweepZ ? 1 : 0 },
      uScanColor: { value: new THREE.Color('#5fe0d9') }, // holo cyan (the agent's scanning light)
    },
    halfLen,
  };
  patchScan(edgeMat, scan.uniforms); // edges only — world-baked coords; the faint fill would misalign

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
  return { clone, edges, scan, dispose };
}
