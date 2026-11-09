import { createContext, Suspense, useContext } from 'react';
import { PerspectiveCamera, View } from '@react-three/drei';
import { MeshErrorBoundary } from '../scene/VehicleBody';
import { useReducedMotion } from '../scene/useReducedMotion';
import { EdgeModel, useTokenColor, type EdgeFit } from './EdgeModel';
import { partUrl, type PartFile } from './partMeshes';

const PART_FIT: EdgeFit = { mode: 'centre', size: 1 };

/**
 * Views are drawn into one shared canvas by the rect of their element, which a hidden plane still has.
 * Only the front plane may render live parts; the others get an empty plate of the same size.
 */
export const PartsLive = createContext(false);

/** A part rendered live on a small lit plate. `label` names it for assistive tech. */
export function PartThumb({ file, label, size = 'sm', tone }: { file: PartFile | null; label: string; size?: 'sm' | 'md' | 'lg'; tone?: 'over' | 'focus' }) {
  const live = useContext(PartsLive);
  const reduced = useReducedMotion();
  const ink = useTokenColor(tone === 'over' ? '--w-over' : tone === 'focus' ? '--w-blue' : '--w-ink');
  const className = `w-part w-part--${size}`;
  if (!file)
    return (
      <span className={`${className} w-part--unknown`} role="img" aria-label={label}>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.25" aria-hidden="true" focusable="false">
          <rect x="4.5" y="4.5" width="15" height="15" strokeDasharray="2 2" />
          <path d="M9.5 10a2.5 2.5 0 1 1 3.4 2.3c-.6.3-.9.8-.9 1.4V14.5M12 16.8v.4" />
        </svg>
      </span>
    );
  if (!live) return <span className={className} role="img" aria-label={label} />;
  return (
    <span className="w-part-frame" role="img" aria-label={label}>
      <View className={className}>
      <PerspectiveCamera makeDefault fov={30} near={0.05} far={20} position={[1.25, 0.95, 1.7]} onUpdate={(c) => c.lookAt(0, 0, 0)} />
      <MeshErrorBoundary key={file} fallback={null}>
        <Suspense fallback={null}>
          <EdgeModel url={partUrl(file)} angle={18} fit={PART_FIT} ink={ink} reduced={reduced} />
        </Suspense>
      </MeshErrorBoundary>
      </View>
    </span>
  );
}
