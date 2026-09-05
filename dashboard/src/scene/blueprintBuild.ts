import * as THREE from 'three';
import { EDGE } from './CarProxy';
import { GHOST_EDGE_FLOOR } from './holo';
import { buildHoloFill, type HoloFillControl } from './holoFill';
import { HOLO_CYAN } from './signalColors';

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

/** The region PULSE: a stationary cyan flare centred at one region's slice along the SAME swept axis the
 * scan uses (so it works for a glb along x or z, and for the proxy). Driven by PulseRunner: `uPulseCenter`
 * is the region's object-space position on that axis, `uPulseOn` the eased one-shot envelope. Inert at
 * uPulseOn=0. Shares the scan's `halfLen` (same axis) so a region fraction maps to a position. */
export interface PulseControl {
  uniforms: {
    uPulseCenter: { value: number }; // flare centre on the swept axis, object space
    uPulseOn: { value: number }; // 0..1 one-shot amplitude (pulseEnvelope)
    uPulseWidth: { value: number }; // half-width of the flared slice
    uPulseColor: { value: THREE.Color };
  };
  halfLen: number;
}

/** The GHOST verb (C1): the shell steps out of the way of a reveal. ONE uniform, `uGhost`, is shared by
 * the edge patch (dims toward {@link GHOST_EDGE_FLOOR}) and the holo fill shader (body, fresnel and
 * scanlines fade to zero); `apply` writes that one uniform — a depth in [0,1], 0 = the untouched
 * blueprint. Inert at 0, so a parc thumb (which never calls it) is bit-for-bit what it was. */
export interface GhostControl {
  uniforms: {
    uGhost: { value: number };
  };
  apply: (depth: number) => void;
}

export interface Blueprint {
  /** The cloned, re-centred, fill-overridden mesh group. Render via <primitive>. */
  clone: THREE.Object3D;
  /** Steel-blue edge LineSegments (geometry world-baked). Render via <primitive>. */
  edges: THREE.Group;
  /** The scan-band control (uniforms + extent) — animate via ScanRunner; inert at uScanOn=0. */
  scan: ScanControl;
  /** The region-pulse control — animate via PulseRunner; inert at uPulseOn=0. */
  pulse: PulseControl;
  /** The ghost control — animate via GhostRunner; inert at depth 0. */
  ghost: GhostControl;
  /** The hologram fill's clock + arrival flicker — animate via HoloRunner; static at uTime 0, uFlicker 0. */
  holo: HoloFillControl;
  /** Free per-instance resources on unmount. Never the cached edge geometry or the drei-cached glb. */
  dispose: () => void;
}

// Inject the two cyan holo verbs into the EDGE material (LineBasicMaterial, `basic` shader): the moving
// scan WAVE and the localized region PULSE. The edge geometry is world-baked to car-local space (see
// below), so `position` IS the car frame — a band along the swept axis reads as a holo wave travelling
// the length of the body; a stationary band at a region's slice reads as that region flaring. Both share
// ONE varying (vScanC, the swept-axis coord) and ONE onBeforeCompile (a material has only one). NOT
// applied to the fill mesh: its geometry keeps per-mesh LOCAL coords, which don't line up with the baked
// edges (would glow uniformly). Inert at uScanOn≈0 AND uPulseOn≈0, so parc thumbs (never animated) look
// unchanged.
function patchHolo(mat: THREE.Material, s: ScanControl['uniforms'], p: PulseControl['uniforms'], g: GhostControl['uniforms']): void {
  mat.onBeforeCompile = (shader) => {
    shader.uniforms.uGhost = g.uGhost;
    shader.uniforms.uScanX = s.uScanX;
    shader.uniforms.uScanOn = s.uScanOn;
    shader.uniforms.uScanWidth = s.uScanWidth;
    shader.uniforms.uScanAxis = s.uScanAxis;
    shader.uniforms.uScanColor = s.uScanColor;
    shader.uniforms.uPulseCenter = p.uPulseCenter;
    shader.uniforms.uPulseOn = p.uPulseOn;
    shader.uniforms.uPulseWidth = p.uPulseWidth;
    shader.uniforms.uPulseColor = p.uPulseColor;
    shader.vertexShader = `uniform float uScanAxis;\nvarying float vScanC;\n${shader.vertexShader}`.replace(
      '#include <begin_vertex>',
      '#include <begin_vertex>\n\tvScanC = mix(position.x, position.z, uScanAxis);',
    );
    // The ghost multiplies LAST, after the two cyan verbs: a shell that has stepped aside must not keep
    // flaring at full brightness underneath the diagram that replaced it.
    shader.fragmentShader = `uniform float uScanX;\nuniform float uScanOn;\nuniform float uScanWidth;\nuniform vec3 uScanColor;\nuniform float uPulseCenter;\nuniform float uPulseOn;\nuniform float uPulseWidth;\nuniform vec3 uPulseColor;\nuniform float uGhost;\nvarying float vScanC;\n${shader.fragmentShader}`.replace(
      '#include <dithering_fragment>',
      `#include <dithering_fragment>\n\tfloat _band = smoothstep(uScanWidth, 0.0, abs(vScanC - uScanX)) * uScanOn;\n\tgl_FragColor.rgb = mix(gl_FragColor.rgb, uScanColor, _band * 0.85) + uScanColor * _band * 0.9;\n\tfloat _pulse = smoothstep(uPulseWidth, 0.0, abs(vScanC - uPulseCenter)) * uPulseOn;\n\tgl_FragColor.rgb = mix(gl_FragColor.rgb, uPulseColor, _pulse * 0.85) + uPulseColor * _pulse * 0.9;\n\tgl_FragColor.rgb *= mix(1.0, ${GHOST_EDGE_FLOOR.toFixed(3)}, uGhost);`,
    );
  };
  mat.needsUpdate = true;
}

