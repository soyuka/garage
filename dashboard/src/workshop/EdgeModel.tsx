import { useEffect, useMemo, useRef, useSyncExternalStore } from 'react';
import { useFrame } from '@react-three/fiber';
import { useGLTF } from '@react-three/drei';
import * as THREE from 'three';
import { drawKey, drawProgress, fitTransform, focusMask, markDrawn, shouldDraw, type Vec3 } from './edgeDraw';
import { easeOutExpo } from './turntable';
import { chainSegments, dropInterior, dropInteriorTriangles, pruneStrokes, pruneWithin, scheduleStrokes, strokeBuffers, type Bounds, type HullPlane } from './strokes';
import { ConvexHull } from 'three/examples/jsm/math/ConvexHull.js';
import { ConvexGeometry } from 'three/examples/jsm/geometries/ConvexGeometry.js';
import type { BodyBox } from './regionPoint';

/** How long a mesh takes to draw itself on. Reduced motion skips it. */
export const DRAW_MS = 900;
/** How long the focused strokes take to redraw in the accent when the agent points. */
export const FOCUS_DRAW_MS = 400;
// Share of the whole draw each stroke takes; the rest is the stagger between first and last stroke.
const STROKE_SPAN = 0.42;
const FILL_OPACITY = 0.05;
// Below this many sharp edges a mesh is low-res enough that its softer creases help it read.
const SECONDARY_BUDGET = 24000;

export interface EdgeFit {
  mode: 'floor' | 'centre';
  size: number;
  /** Turn a mesh lying along z so its length runs on x (vehicles only). */
  alignLength?: boolean;
}

interface RawEdges {
  primary: Float32Array;
  secondary: Float32Array | null;
  fill: Float32Array;
  box: BodyBox;
}

const RAW = new Map<string, RawEdges>();

function transformInto(target: number[], attr: THREE.BufferAttribute | THREE.InterleavedBufferAttribute, matrix: THREE.Matrix4): void {
  const v = new THREE.Vector3();
  for (let i = 0; i < attr.count; i++) {
    v.fromBufferAttribute(attr, i).applyMatrix4(matrix);
    target.push(v.x, v.y, v.z);
  }
}

function extract(scene: THREE.Object3D, key: string, angle: number, secondaryAngle: number, rotationY: number | undefined, fit: EdgeFit): RawEdges {
  const cached = RAW.get(key);
  if (cached) return cached;
  const root = scene.clone(true);
  if (rotationY) root.rotation.y = rotationY;
  root.updateMatrixWorld(true);
  if (fit.alignLength) {
    const b = new THREE.Box3().setFromObject(root);
    if (b.max.z - b.min.z > b.max.x - b.min.x) {
      root.rotation.y += -Math.PI / 2;
      root.updateMatrixWorld(true);
    }
  }
  const primary: number[] = [];
  const secondary: number[] = [];
  const fill: number[] = [];
  root.traverse((o) => {
    const mesh = o as THREE.Mesh;
    if (!mesh.isMesh) return;
    const sharp = new THREE.EdgesGeometry(mesh.geometry, angle);
    transformInto(primary, sharp.getAttribute('position'), mesh.matrixWorld);
    sharp.dispose();
    const soft = new THREE.EdgesGeometry(mesh.geometry, secondaryAngle);
    transformInto(secondary, soft.getAttribute('position'), mesh.matrixWorld);
    soft.dispose();
    const flat = mesh.geometry.index ? mesh.geometry.toNonIndexed() : mesh.geometry;
    transformInto(fill, flat.getAttribute('position'), mesh.matrixWorld);
    if (flat !== mesh.geometry) flat.dispose();
  });

  const min: Vec3 = [Infinity, Infinity, Infinity];
  const max: Vec3 = [-Infinity, -Infinity, -Infinity];
  for (let i = 0; i < fill.length; i += 3) {
    for (let a = 0; a < 3; a++) {
      min[a] = Math.min(min[a], fill[i + a]);
      max[a] = Math.max(max[a], fill[i + a]);
    }
  }
  const { scale, offset } = fitTransform({ min, max }, fit);
  const place = (arr: number[]) => {
    const out = new Float32Array(arr.length);
    for (let i = 0; i < arr.length; i++) out[i] = (arr[i] + offset[i % 3]) * scale;
    return out;
  };
  const raw: RawEdges = {
    primary: place(primary),
    secondary: primary.length / 6 < SECONDARY_BUDGET ? place(secondary) : null,
    fill: place(fill),
    box: { length: (max[0] - min[0]) * scale, height: (max[1] - min[1]) * scale, width: (max[2] - min[2]) * scale },
  };
  RAW.set(key, raw);
  return raw;
}

interface FocusUniforms {
  reveal: { value: number };
  ink: { value: THREE.Color };
}

