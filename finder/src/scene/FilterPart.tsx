import { Html, Line, useGLTF } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useMemo, useRef, useState } from 'react';
import { Vector3, type BufferGeometry, type Group, type Material, type Mesh } from 'three';
import type { Dimension } from '../types';
import { filterAnnotations } from './annotations';
import { dampAngle, facingFace, filterCotes, rimBottom, rimTop, visibleLabels, type CoteKey, type Face, type FilterCotes, type Vec3 } from './cotes';
import { Arrowheads, chainSegments, ORDER, OVERLAY, type ArrowTip } from './Dimension';
import { filterScale } from './filterScale';
import { HiddenLine } from './HiddenLine';
import { DRACO_PATH, prepareMesh } from './meshGeometries';
import { DRAFT_BLUE, LINE_THIN } from './palette';

export const FILTER_URL = `${import.meta.env.BASE_URL}parts/oil-filter.glb`;
const METRES_TO_MM = 1000;
const SWITCH_S = 0.15;
const FOLLOW_RATE = 8;
const world = new Vector3();

interface Props {
  dimensions: readonly Dimension[];
  ghost: boolean;
  reducedMotion: boolean;
}

export function FilterPart({ dimensions, ghost, reducedMotion }: Props) {
  const { scene } = useGLTF(FILTER_URL, DRACO_PATH);
  const mesh = useMemo(() => prepareMesh(scene, METRES_TO_MM, 'base'), [scene]);
  const scale = useMemo(() => filterScale(mesh.size, dimensions), [mesh, dimensions]);
  const labels = useMemo(() => filterAnnotations(dimensions), [dimensions]);

  const group = useRef<Group>(null);
  const shown = useRef({ x: scale.x, y: scale.y });
  useFrame((_, delta) => {
    if (!group.current) return;
    const k = reducedMotion ? 1 : 1 - Math.exp(-delta * 9);
    shown.current.x += (scale.x - shown.current.x) * k;
    shown.current.y += (scale.y - shown.current.y) * k;
    group.current.scale.set(shown.current.x, shown.current.y, shown.current.x);
  });

  const H = mesh.size.y * scale.y;
  const R = (Math.max(mesh.size.x, mesh.size.z) * scale.x) / 2;
  const axis = useMemo(() => chainSegments([0, -9, 0], [0, H + 9, 0]), [H]);
  const rims = useMemo(() => {
    const p = profile(mesh.geometries);
    return { top: rimTop(p), bottom: rimBottom(p) };
  }, [mesh]);
  const [face, setFace] = useState<Face>('top');
  const cotes = useMemo(() => filterCotes(R, H, { face, faceY: rims[face] * scale.y }), [R, H, rims, scale.y, face]);

  // The cotes are built facing +Z and turn continuously about the part's axis to follow the camera
  // azimuth; the Ø sits on the face the camera sees. Only a face switch fades out, swaps, fades back in
  // (instantly under reduced motion).
  const outer = useRef<Group>(null);
  const placed = useRef<Group>(null);
  const want = useRef<Face>('top');
  const applied = useRef<Face | null>(null);
  const fade = useRef(1);
  const turn = useRef<number | null>(null);
  useFrame(({ camera }, delta) => {
    const o = outer.current;
    const g = placed.current;
    if (!o || !g) return;
    o.worldToLocal(world.copy(camera.position));
    const azimuth = Math.atan2(world.x, world.z);
    turn.current = turn.current === null ? azimuth : dampAngle(turn.current, azimuth, delta, FOLLOW_RATE, reducedMotion);
    g.rotation.y = turn.current;
    want.current = facingFace(Math.atan2(world.y - H / 2, Math.hypot(world.x, world.z)), want.current);
    const stale = want.current !== applied.current;
    const step = reducedMotion ? 1 : delta / (SWITCH_S / 2);
    if (stale && applied.current !== null && fade.current > 0 && !reducedMotion) {
      fade.current = Math.max(0, fade.current - step);
    } else {
      if (stale) {
        setFace(want.current);
        applied.current = want.current;
      }
      fade.current = Math.min(1, fade.current + step);
    }
    g.traverse((n) => {
      const m = (n as Mesh).material as Material | Material[] | undefined;
      if (m) for (const x of Array.isArray(m) ? m : [m]) x.opacity = fade.current;
    });
    for (const el of Object.values(labelEls.current)) if (el) el.style.opacity = String(fade.current);
  });

  const labelEls = useRef<Partial<Record<CoteKey, HTMLElement | null>>>({});
  useFrame(() => {
    const items = (Object.entries(labelEls.current) as [CoteKey, HTMLElement | null][]).flatMap(([key, el]) => {
      if (!el) return [];
      const r = el.getBoundingClientRect();
      return [{ key, rect: { x: r.left, y: r.top, w: r.width, h: r.height } }];
    });
    const shown = new Set(visibleLabels(items));
    for (const { key } of items) labelEls.current[key]!.style.visibility = shown.has(key) ? 'visible' : 'hidden';
  });
  const bind = (key: CoteKey) => (el: HTMLElement | null) => {
    labelEls.current[key] = el;
  };

  return (
    <group ref={outer} position={[0, -H / 2, 0]}>
      <group ref={group}>
        {mesh.geometries.map((g, i) => (
          <HiddenLine key={i} geometry={g} ghost={ghost} threshold={24} rings />
        ))}
      </group>
      <Line points={axis} segments color={DRAFT_BLUE} lineWidth={LINE_THIN} transparent opacity={ghost ? 0.28 : 1} depthTest={false} renderOrder={9} />
      {!ghost && (
        <group ref={placed}>
          {labels.diameter && <DiameterCote cote={cotes.diameter} label={labels.diameter} labelRef={bind('diameter')} face={face} />}
          {labels.height && <HeightCote cote={cotes.height} label={labels.height} labelRef={bind('height')} />}
          {labels.thread && <ThreadNote cote={cotes.thread} label={labels.thread} labelRef={bind('thread')} />}
        </group>
      )}
    </group>
  );
}

