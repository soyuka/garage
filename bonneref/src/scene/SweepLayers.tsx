import { useFrame, useThree } from '@react-three/fiber';
import { Suspense, useEffect, useLayoutEffect, useReducer, useRef, type ReactNode, type RefObject } from 'react';
import { Box3, Material, Mesh, Plane, Vector3, type Group, type Object3D, type PerspectiveCamera } from 'three';
import { EMPTY, markReady, present, step, sweepEase, type Layer, type SweepState } from './sweep';

interface Props<T> {
  layer: Layer<T> | null;
  // Seconds the pen takes to climb the drawing for the incoming layer.
  duration: (layer: Layer<T>) => number;
  render: (item: T) => ReactNode;
  onDropped: (layer: Layer<T>) => void;
  // The pen reached the top: the incoming layer is fully drawn.
  onSettled: (layer: Layer<T>) => void;
  // The incoming layer is mounted: the camera reframes on it while the pen climbs.
  onShown: (group: Group) => void;
  pen: RefObject<HTMLDivElement>;
}

const KEEP_ALL = new Plane(new Vector3(0, 1, 0), 1e9);
const KEEP_NONE = KEEP_ALL.clone().negate();
const right = new Vector3();
const up = new Vector3();
const forward = new Vector3();
const dir = new Vector3();
const normal = new Vector3();
const eye = new Vector3();
const corner = new Vector3();

function setClip(root: Object3D, planes: Plane[]) {
  root.traverse((o) => {
    const m = (o as Mesh).material as Material | Material[] | undefined;
    if (!m) return;
    for (const x of Array.isArray(m) ? m : [m]) if (x.clippingPlanes !== planes) x.clippingPlanes = planes;
  });
}

const scratch = new Box3();
function worldBox(root: Object3D): Box3 | null {
  scratch.makeEmpty();
  root.traverse((o) => {
    if (o instanceof Mesh && o.userData.fit) scratch.expandByObject(o);
  });
  return scratch.isEmpty() ? null : scratch;
}

function Ready({ onReady }: { onReady: () => void }) {
  useEffect(onReady, []);
  return null;
}

// The plotter sweep (sweep.ts): the incoming layer is clipped to below a screen-horizontal plane through
// the eye, the outgoing one to above it, and a drafting-blue pen line rides the cut.
export function SweepLayers<T>({ layer, duration, render, onDropped, onSettled, onShown, pen }: Props<T>) {
  const camera = useThree((s) => s.camera) as PerspectiveCamera;
  const size = useThree((s) => s.size);
  const state = useRef<SweepState<T>>(EMPTY);
  const [, rerender] = useReducer((n: number) => n + 1, 0);
  const groups = useRef(new Map<string, Group>());
  const planeIn = useRef<Plane[]>([KEEP_ALL.clone()]);
  const planeOut = useRef<Plane[]>([KEEP_ALL.clone()]);
  const sweeping = useRef(false);

  useLayoutEffect(() => {
    if (!layer) return;
    const before = state.current;
    const { state: next, dropped } = present(before, layer);
    state.current = next;
    dropped.forEach(onDropped);
    if (next.shown !== before.shown || next.outgoing !== before.outgoing) rerender();
  }, [layer]);

  const ready = (key: string) => () => {
    state.current = markReady(state.current, key);
    const g = groups.current.get(key);
    if (g) onShown(g);
  };

  useFrame((_, delta) => {
    const s = state.current;
    const shownKey = s.shown?.key;
    const { state: next, dropped } = step(s, Math.min(delta, 0.1), s.shown ? duration(s.shown) : 0);
    state.current = next;
    if (s.progress < 1 && next.progress >= 1 && next.shown) onSettled(next.shown);
    if (dropped.length) {
      dropped.forEach(onDropped);
      rerender();
    }
    const live = next.ready && next.progress < 1;
    const el = pen.current;
    for (const [key, g] of groups.current) setClip(g, key === shownKey ? planeIn.current : planeOut.current);

    if (!live) {
      planeIn.current[0]!.copy(next.ready ? KEEP_ALL : KEEP_NONE);
      // A dropped layer can outlive its last frame until React unmounts it: keep it hidden meanwhile.
      planeOut.current[0]!.copy(next.ready ? KEEP_NONE : KEEP_ALL);
      if (sweeping.current && el) {
        el.style.opacity = '0';
        el.parentElement?.removeAttribute('data-sweeping');
      }
      sweeping.current = false;
      return;
    }

    // Screen extent of both layers: their world boxes projected, so the pen spans the parts, not the sheet.
    camera.updateMatrixWorld();
    camera.matrixWorld.extractBasis(right, up, forward);
    forward.negate();
    eye.setFromMatrixPosition(camera.matrixWorld);
    const tanHalf = Math.tan((camera.fov * Math.PI) / 360);
    let lo = Infinity;
    let hi = -Infinity;
    let left = Infinity;
    let rightmost = -Infinity;
    for (const g of groups.current.values()) {
      const box = worldBox(g);
      if (!box) continue;
      for (let i = 0; i < 8; i++) {
        corner.set(i & 1 ? box.max.x : box.min.x, i & 2 ? box.max.y : box.min.y, i & 4 ? box.max.z : box.min.z).project(camera);
        lo = Math.min(lo, corner.y);
        hi = Math.max(hi, corner.y);
        left = Math.min(left, corner.x);
        rightmost = Math.max(rightmost, corner.x);
      }
    }
    if (!Number.isFinite(lo)) {
      lo = -1;
      hi = 1;
      left = -0.4;
      rightmost = 0.4;
    }
    const t = sweepEase(next.progress);
    const ndcY = lo + (hi - lo) * t;
    dir.copy(forward).addScaledVector(up, ndcY * tanHalf);
    normal.crossVectors(right, dir).normalize();
    planeOut.current[0]!.set(normal, -normal.dot(eye));
    planeIn.current[0]!.set(normal.clone().negate(), normal.dot(eye));

    if (el) {
      const y = ((1 - ndcY) / 2) * size.height;
      const x0 = Math.max(0, ((left + 1) / 2) * size.width - 24);
      const x1 = Math.min(size.width, ((rightmost + 1) / 2) * size.width + 24);
      el.style.transform = `translate3d(${x0.toFixed(1)}px, ${y.toFixed(1)}px, 0) scaleX(${Math.max(1, x1 - x0).toFixed(1)})`;
      el.style.opacity = String(Math.min(1, Math.sin(Math.PI * next.progress) * 3));
      if (!sweeping.current) el.parentElement?.setAttribute('data-sweeping', '');
    }
    sweeping.current = true;
  });

  const layers = [state.current.outgoing, state.current.shown].filter((l): l is Layer<T> => l !== null);
  return (
    <>
      {layers.map((l) => (
        <group
          key={l.key}
          ref={(g) => {
            if (g) groups.current.set(l.key, g);
            else groups.current.delete(l.key);
          }}
        >
          <Suspense fallback={null}>
            {render(l.item)}
            <Ready onReady={ready(l.key)} />
          </Suspense>
        </group>
      ))}
    </>
  );
}
