import { Component, lazy, Suspense, useEffect, useRef, useState, type ReactNode } from 'react';
import { measure } from '../dims';
import type { SheetPhoto } from '../photo';
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
  photo?: SheetPhoto | null;
  status?: ReactNode;
  // A search is running: the drawing area turns the loading carousel whatever the part's family.
  searching?: boolean;
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

export const UNKNOWN_LABEL = 'Ni modèle 3D ni photo catalogue';
export const APPROXIMATE_CAPTION = 'Forme type de la famille, pas la pièce exacte';

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

// The canvas outlives the search by its fade when the result has no mesh (photo, « ? », message):
// the carousel dissolves while the photo or the « ? » comes in over it.
const CANVAS_EXIT_MS = 360;

function useLinger(on: boolean, ms: number): { mounted: boolean; leaving: boolean } {
  const [mounted, setMounted] = useState(on);
  useEffect(() => {
    if (on) return setMounted(true);
    const t = window.setTimeout(() => setMounted(false), ms);
    return () => window.clearTimeout(t);
  }, [on, ms]);
  return { mounted: on || mounted, leaving: !on && mounted };
}

export function Sheet({ family, viewLabel, dimensions, ghost, reducedMotion, placeholder, overlayWidth, photo = null, status, searching = false, children }: Props) {
  const overlaid = Boolean(overlayWidth && children);
  const [sideRef, blockHeight] = useBlockHeight(overlaid);
  const drawn = family.kind !== 'none' && family.kind !== 'photo' ? family : null;
  const canvas = useLinger(searching || drawn !== null, reducedMotion ? 0 : CANVAS_EXIT_MS);
  const lastDrawn = useRef(drawn);
  if (drawn) lastDrawn.current = drawn;
  const side = overlayWidth ? { right: overlayWidth } : undefined;
  return (
    <div className="sheet" style={blockHeight ? { minHeight: Math.ceil(blockHeight) } : undefined}>
      {viewLabel && <div className="sheet-label m">{viewLabel}</div>}
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
      {!searching && family.kind === 'photo' && (
        <div className="sheet-figure" style={side}>
          {photo ? <CataloguePhoto key={photo.src} photo={photo} /> : !status && <Unknown />}
        </div>
      )}
      {status && searching ? (
        <p className="sheet-searching m" role="status" style={side ? { right: side.right } : undefined}>
          {status}
        </p>
      ) : (
        status && (
          <div className="sheet-status" style={side}>
            {status}
          </div>
        )
      )}
      {!searching && family.kind === 'mesh' && family.approximate && !status && <p className="sheet-caption">{APPROXIMATE_CAPTION}</p>}
      {!searching && drawn && !ghost && <div className="sheet-hint">Glisser pour tourner</div>}
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

// A reference photo pinned on the sheet: never measured, never dimensioned. A missing or broken image
// falls back to the « ? ».
function CataloguePhoto({ photo }: { photo: SheetPhoto }) {
  const [failed, setFailed] = useState(false);
  if (failed) return <Unknown />;
  return (
    <figure className="sheet-photo">
      <img src={photo.src} alt={photo.alt} decoding="async" onError={() => setFailed(true)} />
      <figcaption className="sheet-photo-cap m">
        Photo catalogue · <span className="nw">{photo.reference}</span>
      </figcaption>
    </figure>
  );
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
