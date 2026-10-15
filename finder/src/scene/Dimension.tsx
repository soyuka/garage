import { Html, Line } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useEffect, useMemo, useRef } from 'react';
import { BufferGeometry, Float32BufferAttribute, Matrix4, Vector3, type Mesh } from 'three';
import { arrowSide, type Vec3 } from './cotes';
import { DRAFT_BLUE, LINE_THIN } from './palette';

export type { Vec3 };

const v = (p: Vec3) => new Vector3(...p);
const arr = (p: Vector3): Vec3 => [p.x, p.y, p.z];

// Drawn over the part: dimensions never hide behind the paper fill.
export const OVERLAY = { depthTest: false, depthWrite: false, transparent: true } as const;
export const ORDER = 10;

export interface ArrowTip {
  tip: Vector3;
  back: Vector3;
}

const view = new Vector3();
const toLocal = new Matrix4();

// Filled arrowheads whose half-width opens across the line and across the view every frame, so a
// head on a line lying in the top face never shows edge-on as the part turns.
export function Arrowheads({ tips, length = 3.4, width = 1.1, opacity }: { tips: ArrowTip[]; length?: number; width?: number; opacity: number }) {
  const mesh = useRef<Mesh>(null);
  const geometry = useMemo(() => {
    const g = new BufferGeometry();
    g.setAttribute('position', new Float32BufferAttribute(new Float32Array(tips.length * 9), 3));
    return g;
  }, [tips.length]);
  useEffect(() => () => geometry.dispose(), [geometry]);

  useFrame(({ camera }) => {
    const m = mesh.current;
    if (!m) return;
    camera.getWorldDirection(view).negate();
    toLocal.copy(m.matrixWorld).invert();
    view.transformDirection(toLocal);
    const pos = geometry.getAttribute('position') as Float32BufferAttribute;
    tips.forEach(({ tip, back }, i) => {
      const [sx, sy, sz] = arrowSide([back.x, back.y, back.z], [view.x, view.y, view.z]);
      const bx = tip.x + back.x * length;
      const by = tip.y + back.y * length;
      const bz = tip.z + back.z * length;
      pos.setXYZ(i * 3, tip.x, tip.y, tip.z);
      pos.setXYZ(i * 3 + 1, bx + sx * width, by + sy * width, bz + sz * width);
      pos.setXYZ(i * 3 + 2, bx - sx * width, by - sy * width, bz - sz * width);
    });
    pos.needsUpdate = true;
  });

  return (
    <mesh ref={mesh} geometry={geometry} renderOrder={ORDER} frustumCulled={false}>
      <meshBasicMaterial color={DRAFT_BLUE} side={2} opacity={opacity} {...OVERLAY} />
    </mesh>
  );
}

interface DimensionProps {
  from: Vec3;
  to: Vec3;
  offset: Vec3;
  label: string;
  placement: 'above' | 'right';
  gap?: number;
  overshoot?: number;
  opacity?: number;
}

// A drafting dimension between two feature points: extension lines start `gap` mm off the part and
// run `overshoot` mm past an unbroken dimension line with filled arrowheads; the value sits beside
// the line as DOM text so it stays crisp at any zoom.
export function DimensionLine({ from, to, offset, label, placement, gap = 1.5, overshoot = 2.5, opacity = 1 }: DimensionProps) {
  const { segments, tips, mid } = useMemo(() => {
    const a = v(from);
    const b = v(to);
    const off = v(offset);
    const n = off.clone().normalize();
    const da = a.clone().add(off);
    const db = b.clone().add(off);
    const dir = db.clone().sub(da).normalize();
    return {
      segments: [
        a.clone().add(n.clone().multiplyScalar(gap)), da.clone().add(n.clone().multiplyScalar(overshoot)),
        b.clone().add(n.clone().multiplyScalar(gap)), db.clone().add(n.clone().multiplyScalar(overshoot)),
        da, db,
      ].map(arr),
      tips: [
        { tip: da, back: dir },
        { tip: db, back: dir.clone().negate() },
      ],
      mid: arr(da.clone().add(db).multiplyScalar(0.5)),
    };
  }, [from, to, offset, gap, overshoot]);

  return (
    <group>
      <Line points={segments} segments color={DRAFT_BLUE} lineWidth={LINE_THIN} opacity={opacity} renderOrder={ORDER} {...OVERLAY} />
      <Arrowheads tips={tips} opacity={opacity} />
      <Html position={mid} zIndexRange={[5, 0]}>
        <span className={`dim-label dim-label--${placement}`}>{label}</span>
      </Html>
    </group>
  );
}

interface LeaderProps {
  points: Vec3[];
  label: string;
  opacity?: number;
}

// A leader: arrowhead on the feature, polyline out to a horizontal shelf, note at the shelf's end.
export function Leader({ points, label, opacity = 1 }: LeaderProps) {
  const { segments, tips } = useMemo(() => {
    const p = points.map(v);
    const segs = p.slice(1).flatMap((q, i) => [arr(p[i]!), arr(q)]);
    const back = p[1]!.clone().sub(p[0]!).normalize();
    return { segments: segs, tips: [{ tip: p[0]!, back }] };
  }, [points]);
  const end = points[points.length - 1]!;
  return (
    <group>
      <Line points={segments} segments color={DRAFT_BLUE} lineWidth={LINE_THIN} opacity={opacity} renderOrder={ORDER} {...OVERLAY} />
      <Arrowheads tips={tips} opacity={opacity} length={3} width={0.9} />
      <Html position={end} zIndexRange={[5, 0]}>
        <span className="dim-label dim-label--note dim-label--left">{label}</span>
      </Html>
    </group>
  );
}

// Chain line (ISO 128 type 04: long dash, gap, short dash, gap) along one axis, as explicit segments
// so the pattern survives fat-line rendering.
export function chainSegments(a: Vec3, b: Vec3, pattern: readonly number[] = [12, 2.5, 2, 2.5]): Vec3[] {
  const start = v(a);
  const span = v(b).sub(start);
  const total = span.length();
  const dir = span.normalize();
  const out: Vec3[] = [];
  let t = 0;
  let i = 0;
  while (t < total) {
    const len = pattern[i % pattern.length]!;
    if (i % 2 === 0) out.push(arr(start.clone().add(dir.clone().multiplyScalar(t))), arr(start.clone().add(dir.clone().multiplyScalar(Math.min(t + len, total)))));
    t += len;
    i++;
  }
  return out;
}
