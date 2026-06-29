import { Component, Suspense, type ReactNode } from 'react';
import type { VehicleSummary } from '../types';
import { CarProxy, type BodyClass } from './CarProxy';
import { resolveMesh } from './vehicleMesh';
import { VehicleMesh } from './VehicleMesh';

// The single body element that replaces CarProxy on the 3D path. It resolves the glb, suspends while it
// loads (showing the matching-body proxy so the stage never blanks), and catches any load/decode failure
// to fall back to the same proxy. So CarProxy serves double duty — loading placeholder AND error
// fallback — and the real mesh swaps in once decoded.

// useGLTF throws during suspended render on 404/decode error; only an error boundary catches a
// render-phase throw, and there is none elsewhere in the app. Keep it tiny and scene-local.
class MeshErrorBoundary extends Component<{ fallback: ReactNode; children: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError() {
    return { failed: true };
  }
  render() {
    return this.state.failed ? this.props.fallback : this.props.children;
  }
}

export function VehicleBody({ vehicle, bodyClass, paint }: { vehicle: VehicleSummary; bodyClass: BodyClass; paint: string }) {
  const spec = resolveMesh(vehicle);
  const proxy = <CarProxy bodyClass={bodyClass} paint={paint} />;
  // key on the resolved url so switching to a different mesh resets a previously-failed boundary.
  return (
    <MeshErrorBoundary key={spec.url} fallback={proxy}>
      <Suspense fallback={proxy}>
        <VehicleMesh spec={spec} paint={paint} />
      </Suspense>
    </MeshErrorBoundary>
  );
}
