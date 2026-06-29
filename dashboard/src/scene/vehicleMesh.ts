import type { VehicleSummary } from '../types';

// Stage D mesh resolver — a vehicle → the default blueprint glb to load. Make/model specifics win over
// the generic per-VehicleType fallback. The glb facing is observed in-browser; where a source mesh is
// not +x=front (the anchors' assumption, see anchors.ts:13) its rotationY override lands here.

export interface MeshSpec {
  /** Same-origin absolute path under public/. */
  url: string;
  /** EdgesGeometry threshold (degrees) — moto's spoke/fin clutter needs a coarser 45°. */
  edgeAngle: number;
  /** Yaw correction so the mesh reads +x=front (anchors require it). Omitted = already aligned. */
  rotationY?: number;
}

type DefaultType = 'car' | 'moto' | 'cv' | 'groundscare';

// car default is the Mazda6 generic 3-box, NOT a `car-default` slug — so this is an explicit table.
const DEFAULT_URL: Record<DefaultType, string> = {
  car: '/mazda6-gh-web.glb',
  moto: '/moto-default-web.glb',
  cv: '/cv-default-web.glb',
  groundscare: '/groundscare-default-web.glb',
};

// Per-asset yaw correction, keyed by url. Empty until each glb's facing is verified in-browser; an
// off-axis source gets `Math.PI` / `±Math.PI/2` here. Anchors stay DIMS-based (+x=front).
const ROTATION_Y: Record<string, number> = {};

function normalizeType(vehicleType: string): DefaultType {
  switch (vehicleType.trim().toLowerCase()) {
    case 'moto':
    case 'motorcycle':
      return 'moto';
    case 'cv':
    case 'truck':
    case 'van':
      return 'cv';
    case 'groundscare':
    case 'mower':
      return 'groundscare';
    default:
      return 'car';
  }
}

function specificUrl(make: string, model: string): string | null {
  const m = `${make} ${model}`.trim().toLowerCase();
  if (m.includes('espace')) return '/renault-espace-iv-web.glb';
  if (make.trim().toLowerCase() === 'mazda' && /\b(mazda\s*)?6\b/.test(model.trim().toLowerCase()))
    return '/mazda6-gh-web.glb';
  return null;
}

export function resolveMesh(v: Pick<VehicleSummary, 'vehicleType' | 'make' | 'model'>): MeshSpec {
  const url = specificUrl(v.make ?? '', v.model ?? '') ?? DEFAULT_URL[normalizeType(v.vehicleType ?? '')];
  const edgeAngle = normalizeType(v.vehicleType ?? '') === 'moto' ? 45 : 30;
  const rotationY = ROTATION_Y[url];
  return rotationY === undefined ? { url, edgeAngle } : { url, edgeAngle, rotationY };
}
