import { Component, lazy, Suspense, type ReactNode } from 'react';
import { measure } from '../dims';
import type { Dimension, Family } from '../types';

const Blueprint = lazy(() => import('../scene/Blueprint').then((m) => ({ default: m.Blueprint })));

interface Props {
  family: Family;
  viewLabel: string;
  dimensions: readonly Dimension[];
  ghost: boolean;
  reducedMotion: boolean;
  placeholder: ReactNode;
  overlayWidth: number | null;
  status?: ReactNode;
  notes?: ReactNode;
  children?: ReactNode;
}

// Framing diameter, in scene millimetres: the part's largest span plus room for its cotes, from any angle.
function extentFor(family: Family, dims: readonly Dimension[]): number {
  if (family.kind === 'filter') {
    const height = measure(dims, 'height')?.mid ?? 69;
    const diameter = measure(dims, 'outer')?.mid ?? 70;
    return Math.max(height + 56, diameter + 76);
  }
  if (family.kind === 'brake') return (family.component === 'disc' ? (measure(dims, 'outer')?.mid ?? 254) : 254) + 90;
  return 150;
}

export const GENERIC_CAPTION = 'Pas de modèle 3D : forme générique';
export const APPROXIMATE_CAPTION = 'Forme type de la famille, pas la pièce exacte';

export function Sheet({ family, viewLabel, dimensions, ghost, reducedMotion, placeholder, overlayWidth, status, notes, children }: Props) {
  return (
    <div className="sheet">
      <div className="sheet-label m">{viewLabel}</div>
      {notes && <div className="sheet-notes">{notes}</div>}
      {family.kind === 'none' ? (
        <div className="sheet-placeholder" style={overlayWidth ? { right: overlayWidth } : undefined}>
          {placeholder}
        </div>
      ) : (
        <div className="sheet-canvas" style={overlayWidth ? { right: overlayWidth } : undefined}>
          <SceneBoundary fallback={<div className="sheet-placeholder"><p className="sheet-message">Vue 3D indisponible. Rechargez la page.</p></div>}>
            <Suspense fallback={null}>
              <Blueprint family={family} dimensions={dimensions} ghost={ghost} reducedMotion={reducedMotion} extent={extentFor(family, dimensions)} />
            </Suspense>
          </SceneBoundary>
        </div>
      )}
      {status && (
        <div className="sheet-status" style={overlayWidth ? { right: overlayWidth } : undefined}>
          {status}
        </div>
      )}
      {family.kind === 'generic' && !status && <p className="sheet-caption">{GENERIC_CAPTION}</p>}
      {family.kind === 'mesh' && family.approximate && !status && <p className="sheet-caption">{APPROXIMATE_CAPTION}</p>}
      {family.kind !== 'none' && !ghost && <div className="sheet-hint">Glisser pour tourner</div>}
      {overlayWidth && children ? (
        <div className="sheet-side" style={{ width: overlayWidth }}>
          {children}
        </div>
      ) : (
        children
      )}
    </div>
  );
}

class SceneBoundary extends Component<{ fallback: ReactNode; children: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError() {
    return { failed: true };
  }
  render() {
    return this.state.failed ? this.props.fallback : this.props.children;
  }
}