// Each stroke draws along its own length inside its slot of the overall progress: the vertex carries its
// arc fraction and its stroke's slot, the fragment is discarded past the stroke's reveal point. A focused
// segment shows its accent only up to the focus reveal along its stroke, ink beyond it.
function strokeMaterial(opacity: number, progress: { value: number }, focus: FocusUniforms): THREE.LineBasicMaterial {
  const material = new THREE.LineBasicMaterial({ vertexColors: true, transparent: true, opacity, depthWrite: false });
  material.onBeforeCompile = (shader) => {
    shader.uniforms.uProgress = progress;
    shader.uniforms.uFocusReveal = focus.reveal;
    shader.uniforms.uInk = focus.ink;
    shader.vertexShader = shader.vertexShader
      .replace('#include <common>', 'attribute float aAlong;\nattribute float aStart;\nattribute float aSpan;\nattribute float aFocus;\nvarying float vAlong;\nvarying float vStart;\nvarying float vSpan;\nvarying float vFocus;\n#include <common>')
      .replace('#include <begin_vertex>', '#include <begin_vertex>\nvAlong = aAlong;\nvStart = aStart;\nvSpan = aSpan;\nvFocus = aFocus;');
    shader.fragmentShader = shader.fragmentShader
      .replace('#include <common>', 'uniform float uProgress;\nuniform float uFocusReveal;\nuniform vec3 uInk;\nvarying float vAlong;\nvarying float vStart;\nvarying float vSpan;\nvarying float vFocus;\n#include <common>')
      .replace('void main() {', 'void main() {\n  float local = clamp((uProgress - vStart) / max(vSpan, 1e-4), 0.0, 1.0);\n  float reveal = local >= 1.0 ? 1.0 : (1.0 - exp2(-10.0 * local)) / (1.0 - exp2(-10.0));\n  if (vAlong > reveal) discard;')
      .replace('#include <color_fragment>', '#include <color_fragment>\n  if (vFocus > 0.5 && vAlong > uFocusReveal) diffuseColor.rgb = uInk;');
  };
  material.customProgramCacheKey = () => 'w-stroke';
  return material;
}

// Planes of the body's convex hull: a stroke deeper than INTERIOR_DEPTH (of the fit size) inside it (seats, dashboard,
// seen through window openings) is not drawn. Shell lines sit on the hull or just under it.
const INTERIOR_DEPTH = 0.05;

// Depth-only proxy of the cabin so nothing behind the windows draws (the thumbs carry no glass). It is
// the body's convex hull pulled in hardest across the width: the mirrors widen the hull, and the near-side
// doors must stay in front of it, while the seats sit well inside it.
const OCCLUDER_SCALE: [number, number, number] = [0.97, 0.95, 0.84];

function cabinOccluder(fill: Float32Array): THREE.Mesh {
  const points: THREE.Vector3[] = [];
  const stride = Math.max(1, Math.floor(fill.length / 3 / 6000));
  for (let i = 0; i < fill.length / 3; i += stride) points.push(new THREE.Vector3(fill[i * 3], fill[i * 3 + 1], fill[i * 3 + 2]));
  const geometry = new ConvexGeometry(points);
  geometry.computeBoundingBox();
  const centre = geometry.boundingBox!.getCenter(new THREE.Vector3());
  geometry.translate(-centre.x, -centre.y, -centre.z);
  geometry.scale(...OCCLUDER_SCALE);
  geometry.translate(centre.x, centre.y, centre.z);
  const mesh = new THREE.Mesh(geometry, new THREE.MeshBasicMaterial({ colorWrite: false, depthWrite: true }));
  mesh.renderOrder = -1;
  return mesh;
}

function hullPlanes(fill: Float32Array): HullPlane[] {
  const points: THREE.Vector3[] = [];
  // A hull of every vertex is wasteful; a stride keeps the silhouette at a fraction of the cost.
  const stride = Math.max(1, Math.floor(fill.length / 3 / 6000));
  for (let i = 0; i < fill.length / 3; i += stride) points.push(new THREE.Vector3(fill[i * 3], fill[i * 3 + 1], fill[i * 3 + 2]));
  const hull = new ConvexHull().setFromPoints(points);
  return hull.faces.map((f) => ({ n: [f.normal.x, f.normal.y, f.normal.z] as [number, number, number], c: -f.constant }));
}

interface StrokeCull {
  minStroke: number;
  hull: HullPlane[];
  interior: number;
  wheels: Bounds[];
  wheelStroke: number;
}

// Wheel spokes and tread chain into short strokes; the tyre and rim outlines are long ones.
const WHEEL_STROKE = 0.16;

function wheelBounds(box: BodyBox): Bounds[] {
  const half = 0.15 * box.length;
  return [-0.31, 0.31].map((f) => ({
    min: [f * box.length - half, 0, -box.width] as [number, number, number],
    max: [f * box.length + half, 0.45 * box.height, box.width] as [number, number, number],
  }));
}

