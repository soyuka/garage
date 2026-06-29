import { Suspense, type RefObject } from 'react';
import { Canvas } from '@react-three/fiber';
import { OrbitControls } from '@react-three/drei';
import type { ForecastDue, VehicleSummary } from '../types';
import { bodyClassFor } from './CarProxy';
import { VehicleBody } from './VehicleBody';
import { SilhouetteCar } from './SilhouetteCar';
import { Hotspots } from './Hotspots';
import { BodyGlow } from './BodyGlow';
import { RimGauge } from './RimGauge';
import './scene.css';

// Le Plateau — the hero stage. The degradation ladder lives behind a manual `rung` prop (the seam
// Stage D's auto-detector plugs into): a=real .glb (Stage D; falls back to the proxy here) · b=R3F
// body-class proxy on the turntable · c=flat SVG silhouette · d=list-only (the scene shows the flat
// plan instead). WebGL is lazy-init'd by mounting the Canvas only on the 3D rungs.
export type Rung = 'a' | 'b' | 'c' | 'd';

// A tasteful default paint per make (the backend has no paint color yet — Stage C/D may resolve one).
const PAINT: Record<string, string> = {
  Renault: '#3b4a5a',
  Peugeot: '#5a3b3b',
  Citroën: '#46524a',
  Yamaha: '#243447',
};
function paintFor(v: VehicleSummary): string {
  return PAINT[v.make] ?? '#3a4654';
}

function SceneLights() {
  // Blueprint edges are unlit; only the turntable disc needs light. A warm key keeps the §2 mood.
  return (
    <>
      <ambientLight intensity={0.6} />
      <directionalLight position={[-5, 6, 4]} intensity={0.9} color="#ffe0b0" />
    </>
  );
}

function Turntable() {
  return (
    <group>
      <mesh receiveShadow position={[0, -0.04, 0]}>
        <cylinderGeometry args={[3.2, 3.2, 0.12, 64]} />
        <meshStandardMaterial color="#23272d" metalness={0.7} roughness={0.45} />
      </mesh>
      {/* inlaid light-ring at the rim */}
      <mesh position={[0, 0.03, 0]} rotation={[-Math.PI / 2, 0, 0]}>
        <ringGeometry args={[3.0, 3.18, 64]} />
        <meshStandardMaterial color="#2c323a" emissive="#222a31" emissiveIntensity={0.6} />
      </mesh>
    </group>
  );
}

function Plateau3D({
  vehicle,
  due,
  reduced,
  portalTarget,
  onPlan,
}: {
  vehicle: VehicleSummary;
  due: ForecastDue[];
  reduced: boolean;
  portalTarget?: RefObject<HTMLDivElement | null>;
  onPlan?: (due: ForecastDue) => void;
}) {
  const bodyClass = bodyClassFor(vehicle.vehicleType);
  return (
    <Canvas shadows dpr={[1, 1.75]} camera={{ position: [6.2, 4.3, 9.2], fov: 33 }} gl={{ antialias: true }}>
      <color attach="background" args={['#131210']} />
      <SceneLights />
      <Suspense fallback={null}>
        <group position={[0, 0.04, 0]}>
          <VehicleBody key={vehicle['@id']} vehicle={vehicle} bodyClass={bodyClass} paint={paintFor(vehicle)} />
          {/* additive sick-region glow + hex hotspots share the car-local frame (B.2 step 2 leaves) */}
          <BodyGlow bodyClass={bodyClass} due={due} reduced={reduced} />
          <Hotspots bodyClass={bodyClass} due={due} reduced={reduced} portalTarget={portalTarget} onPlan={onPlan} />
        </group>
        <Turntable />
        {/* the rim is the global gauge — one arc per tracked system, lit by signal (world space) */}
        <RimGauge due={due} reduced={reduced} />
      </Suspense>
      <OrbitControls
        makeDefault
        enablePan={false}
        enableZoom={false}
        autoRotate={!reduced}
        autoRotateSpeed={0.55}
        minPolarAngle={Math.PI * 0.16}
        maxPolarAngle={Math.PI * 0.49}
        target={[0, 0.45, 0]}
      />
    </Canvas>
  );
}

export function Plateau({
  vehicle,
  rung,
  due,
  reduced = false,
  portalTarget,
  onPlan,
}: {
  vehicle: VehicleSummary;
  rung: Rung;
  due: ForecastDue[];
  reduced?: boolean;
  portalTarget?: RefObject<HTMLDivElement | null>;
  onPlan?: (due: ForecastDue) => void;
}) {
  if (rung === 'd') {
    return (
      <div className="at-plateau at-plateau--list">
        <p className="at-plateau-note">Mode liste — le plan d'entretien tient lieu de vue.</p>
      </div>
    );
  }
  if (rung === 'c') {
    return (
      <div className="at-plateau">
        <SilhouetteCar bodyClass={bodyClassFor(vehicle.vehicleType)} paint={paintFor(vehicle)} />
      </div>
    );
  }
  // a (no .glb yet → proxy) and b
  return (
    <div className="at-plateau">
      <Plateau3D vehicle={vehicle} due={due} reduced={reduced} portalTarget={portalTarget} onPlan={onPlan} />
    </div>
  );
}
