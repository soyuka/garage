import { Edges } from '@react-three/drei';

// The body-class PROXY car (degradation rung b), rendered as a BLUEPRINT: glowing edges over a near-
// invisible paint-tinted volume — a futuristic technical-drawing / hologram look. Edges hide the
// low-poly crudeness, so a simple primitive mesh reads as intentional (the real hyperreal .glb is
// Stage D). Edge color is a cool steel-blue, NOT the agent's rationed cyan. Hotspot anchors are per
// body-class, not per mesh (BUILD-NOTE 2).

export type BodyClass = 'hatchback' | 'berline' | 'suv' | 'utilitaire' | 'moto';

export const EDGE = '#a8c4dc'; // blueprint line — cool, distinct from Le Noyau's cyan
export const FILL_OPACITY = 0.07; // a ghost of volume; the lines carry the form

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

/** A blueprint box: faint paint-tinted fill + glowing edge lines. */
function Box({ args, position, paint }: { args: [number, number, number]; position: [number, number, number]; paint: string }) {
  return (
    <mesh position={position}>
      <boxGeometry args={args} />
      <meshBasicMaterial color={paint} transparent opacity={FILL_OPACITY} depthWrite={false} />
      <Edges threshold={20} color={EDGE} />
    </mesh>
  );
}

function Wheel({ x, z, r }: { x: number; z: number; r: number }) {
  return (
    <mesh position={[x, r, z]} rotation={[Math.PI / 2, 0, 0]}>
      <cylinderGeometry args={[r, r, 0.2, 24]} />
      <meshBasicMaterial color="#0e1318" transparent opacity={FILL_OPACITY} depthWrite={false} />
      <Edges threshold={18} color={EDGE} />
    </mesh>
  );
}

function Car({ d, paint }: { d: Dims; paint: string }) {
  const axleX = d.len / 2 - d.wheelR - 0.1;
  const axleZ = d.wid / 2 - 0.02;
  const bodyY = d.wheelR + d.bodyH / 2;
  const cabinY = bodyY + d.bodyH / 2 + d.cabinH / 2 - 0.06;
  return (
    <group>
      <Box args={[d.len, d.bodyH, d.wid]} position={[0, bodyY, 0]} paint={paint} />
      <Box args={[d.cabinLen, d.cabinH, d.wid - 0.16]} position={[d.cabinOff, cabinY, 0]} paint={paint} />
      <Wheel x={axleX} z={axleZ} r={d.wheelR} />
      <Wheel x={axleX} z={-axleZ} r={d.wheelR} />
      <Wheel x={-axleX} z={axleZ} r={d.wheelR} />
      <Wheel x={-axleX} z={-axleZ} r={d.wheelR} />
    </group>
  );
}

function Moto({ paint }: { paint: string }) {
  const r = 0.32;
  const axleX = 0.72;
  return (
    <group>
      <Wheel x={axleX} z={0} r={r} />
      <Wheel x={-axleX} z={0} r={r} />
      <Box args={[1.5, 0.18, 0.22]} position={[0, r + 0.34, 0]} paint={paint} />
      <Box args={[0.7, 0.34, 0.32]} position={[0.05, r + 0.56, 0]} paint={paint} />
      <Box args={[0.7, 0.12, 0.26]} position={[-0.55, r + 0.52, 0]} paint={paint} />
    </group>
  );
}

export function CarProxy({ bodyClass, paint }: { bodyClass: BodyClass; paint: string }) {
  if (bodyClass === 'moto') return <Moto paint={paint} />;
  return <Car d={DIMS[bodyClass]} paint={paint} />;
}
