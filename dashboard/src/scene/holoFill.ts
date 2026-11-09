import * as THREE from 'three';
import type { GhostControl, ScanControl } from './blueprintBuild';
import { FILL_OPACITY } from './CarProxy';
import { BLUEPRINT_EDGE } from './signalColors';
import { DEPTH_FADE, FLICKER_DEPTH, FLICKER_HZ, FRESNEL_GAIN, FRESNEL_POWER, HOLO_SCAN_PERIOD, HOLO_SCAN_SPACING, SCAN_FLICKER } from './holo';

// The hologram fill: a ShaderMaterial with NO
// lighting input — a view-dependent fresnel in the blueprint blue, horizontal scanlines drifting upward
// (a loop period), a slight depth fade, and a per-scanline noise flicker gated by uFlicker (the arrival,
// driven by HoloRunner) and by the scan band while the sweep is on. The scan and ghost uniforms are the
// SAME objects the edge patch reads, so one driver write moves both surfaces.
//
// `uRise` (default 1) scales the whole alpha, fresnel included: the reveal's part rise scrubs it (a
// ShaderMaterial has no `.opacity` to write, and scaling `uFillOpacity` alone would leave the rim glowing
// at full gain from the first frame). The shell never touches it, so it stays at 1 for the car itself.
//
// Output is linear, no tonemapping/colorspace includes — exactly like HoloPodium's SHAFT_FRAG. Bloom safety
// comes from magnitude, not a flag: every channel stays ≤ ~1.06 and alpha ≤ FILL_OPACITY + FRESNEL_GAIN,
// composited over a near-black room → under the composer's luminanceThreshold (uRise ≤ 1 only lowers alpha).
//
// World-space coordinates on purpose: the edges are baked in the shared parent's frame (blueprintBuild),
// and the fill's LOCAL coords would misalign with them — vWorld is the frame the scan band lives in.

export interface HoloFillControl {
  uniforms: {
    uTime: { value: number };
    uFlicker: { value: number };
    uRise: { value: number };
  };
}

const VERT = /* glsl */ `
  varying vec3 vWorld;
  varying vec3 vNormalW;
  varying vec3 vViewDir;
  varying float vViewZ;
  void main() {
    vec4 world = modelMatrix * vec4(position, 1.0);
    vWorld = world.xyz;
    vNormalW = normalize(mat3(modelMatrix) * normal);
    vViewDir = normalize(cameraPosition - world.xyz);
    vec4 mv = modelViewMatrix * vec4(position, 1.0);
    vViewZ = -mv.z;
    gl_Position = projectionMatrix * mv;
  }
`;

const FRAG = /* glsl */ `
  #define FRESNEL_POWER ${FRESNEL_POWER.toFixed(4)}
  #define FRESNEL_GAIN ${FRESNEL_GAIN.toFixed(4)}
  #define HOLO_SCAN_SPACING ${HOLO_SCAN_SPACING.toFixed(4)}
  #define HOLO_SCAN_PERIOD ${HOLO_SCAN_PERIOD.toFixed(4)}
  #define FLICKER_HZ ${FLICKER_HZ.toFixed(4)}
  #define FLICKER_DEPTH ${FLICKER_DEPTH.toFixed(4)}
  #define SCAN_FLICKER ${SCAN_FLICKER.toFixed(4)}
  #define DEPTH_NEAR ${DEPTH_FADE.near.toFixed(4)}
  #define DEPTH_FAR ${DEPTH_FADE.far.toFixed(4)}
  #define DEPTH_FLOOR ${DEPTH_FADE.floor.toFixed(4)}
  uniform vec3 uPaint;
  uniform vec3 uEdge;
  uniform float uFillOpacity;
  uniform float uGhost;
  uniform float uScanX;
  uniform float uScanOn;
  uniform float uScanWidth;
  uniform float uScanAxis;
  uniform vec3 uScanColor;
  uniform float uTime;
  uniform float uFlicker;
  uniform float uRise;
  varying vec3 vWorld;
  varying vec3 vNormalW;
  varying vec3 vViewDir;
  varying float vViewZ;
  void main() {
    float fres = pow(1.0 - clamp(dot(normalize(vNormalW), normalize(vViewDir)), 0.0, 1.0), FRESNEL_POWER);
    float scanPhase = vWorld.y / HOLO_SCAN_SPACING - uTime / HOLO_SCAN_PERIOD;
    float scan = 0.5 + 0.5 * sin(scanPhase * 6.2831853);
    float scanC = mix(vWorld.x, vWorld.z, uScanAxis);
    float band = smoothstep(uScanWidth, 0.0, abs(scanC - uScanX)) * uScanOn;
    float tick = floor(uTime * FLICKER_HZ);
    float line = floor(vWorld.y / HOLO_SCAN_SPACING);
    float noise = fract(sin(dot(vec2(tick, line), vec2(12.9898, 78.233))) * 43758.5453);
    float drop = (uFlicker + band * SCAN_FLICKER) * FLICKER_DEPTH * step(0.5, noise);
    float depth = mix(1.0, DEPTH_FLOOR, smoothstep(DEPTH_NEAR, DEPTH_FAR, vViewZ));
    vec3 col = mix(uPaint, uEdge, fres * 0.8) + uEdge * scan * 0.06;
    col = mix(col, uScanColor, band * 0.6);
    float alpha = (uFillOpacity * mix(0.75, 1.0, scan) + fres * FRESNEL_GAIN) * depth * (1.0 - drop) * (1.0 - uGhost) * uRise;
    gl_FragColor = vec4(col, alpha);
  }
`;

export function buildHoloFill(
  paint: string,
  scan: ScanControl['uniforms'],
  ghost: GhostControl['uniforms'],
  edge: string = BLUEPRINT_EDGE,
): { material: THREE.ShaderMaterial; holo: HoloFillControl } {
  const holo: HoloFillControl = { uniforms: { uTime: { value: 0 }, uFlicker: { value: 0 }, uRise: { value: 1 } } };
  const material = new THREE.ShaderMaterial({
    transparent: true,
    depthWrite: false,
    side: THREE.FrontSide,
    uniforms: {
      uPaint: { value: new THREE.Color(paint) },
      uEdge: { value: new THREE.Color(edge) },
      uFillOpacity: { value: FILL_OPACITY },
      uGhost: ghost.uGhost,
      uScanX: scan.uScanX,
      uScanOn: scan.uScanOn,
      uScanWidth: scan.uScanWidth,
      uScanAxis: scan.uScanAxis,
      uScanColor: scan.uScanColor,
      uTime: holo.uniforms.uTime,
      uFlicker: holo.uniforms.uFlicker,
      uRise: holo.uniforms.uRise,
    },
    vertexShader: VERT,
    fragmentShader: FRAG,
  });
  return { material, holo };
}
