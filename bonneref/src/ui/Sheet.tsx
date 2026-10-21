import { Component, lazy, Suspense, useEffect, useRef, useState, type ReactNode } from 'react';
import { measure } from '../dims';
import { sheetLine } from '../narration';
import { categoryPicto } from '../picto';
import { CategoryPicto } from './CategoryPicto';
import { useLinger } from './Presence';
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
  categoryPath?: string | null;
  status?: ReactNode;
  // A search is running: the drawing area turns the loading carousel whatever the part's family.
  searching?: boolean;
  // The agent's live sentence: takes the searching line's place, and shows over a part being refined.
  narration?: string | null;
  // Shown under the part when nothing is being narrated.
  hint?: string | null;
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

export const UNKNOWN_LABEL = 'Pas de modèle 3D';

// The overlaid title block never clips: a block taller than the sheet (variant table) makes the sheet grow.
function useBlockHeight(active: boolean) {
  const ref = useRef<HTMLDivElement>(null);
  const [height, setHeight] = useState<number | null>(null);
  useEffect(() => {
    const el = ref.current;
    if (!active || !el) return setHeight(null);
    const observer = new ResizeObserver(() => setHeight(el.getBoundingClientRect().height));
    observer.observe(el);
    return () => observer.disconnect();
  }, [active]);
  return [ref, height] as const;
}

// The canvas outlives the search by its fade when the result has no mesh (pictogram, « ? », message):
// the carousel dissolves while the pictogram or the « ? » comes in over it.
const CANVAS_EXIT_MS = 360;

export function Sheet({ family, viewLabel, dimensions, ghost, reducedMotion, placeholder, overlayWidth, categoryPath = null, status, searching = false, narration = null, hint = null, children }: Props) {
  const overlaid = Boolean(overlayWidth && children);
  const [sideRef, blockHeight] = useBlockHeight(overlaid);
  const drawn = family.kind !== 'none' && family.kind !== 'generic' ? family : null;
  const canvas = useLinger(searching || drawn !== null, reducedMotion ? 0 : CANVAS_EXIT_MS);
  const lastDrawn = useRef(drawn);
  if (drawn) lastDrawn.current = drawn;
  const line = sheetLine({ narration, status, searching, hint });
  const side = overlayWidth ? { right: overlayWidth } : undefined;
  return (
    <div className="sheet" style={blockHeight ? { minHeight: Math.ceil(blockHeight) } : undefined}>
      {viewLabel && !searching && <div className="sheet-label m">{viewLabel}</div>}
      {canvas.mounted && (
        <div className="sheet-canvas" style={side} data-leaving={canvas.leaving || undefined} data-searching={searching || undefined}>
          <SceneBoundary fallback={<div className="sheet-placeholder"><p className="sheet-message">Vue 3D indisponible. Rechargez la page.</p></div>}>
            <Suspense fallback={null}>
              <Blueprint
                family={canvas.leaving ? lastDrawn.current : drawn}
                dimensions={dimensions}
                ghost={ghost}
                reducedMotion={reducedMotion}
                extent={drawn ? extentFor(drawn, dimensions) : 150}
                searching={searching || (canvas.leaving && !drawn)}
              />
            </Suspense>
          </SceneBoundary>
        </div>
      )}
      {!searching && family.kind === 'none' && placeholder && (
        <div className="sheet-placeholder" style={side}>
          {placeholder}
        </div>
      )}
      {!searching && family.kind === 'generic' && (
        <div className="sheet-figure" style={side}>
          {!status && <Fallback categoryPath={categoryPath} />}
        </div>
      )}
      {line ? (
        <p key={String(line.text)} className={`sheet-line sheet-line--${line.kind}`} role={line.kind === 'narration' ? 'status' : undefined} style={side}>
          {line.text}
        </p>
      ) : (
        status && (
          <div className="sheet-status" style={side}>
            {status}
          </div>
        )
      )}
      {overlaid ? (
        <div ref={sideRef} className="sheet-side" style={{ width: overlayWidth! }}>
          {children}
        </div>
      ) : (
        children
      )}
    </div>
  );
}

function Fallback({ categoryPath }: { categoryPath: string | null }) {
  const picto = categoryPath ? categoryPicto(categoryPath) : null;
  return picto ? <CategoryPicto picto={picto} /> : <Unknown />;
}

function Unknown() {
  return (
    <div className="sheet-unknown" role="img" aria-label={UNKNOWN_LABEL}>
      <span className="sheet-unknown-mark" aria-hidden="true">?</span>
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