function lines(positions: Float32Array, opacity: number, origin: Vec3 | null, progress: { value: number }, focus: FocusUniforms, cull: StrokeCull): THREE.LineSegments {
  const strokes = pruneWithin(dropInterior(pruneStrokes(chainSegments(positions), cull.minStroke), cull.hull, cull.interior), cull.wheels, cull.wheelStroke);
  const buffers = strokeBuffers(strokes, scheduleStrokes(strokes, origin, STROKE_SPAN));
  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.BufferAttribute(buffers.position, 3));
  geometry.setAttribute('color', new THREE.BufferAttribute(new Float32Array(buffers.position.length), 3));
  geometry.setAttribute('aAlong', new THREE.BufferAttribute(buffers.along, 1));
  geometry.setAttribute('aStart', new THREE.BufferAttribute(buffers.start, 1));
  geometry.setAttribute('aSpan', new THREE.BufferAttribute(buffers.span, 1));
  geometry.setAttribute('aFocus', new THREE.BufferAttribute(new Float32Array(buffers.along.length), 1));
  const segments = new THREE.LineSegments(geometry, strokeMaterial(opacity, progress, focus));
  segments.renderOrder = 1;
  return segments;
}

function paint(segments: THREE.LineSegments, ink: THREE.Color, accent: THREE.Color, mask: Uint8Array): void {
  const color = segments.geometry.getAttribute('color') as THREE.BufferAttribute;
  const focused = segments.geometry.getAttribute('aFocus') as THREE.BufferAttribute;
  const arr = color.array as Float32Array;
  const flags = focused.array as Float32Array;
  for (let s = 0; s < mask.length; s++) {
    const c = mask[s] ? accent : ink;
    for (let v = 0; v < 2; v++) {
      const o = (s * 2 + v) * 3;
      arr[o] = c.r;
      arr[o + 1] = c.g;
      arr[o + 2] = c.b;
      flags[s * 2 + v] = mask[s];
    }
  }
  color.needsUpdate = true;
  focused.needsUpdate = true;
}

/** Reads a colour token off :root and follows theme switches. */
export function useTokenColor(name: string): string {
  return useSyncExternalStore(
    (cb) => {
      const mo = new MutationObserver(cb);
      mo.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
      return () => mo.disconnect();
    },
    () => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#888888',
    () => '#888888',
  );
}

export interface EdgeModelProps {
  url: string;
  angle: number;
  secondaryAngle?: number;
  rotationY?: number;
  fit: EdgeFit;
  /** Line colour (a CSS colour string from a token). */
  ink: string;
  /** Colour of the edges inside `focus`. */
  accent?: string;
  /** Edges inside this sphere draw in `accent`; the draw-on also starts from it. */
  focus?: { point: Vec3; radius: number } | null;
  /** Draw the softer creases too (parts); vehicles read better on their sharp edges alone. */
  secondary?: boolean;
  /** Strokes shorter than this fraction of the fit size are dropped (wheel hatching, grilles). */
  minStroke?: number;
  /** Leave out what sits inside the body (seats through window openings), measured against its convex hull. */
  occludeInterior?: boolean;
  /** Keep only the tyre and rim outlines inside the wheels (vehicles). */
  wheels?: boolean;
  /** A sample of the mesh's own points, so the camera can fit its silhouette rather than its box. */
  onPoints?: (points: Float32Array) => void;
  delay?: number;
  reduced?: boolean;
  onBox?: (box: BodyBox) => void;
  /** Changing it redraws the focused strokes in the accent along their length (the agent pointing). */
  focusDraw?: number;
}