function profile(geometries: readonly BufferGeometry[]): [number, number][] {
  return geometries.flatMap((g) => {
    const p = g.getAttribute('position');
    return Array.from({ length: p.count }, (_, i): [number, number] => [Math.hypot(p.getX(i), p.getZ(i)), p.getY(i)]);
  });
}

type LabelRef = (el: HTMLElement | null) => void;

function DiameterCote({ cote, label, labelRef, face }: { cote: FilterCotes['diameter']; label: string; labelRef: LabelRef; face: Face }) {
  const tips = useMemo<ArrowTip[]>(() => {
    const a = new Vector3(...cote.from);
    const b = new Vector3(...cote.to);
    const inward = b.clone().sub(a).normalize();
    return [
      { tip: a, back: inward },
      { tip: b, back: inward.clone().negate() },
    ];
  }, [cote]);
  return (
    <group>
      <Line points={[cote.from, cote.to]} segments color={DRAFT_BLUE} lineWidth={LINE_THIN} renderOrder={ORDER} {...OVERLAY} />
      <Arrowheads tips={tips} opacity={1} />
      <Html position={cote.label} zIndexRange={[5, 0]} wrapperClass="dim-host">
        <span ref={labelRef} className={`dim-label dim-label--${face === 'top' ? 'above' : 'below'}`}>
          {label}
        </span>
      </Html>
    </group>
  );
}

function HeightCote({ cote, label, labelRef }: { cote: FilterCotes['height']; label: string; labelRef: LabelRef }) {
  const tips = useMemo<ArrowTip[]>(() => {
    const a = new Vector3(...cote.from);
    const b = new Vector3(...cote.to);
    const up = b.clone().sub(a).normalize();
    return [
      { tip: a, back: up },
      { tip: b, back: up.clone().negate() },
    ];
  }, [cote]);
  return (
    <group>
      <Line points={[...cote.extensions, cote.from, cote.to]} segments color={DRAFT_BLUE} lineWidth={LINE_THIN} renderOrder={ORDER} {...OVERLAY} />
      <Arrowheads tips={tips} opacity={1} />
      <Html position={cote.label} zIndexRange={[5, 0]} wrapperClass="dim-host">
        <span ref={labelRef} className="dim-label dim-label--right">
          {label}
        </span>
      </Html>
    </group>
  );
}

function ThreadNote({ cote, label, labelRef }: { cote: FilterCotes['thread']; label: string; labelRef: LabelRef }) {
  const { segments, tips } = useMemo(() => {
    const p = cote.points.map((q) => new Vector3(...q));
    return {
      segments: p.slice(1).flatMap((q, i) => [p[i]!.toArray() as Vec3, q.toArray() as Vec3]),
      tips: [{ tip: p[0]!, back: p[1]!.clone().sub(p[0]!).normalize() }],
    };
  }, [cote]);
  return (
    <group>
      <Line points={segments} segments color={DRAFT_BLUE} lineWidth={LINE_THIN} renderOrder={ORDER} {...OVERLAY} />
      <Arrowheads tips={tips} opacity={1} length={3} width={0.9} />
      <Html position={cote.label} zIndexRange={[5, 0]} wrapperClass="dim-host">
        <span ref={labelRef} className="dim-label dim-label--note dim-label--left">
          {label}
        </span>
      </Html>
    </group>
  );
}

useGLTF.preload(FILTER_URL, DRACO_PATH);