// HDR line boost fed to the composer's bloom pass — the shell's default; a reveal part may lower it so
// its bloomed hue matches the primitive's flat hue (CHOREOGRAPHY § The reveal, "Glow is not hue").
export const EDGE_BOOST = 1.7;

export function buildBlueprint(
  scene: THREE.Object3D,
  {
    edgeAngle,
    paint,
    edge,
    edgeBoost,
    rotationX,
    rotationY,
    cacheKey,
  }: { edgeAngle: number; paint: string; edge?: string; edgeBoost?: number; rotationX?: number; rotationY?: number; cacheKey?: string },
): Blueprint {
  const clone = scene.clone(true);
  if (rotationX) clone.rotation.x = rotationX;
  if (rotationY) clone.rotation.y = rotationY;

  // Re-centre to origin + floor (deterministic → cached edges line up across instances).
  clone.updateMatrixWorld(true);
  const box = new THREE.Box3().setFromObject(clone);
  const center = box.getCenter(new THREE.Vector3());
  clone.position.x -= center.x;
  clone.position.z -= center.z;
  clone.position.y -= box.min.y;
  clone.updateMatrixWorld(true);

  // HDR-boosted edge color (>1 channels, toneMapped off): in the hero's half-float composer this is
  // what feeds the bloom pass — the neon-tube core. Parc thumbs have no composer and just clamp to a
  // slightly brighter line, which is fine.
  // `edge` — the line/fresnel/scanline hue; the shell never passes it (blueprint blue); a highlighted
  // reveal part passes OPERATOR_HEX (CHOREOGRAPHY § The reveal, 2026-09-04 row). `edgeBoost` — the HDR
  // line boost, default EDGE_BOOST; a part may lower it so its bloomed hue matches the primitive's flat
  // hue (row, "Glow is not hue").
  const edgeHue = edge ?? EDGE;
  const edgeMat = new THREE.LineBasicMaterial({ color: new THREE.Color(edgeHue).multiplyScalar(edgeBoost ?? EDGE_BOOST), toneMapped: false });

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
      uScanColor: { value: new THREE.Color(HOLO_CYAN) }, // the agent's scanning light
    },
    halfLen,
  };
  // Region pulse: a stationary flare on the SAME swept axis (shares halfLen). A touch wider than the scan
  // band so a region reads as a zone, not a hairline; still a slice, never the whole body.
  const pulse: PulseControl = {
    uniforms: {
      uPulseCenter: { value: 0 },
      uPulseOn: { value: 0 },
      uPulseWidth: { value: Math.max(0.5, halfLen * 0.32) },
      uPulseColor: { value: new THREE.Color(HOLO_CYAN) }, // the agent acting (same as the scan)
    },
    halfLen,
  };
  // The ghost: uGhost is shared by the edge patch and the holo fill shader; `apply` writes the one uniform.
  // Called from a useFrame driver, so it must not allocate.
  const ghost: GhostControl = {
    uniforms: { uGhost: { value: 0 } },
    apply: (depth: number) => {
      ghost.uniforms.uGhost.value = depth;
    },
  };
  patchHolo(edgeMat, scan.uniforms, pulse.uniforms, ghost.uniforms); // the pulse is edges-only (object-space slice)
  // The fill reads the scan + ghost in WORLD space (its own shader), so it lines up with the baked edges.
  const { material: fillMat, holo } = buildHoloFill(paint, scan.uniforms, ghost.uniforms, edgeHue);

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
  return { clone, edges, scan, pulse, ghost, holo, dispose };
}
