import type { VehicleSummary } from '../types';

// Stage D mesh resolver — a vehicle → the default blueprint glb to load. Make/model specifics win over
// the generic per-VehicleType fallback. The glb facing is observed in-browser; where a source mesh is
// not +x=front (the anchors' assumption, see anchors.ts:13) its rotationY override lands here.

export interface MeshSpec {
  /** Same-origin absolute path under public/. */
  url: string;
  /** Low-poly variant for the parc grid (drei <View> thumbnails). Falls back to `url` if no `-web` suffix. */
  thumbUrl: string;
  /** EdgesGeometry threshold (degrees) — moto's spoke/fin clutter needs a coarser 45°. */
  edgeAngle: number;
  /** Yaw correction so the mesh reads +x=front (anchors require it). Omitted = already aligned. */
  rotationY?: number;
}

// Every shipped asset is `<base>-web.glb` with a decimated `<base>-thumb.glb` sibling (BLUEPRINT-PIPELINE
// "Thumb variants"). A url without the `-web` suffix has no thumb → reuse the full mesh (never a 404).
function thumbFor(url: string): string {
  return url.replace(/-web\.glb$/, '-thumb.glb');
}

type DefaultType = 'car' | 'moto' | 'cv' | 'groundscare';

// car default is the Mazda6 generic 3-box, NOT a `car-default` slug — so this is an explicit table.
const DEFAULT_URL: Record<DefaultType, string> = {
  car: '/mazda6-gh-web.glb',
  moto: '/moto-default-web.glb',
  cv: '/cv-default-web.glb',
  groundscare: '/groundscare-default-web.glb',
};

// Per-asset yaw correction, keyed by url. Both Blender-exported assets land FRONT AT −x (espace lies
// along x with its taillights at +x; mazda6 lies along z, rear −z, and reads the same on screen), so
// −x is the house facing a new asset must match — NOT the +x the anchors comment claims (2026-09-28:
// anchors are DIMS-based and only drive the proxy, so the two never met; left as-is, flagged here).
//
// peugeot-206: Sketchfab glTF, front at +z (source node bounds — `engine__spec` z∈[1.23,1.77],
// `Boot__spec` z∈[-1.82,-1.13]). −π/2 about y maps +z→−x. Verified in-browser against the mazda6.
const ROTATION_Y: Record<string, number> = {
  '/peugeot-206-web.glb': -Math.PI / 2,
};

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
  // `\b206\b` and not `includes`, so a 2008 / 306 / 406 keeps the generic car default.
  if (make.trim().toLowerCase() === 'peugeot' && /\b206\b/.test(model.trim().toLowerCase()))
    return '/peugeot-206-web.glb';
  if (make.trim().toLowerCase() === 'mazda' && /\b(mazda\s*)?6\b/.test(model.trim().toLowerCase()))
    return '/mazda6-gh-web.glb';
  // GS / GSA only: an F 800 R/ST/GT is a road bike and would misread as this enduro. The catalogue spells
  // the make "BMW MOTORCYCLES", the owner "BMW" or "BMW Motorrad".
  if (/^bmw\b/.test(make.trim().toLowerCase()) && /\bf\s*800\s*gs/.test(model.trim().toLowerCase()))
    return '/bmw-f800gs-adventure-web.glb';
  return null;
}

export function resolveMesh(v: Pick<VehicleSummary, 'vehicleType' | 'make' | 'model'>): MeshSpec {
  const url = specificUrl(v.make ?? '', v.model ?? '') ?? DEFAULT_URL[normalizeType(v.vehicleType ?? '')];
  const edgeAngle = normalizeType(v.vehicleType ?? '') === 'moto' ? 45 : 30;
  const thumbUrl = thumbFor(url);
  const rotationY = ROTATION_Y[url];
  return rotationY === undefined ? { url, thumbUrl, edgeAngle } : { url, thumbUrl, edgeAngle, rotationY };
}