/** A mesh drawn as its edges only, drawing itself on the first time it appears in the session. */
export function EdgeModel({ url, angle, secondaryAngle = angle * 0.45, rotationY, fit, ink, accent, focus = null, secondary: withSecondary = true, minStroke = 0, occludeInterior = false, wheels = false, delay = 0, reduced = false, onBox, onPoints, focusDraw }: EdgeModelProps) {
  const { scene } = useGLTF(url);
  const raw = useMemo(() => extract(scene, `${url}|${angle}|${secondaryAngle}|${fit.mode}|${fit.size}`, angle, secondaryAngle, rotationY, fit), [scene, url, angle, secondaryAngle, rotationY, fit.mode, fit.size]);
  // The draw-on starts from wherever the focus was when the mesh arrived; later focus moves only recolour.
  const arrivalFocus = useRef(focus?.point ?? null);
  const origin = arrivalFocus.current;

  const sessionKey = drawKey(url, fit);
  const built = useMemo(() => {
    const instant = reduced || !shouldDraw(sessionKey);
    const progress = { value: instant ? 1 : 0 };
    const focusUniforms: FocusUniforms = { reveal: { value: 1 }, ink: { value: new THREE.Color() } };
    const cut = minStroke * fit.size;
    const hull = occludeInterior ? hullPlanes(raw.fill) : [];
    const cull: StrokeCull = {
      minStroke: cut,
      hull,
      interior: INTERIOR_DEPTH * fit.size,
      wheels: wheels ? wheelBounds(raw.box) : [],
      wheelStroke: WHEEL_STROKE * fit.size,
    };
    const primary = lines(raw.primary, 0.92, origin, progress, focusUniforms, cull);
    const secondary = withSecondary && raw.secondary ? lines(raw.secondary, 0.26, origin, progress, focusUniforms, cull) : null;
    const fillGeometry = new THREE.BufferGeometry();
    // The fill the culled interior strokes belonged to goes too, or the seats ghost through as grey.
    fillGeometry.setAttribute('position', new THREE.BufferAttribute(dropInteriorTriangles(raw.fill, hull, cull.interior), 3));
    // Writes depth so back edges hide behind the body; a whisper of colour gives it volume.
    const fillMaterial = new THREE.MeshBasicMaterial({ transparent: true, opacity: FILL_OPACITY, side: THREE.DoubleSide, polygonOffset: true, polygonOffsetFactor: 1, polygonOffsetUnits: 1 });
    const fill = new THREE.Mesh(fillGeometry, fillMaterial);
    fill.renderOrder = 0;
    const group = new THREE.Group();
    group.add(fill, primary);
    if (secondary) group.add(secondary);
    if (occludeInterior) group.add(cabinOccluder(raw.fill));
    fillMaterial.opacity = instant ? FILL_OPACITY : 0;
    return { group, primary, secondary, fill, progress, focus: focusUniforms, instant };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [raw]);

  useEffect(
    () => () =>
      built.group.traverse((o) => {
        const mesh = o as THREE.Mesh;
        if (!mesh.geometry) return;
        mesh.geometry.dispose();
        (mesh.material as THREE.Material).dispose();
      }),
    [built],
  );

  useEffect(() => onBox?.(raw.box), [raw, onBox]);
  // Every vertex, not a sample: a stride can skip the bumper's tip and leave the framing lopsided.
  useEffect(() => onPoints?.(raw.fill), [raw, onPoints]);

  const focusKey = focus ? `${focus.point.join(',')}|${focus.radius}` : 'none';
  useEffect(() => {
    const inkColor = new THREE.Color(ink);
    const accentColor = new THREE.Color(accent ?? ink);
    const primaryPos = built.primary.geometry.getAttribute('position').array as Float32Array;
    paint(built.primary, inkColor, accentColor, focusMask(primaryPos, focus));
    if (built.secondary) {
      const secondaryPos = built.secondary.geometry.getAttribute('position').array as Float32Array;
      paint(built.secondary, inkColor, accentColor, focusMask(secondaryPos, focus));
    }
    (built.fill.material as THREE.MeshBasicMaterial).color.set(ink);
    built.focus.ink.value.set(ink);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [built, ink, accent, focusKey]);

  const focusRun = useRef(FOCUS_DRAW_MS);
  const lastFocusDraw = useRef(focusDraw);
  useEffect(() => {
    if (focusDraw === lastFocusDraw.current) return;
    lastFocusDraw.current = focusDraw;
    focusRun.current = reduced ? FOCUS_DRAW_MS : 0;
    built.focus.reveal.value = reduced ? 1 : 0;
  }, [built, focusDraw, reduced]);

  const elapsed = useRef(0);
  const done = useRef(false);
  useEffect(() => {
    elapsed.current = 0;
    done.current = false;
  }, [built]);
  useFrame((_, delta) => {
    const dt = Math.min(delta, 0.05) * 1000;
    if (focusRun.current < FOCUS_DRAW_MS) {
      focusRun.current += dt;
      built.focus.reveal.value = easeOutExpo(focusRun.current / FOCUS_DRAW_MS);
    }
    if (done.current) return;
    elapsed.current += dt;
    const instant = built.instant;
    // Time runs linearly across the staggered strokes; each stroke eases out along its own length (shader).
    const linear = instant ? 1 : Math.min(1, Math.max(0, (elapsed.current - delay) / DRAW_MS));
    const p = drawProgress(elapsed.current, instant ? 0 : DRAW_MS, instant ? 0 : delay);
    built.progress.value = linear;
    // The body's faint fill comes up with the drawing, so a half-drawn mesh is not a full silhouette.
    (built.fill.material as THREE.MeshBasicMaterial).opacity = FILL_OPACITY * p;
    if (linear >= 1) {
      done.current = true;
      if (!instant) markDrawn(sessionKey);
    }
  });

  return <primitive object={built.group} />;
}
