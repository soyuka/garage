import { Component, lazy, Suspense, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import type { Dimension, Family } from '../types';
import type { Focus } from '../scene/Blueprint';

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
  children?: ReactNode;
}

function extentFor(family: Family, dims: readonly Dimension[]): number {
  if (family.kind !== 'filter') return 170;
  const measured = (re: RegExp, fallback: number) => dims.find((d) => re.test(d.key) && d.number !== null)?.number ?? fallback;
  return Math.max(measured(/^hauteur(_1)?$/, 69) + 40, measured(/^diametre(_exterieur|_1)?$/, 70) + 70);
}

export function Sheet({ family, viewLabel, dimensions, ghost, reducedMotion, placeholder, overlayWidth, status, children }: Props) {
  const ref = useRef<HTMLDivElement>(null);
  const [focus, setFocus] = useState<Focus | null>(null);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const measure = () => {
      const { width, height } = el.getBoundingClientRect();
      setFocus(overlayWidth ? { x: Math.max((width - overlayWidth) / 2, width * 0.25), y: height / 2 } : null);
    };
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [overlayWidth]);

  return (
    <div className="sheet" ref={ref}>
      <div className="sheet-label m">{viewLabel}</div>
      {family.kind === 'none' ? (
        <div className="sheet-placeholder" style={overlayWidth ? { right: overlayWidth } : undefined}>
          {placeholder}
        </div>
      ) : (
        <div className="sheet-canvas">
          <SceneBoundary fallback={<div className="sheet-placeholder"><p className="sheet-message">La vue technique n’a pas pu être chargée.</p></div>}>
            <Suspense fallback={null}>
              <Blueprint family={family} dimensions={dimensions} ghost={ghost} reducedMotion={reducedMotion} focus={focus} extent={extentFor(family, dimensions)} />
            </Suspense>
          </SceneBoundary>
        </div>
      )}
      {status && (
        <div className="sheet-status" style={overlayWidth ? { right: overlayWidth } : undefined}>
          {status}
        </div>
      )}
      {family.kind !== 'none' && !ghost && <div className="sheet-hint">Glisser pour tourner · molette pour zoomer</div>}
      {children}
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
