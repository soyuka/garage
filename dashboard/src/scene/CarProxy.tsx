import { Edges } from '@react-three/drei';
import { BLUEPRINT_EDGE } from './signalColors';
import type { StageVariant } from './stageVariant';

// The body-class PROXY car (degradation rung b), rendered as a BLUEPRINT: glowing edges over a
// paint-tinted night-glass volume — a futuristic technical-drawing / hologram look. Edges hide the
// low-poly crudeness, so a simple primitive mesh reads as intentional (the real hyperreal .glb is
// Stage D). Edge color is an electric blue, NOT the agent's rationed cyan. Hotspot anchors are per
// body-class, not per mesh (BUILD-NOTE 2).

export type BodyClass = 'hatchback' | 'berline' | 'suv' | 'utilitaire' | 'moto';

export const EDGE = BLUEPRINT_EDGE; // blueprint line — single source in signalColors
export const FILL_OPACITY = 0.14; // tinted night glass: enough body for the edges to contrast against

// PROTOTYPE (stageVariant 'mat', see stageVariant.ts) — a warm off-white matte shell (clay/maquette
// automotive-viz register) instead of the tinted night-glass fill: non-emissive, lit + toneMapped like
// any ordinary surface (no bloom contribution from the shell itself). The blueprint edges are untouched
// by this swap — they stay the same electric-blue lines over either fill. Wheels sit a shade darker so
// the silhouette still reads under the flat clay body.
const PORCELAIN_BODY = '#f1ece2';
const PORCELAIN_WHEEL = '#cdc6b9';

// Two VehicleType spellings reach this at runtime: legacy fixtures (car/motorcycle) and the API enum
// App\Garage\VehicleType (car/moto/cv/groundscare). groundscare has no dedicated body shape — utilitaire
// is the least-wrong fallback (only rendered when its real glb fails to load); a precise mower proxy is
// a tracked follow-up. The returned value is always a DIMS/regionAnchors key.
export function bodyClassFor(vehicleType: string): BodyClass {
  switch (vehicleType.trim().toLowerCase()) {
    case 'moto':
    case 'motorcycle':
      return 'moto';
    case 'cv':
    case 'truck':
    case 'van':
    case 'groundscare':
    case 'mower':
      return 'utilitaire';
    default:
      return 'berline';
  }
}

interface Dims {
  len: number;
  wid: number;
  bodyH: number;
  cabinLen: number;
  cabinH: number;
  cabinOff: number;
  wheelR: number;
}

// Exported as the canonical body-geometry source so the anchors seam (and every 3D leaf reading it)
// stays in lockstep with what this proxy actually renders. See scene/anchors.ts.
export const DIMS: Record<Exclude<BodyClass, 'moto'>, Dims> = {
  hatchback: { len: 3.9, wid: 1.75, bodyH: 0.62, cabinLen: 1.6, cabinH: 0.6, cabinOff: -0.3, wheelR: 0.33 },
  berline: { len: 4.6, wid: 1.8, bodyH: 0.64, cabinLen: 2.0, cabinH: 0.56, cabinOff: -0.15, wheelR: 0.34 },
  suv: { len: 4.5, wid: 1.9, bodyH: 0.95, cabinLen: 2.0, cabinH: 0.68, cabinOff: -0.1, wheelR: 0.41 },
  utilitaire: { len: 5.0, wid: 1.95, bodyH: 1.15, cabinLen: 1.3, cabinH: 0.85, cabinOff: -1.55, wheelR: 0.38 },
};

/** A blueprint box: faint paint-tinted fill (or, under stageVariant 'mat', a matte porcelain fill) +
 * glowing edge lines. */
function Box({
  args,
  position,
  paint,
  stageVariant,
}: {
  args: [number, number, number];
  position: [number, number, number];
  paint: string;
  stageVariant: StageVariant;
}) {
  return (
    <mesh position={position}>
      <boxGeometry args={args} />
      {stageVariant === 'mat' ? (
        <meshStandardMaterial color={PORCELAIN_BODY} roughness={0.52} metalness={0.02} />
      ) : (
        <meshBasicMaterial color={paint} transparent opacity={FILL_OPACITY} depthWrite={false} />
      )}
      <Edges threshold={20} color={EDGE} />
    </mesh>
  );
}

function Wheel({ x, z, r, stageVariant }: { x: number; z: number; r: number; stageVariant: StageVariant }) {
  return (
    <mesh position={[x, r, z]} rotation={[Math.PI / 2, 0, 0]}>
      <cylinderGeometry args={[r, r, 0.2, 24]} />
      {stageVariant === 'mat' ? (
        <meshStandardMaterial color={PORCELAIN_WHEEL} roughness={0.62} metalness={0.02} />
      ) : (
        <meshBasicMaterial color="#0e1318" transparent opacity={FILL_OPACITY} depthWrite={false} />
      )}
      <Edges threshold={18} color={EDGE} />
    </mesh>
  );
}

function Car({ d, paint, stageVariant }: { d: Dims; paint: string; stageVariant: StageVariant }) {
  const axleX = d.len / 2 - d.wheelR - 0.1;
  const axleZ = d.wid / 2 - 0.02;
  const bodyY = d.wheelR + d.bodyH / 2;
  const cabinY = bodyY + d.bodyH / 2 + d.cabinH / 2 - 0.06;
  return (
    <group>
      <Box args={[d.len, d.bodyH, d.wid]} position={[0, bodyY, 0]} paint={paint} stageVariant={stageVariant} />
      <Box args={[d.cabinLen, d.cabinH, d.wid - 0.16]} position={[d.cabinOff, cabinY, 0]} paint={paint} stageVariant={stageVariant} />
      <Wheel x={axleX} z={axleZ} r={d.wheelR} stageVariant={stageVariant} />
      <Wheel x={axleX} z={-axleZ} r={d.wheelR} stageVariant={stageVariant} />
      <Wheel x={-axleX} z={axleZ} r={d.wheelR} stageVariant={stageVariant} />
      <Wheel x={-axleX} z={-axleZ} r={d.wheelR} stageVariant={stageVariant} />
    </group>
  );
}

function Moto({ paint, stageVariant }: { paint: string; stageVariant: StageVariant }) {
  const r = 0.32;
  const axleX = 0.72;
  return (
    <group>
      <Wheel x={axleX} z={0} r={r} stageVariant={stageVariant} />
      <Wheel x={-axleX} z={0} r={r} stageVariant={stageVariant} />
      <Box args={[1.5, 0.18, 0.22]} position={[0, r + 0.34, 0]} paint={paint} stageVariant={stageVariant} />
      <Box args={[0.7, 0.34, 0.32]} position={[0.05, r + 0.56, 0]} paint={paint} stageVariant={stageVariant} />
      <Box args={[0.7, 0.12, 0.26]} position={[-0.55, r + 0.52, 0]} paint={paint} stageVariant={stageVariant} />
    </group>
  );
}

export function CarProxy({
  bodyClass,
  paint,
  stageVariant = 'night',
}: {
  bodyClass: BodyClass;
  paint: string;
  /** Prototype A/B look (stageVariant.ts). Absent → today's night-glass fill, unchanged. */
  stageVariant?: StageVariant;
}) {
  if (bodyClass === 'moto') return <Moto paint={paint} stageVariant={stageVariant} />;
  return <Car d={DIMS[bodyClass]} paint={paint} stageVariant={stageVariant} />;
}
