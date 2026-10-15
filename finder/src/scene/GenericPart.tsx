import { useMemo } from 'react';
import { ExtrudeGeometry, Path, Shape } from 'three';
import { HiddenLine } from './HiddenLine';

const W = 96;
const H = 56;
const DEPTH = 40;
const CHAMFER = 6;
const BORE = 13;

// A deliberately abstract block (chamfered, one bore) for parts without a 3D model: it shows where the
// drawing would be, never what the part looks like, and carries no cotes.
function blockGeometry(): ExtrudeGeometry {
  const shape = new Shape();
  shape.moveTo(-W / 2, -H / 2);
  shape.lineTo(W / 2, -H / 2);
  shape.lineTo(W / 2, H / 2);
  shape.lineTo(-W / 2, H / 2);
  shape.closePath();
  const hole = new Path();
  hole.absarc(0, 0, BORE, 0, Math.PI * 2, true);
  shape.holes.push(hole);
  const g = new ExtrudeGeometry(shape, { depth: DEPTH - 2 * CHAMFER, bevelEnabled: true, bevelSize: CHAMFER, bevelThickness: CHAMFER, bevelSegments: 1, curveSegments: 40 });
  g.center();
  g.rotateX(-Math.PI / 2);
  return g;
}

export function GenericPart({ ghost }: { ghost: boolean }) {
  const geometry = useMemo(blockGeometry, []);
  return <HiddenLine geometry={geometry} ghost={ghost} threshold={20} />;
}
