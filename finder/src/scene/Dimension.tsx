import { Html } from '@react-three/drei';
import { useMemo } from 'react';
import { BufferGeometry, Float32BufferAttribute, Vector3 } from 'three';
import { DRAFT_BLUE } from './palette';

interface Props {
  from: [number, number, number];
  to: [number, number, number];
  extension: [number, number, number];
  label: string;
  arrow?: number;
}

// A drafting dimension: two extension lines from the part, the dimension line with filled-look
// open arrowheads, and the value as DOM text so it stays crisp at any zoom.
export function DimensionLine({ from, to, extension, label, arrow = 3 }: Props) {
  const geometry = useMemo(() => {
    const a = new Vector3(...from);
    const b = new Vector3(...to);
    const ext = new Vector3(...extension);
    const dir = b.clone().sub(a).normalize();
    const side = ext.clone().normalize().multiplyScalar(arrow * 0.45);
    const head = (tip: Vector3, back: Vector3) => {
      const base = tip.clone().add(back.clone().multiplyScalar(arrow));
      return [tip, base.clone().add(side), tip, base.clone().sub(side)];
    };
    const pts = [
      a.clone().sub(ext), a.clone().add(ext.clone().multiplyScalar(0.15)),
      b.clone().sub(ext), b.clone().add(ext.clone().multiplyScalar(0.15)),
      a, b,
      ...head(a, dir),
      ...head(b, dir.clone().negate()),
    ];
    const g = new BufferGeometry();
    g.setAttribute('position', new Float32BufferAttribute(pts.flatMap((p) => [p.x, p.y, p.z]), 3));
    return g;
  }, [from, to, extension, arrow]);

  const mid: [number, number, number] = [(from[0] + to[0]) / 2, (from[1] + to[1]) / 2, (from[2] + to[2]) / 2];

  return (
    <group>
      <lineSegments geometry={geometry}>
        <lineBasicMaterial color={DRAFT_BLUE} transparent opacity={0.85} />
      </lineSegments>
      <Html position={mid} center zIndexRange={[5, 0]} className="dim-label">
        {label}
      </Html>
    </group>
  );
}
