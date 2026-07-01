import { Component, Suspense, type ReactNode } from 'react';
import type { VehicleSummary } from '../types';
import { CarProxy, type BodyClass } from './CarProxy';
import { resolveMesh } from './vehicleMesh';
import { VehicleMesh } from './VehicleMesh';

// The single body element that replaces CarProxy on the 3D path. It resolves the glb, suspends while it
// loads (showing the matching-body proxy so the stage never blanks), and catches any load/decode failure
// to fall back to the same proxy. So CarProxy serves double duty — loading placeholder AND error
// fallback — and the real mesh swaps in once decoded.
//
// Progressive low→high reveal: the small `-thumb.glb` (preloaded, see VehicleMesh) renders first as the
// inner Suspense fallback while the full `-web.glb` streams in on demand; on a cold cache that bubbles to
// the outer Suspense (→ proxy) until the thumb resolves. Sequence: proxy → thumb → full. Both go through
// the same VehicleMesh/buildBlueprint at real scale, so the swap is in-place (edges densify, no jump).

// useGLTF throws during suspended render on 404/decode error; only an error boundary catches a
// render-phase throw, and there is none elsewhere in the app. Keep it tiny and scene-local.
export class MeshErrorBoundary extends Component<{ fallback: ReactNode; children: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError() {
    return { failed: true };
  }
  render() {
    return this.state.failed ? this.props.fallback : this.props.children;
  }
}

// Render the thumb first, swap to the full mesh once it loads. When thumbUrl === url (a mesh with no
// thumb), the two collapse to one load (no double-fetch).
function ThumbThenFull({ spec, paint, scanning, reduced }: { spec: ReturnType<typeof resolveMesh>; paint: string; scanning: boolean; reduced: boolean }) {
  const thumbSpec = { ...spec, url: spec.thumbUrl };
  if (spec.thumbUrl === spec.url) return <VehicleMesh spec={spec} paint={paint} scanning={scanning} reduced={reduced} />;
  return (
    <Suspense fallback={<VehicleMesh spec={thumbSpec} paint={paint} scanning={scanning} reduced={reduced} />}>
      <VehicleMesh spec={spec} paint={paint} scanning={scanning} reduced={reduced} />
    </Suspense>
  );
}

export function VehicleBody({
  vehicle,
  bodyClass,
  paint,
  scanning = false,
  reduced = false,
}: {
  vehicle: VehicleSummary;
  bodyClass: BodyClass;
  paint: string;
  scanning?: boolean;
  reduced?: boolean;
}) {
  const spec = resolveMesh(vehicle);
  const proxy = <CarProxy bodyClass={bodyClass} paint={paint} />;
  // key on the resolved url so switching to a different mesh resets a previously-failed boundary.
  return (
    <MeshErrorBoundary key={spec.url} fallback={proxy}>
      <Suspense fallback={proxy}>
        <ThumbThenFull spec={spec} paint={paint} scanning={scanning} reduced={reduced} />
      </Suspense>
    </MeshErrorBoundary>
  );
}
