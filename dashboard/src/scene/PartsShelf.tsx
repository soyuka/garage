import type { JSX } from 'react';
import type { FluidSpec, PlannedPart } from '../types';
import { formatEuros, NBSP } from '../format';
import './partsshelf.css';

// Parts-as-objects (DESIGN-VISION §2): each part on a lit brushed-metal pedestal, its TecDoc ref
// etched in mono beneath. 2D DOM stand-in for the Stage-D part meshes. A part is NOT a signal, so no
// signal colour — the object's SHAPE encodes its category (grayscale-legible), the ref/brand/price/qty
// are read verbatim from props. A ref-less fluid line renders its honest spec, never a fabricated ref.

export type PartCategory = 'oil' | 'filter' | 'brake' | 'tyre' | 'battery' | 'generic';

/** Category from the part's words — filtre/filter wins BEFORE oil (an oil filter is a filter). */
export function partCategory(part: PlannedPart): PartCategory {
  const hay = `${part.ref ?? ''} ${part.label ?? ''} ${part.brand ?? ''}`.toLowerCase();
  if (hay.includes('filtre') || hay.includes('filter')) return 'filter';
  if (hay.includes('huile') || hay.includes('oil')) return 'oil';
  if (hay.includes('frein') || hay.includes('brake') || hay.includes('disque') || hay.includes('plaquette')) return 'brake';
  if (hay.includes('pneu') || hay.includes('tyre') || hay.includes('tire')) return 'tyre';
  if (hay.includes('batterie') || hay.includes('battery')) return 'battery';
  return 'generic';
}

function isFluidLine(part: PlannedPart): boolean {
  return !!part.spec && !part.ref;
}

function specRows(spec: FluidSpec): string[] {
  const rows: string[] = [];
  if (spec.viscosity) rows.push(spec.viscosity);
  if (spec.standard) rows.push(spec.standard.split('|').join(' / '));
  if (spec.capacity) rows.push(`${spec.capacity}${spec.unit ? NBSP + spec.unit : ''}`);
  return rows;
}

const GLYPH: Record<PartCategory, JSX.Element> = {
  oil: (
    <svg viewBox="0 0 46 56" fill="none" stroke="currentColor" strokeWidth={1.6} aria-hidden="true">
      <path d="M19 9h8v5l4 3v31a3 3 0 0 1-3 3H18a3 3 0 0 1-3-3V20l4-3z" />
      <path d="M19 9V5h8v4" />
      <line x1="15" y1="30" x2="31" y2="30" />
    </svg>
  ),
  filter: (
    <svg viewBox="0 0 46 56" fill="none" stroke="currentColor" strokeWidth={1.6} aria-hidden="true">
      <rect x="13" y="12" width="20" height="32" rx="4" />
      <line x1="13" y1="20" x2="33" y2="20" />
      <line x1="13" y1="28" x2="33" y2="28" />
      <line x1="13" y1="36" x2="33" y2="36" />
      <rect x="19" y="8" width="8" height="4" rx="1" />
    </svg>
  ),
  brake: (
    <svg viewBox="0 0 46 56" fill="none" stroke="currentColor" strokeWidth={1.6} aria-hidden="true">
      <circle cx="23" cy="28" r="15" />
      <circle cx="23" cy="28" r="6" />
      <line x1="23" y1="13" x2="23" y2="19" />
      <line x1="23" y1="37" x2="23" y2="43" />
      <line x1="8" y1="28" x2="14" y2="28" />
      <line x1="32" y1="28" x2="38" y2="28" />
    </svg>
  ),
  tyre: (
    <svg viewBox="0 0 46 56" fill="none" stroke="currentColor" strokeWidth={1.6} aria-hidden="true">
      <circle cx="23" cy="28" r="15" />
      <circle cx="23" cy="28" r="8" />
      <line x1="23" y1="13" x2="23" y2="20" />
      <line x1="23" y1="36" x2="23" y2="43" />
      <line x1="8" y1="28" x2="15" y2="28" />
      <line x1="31" y1="28" x2="38" y2="28" />
      <line x1="12" y1="17" x2="17" y2="22" />
      <line x1="29" y1="34" x2="34" y2="39" />
    </svg>
  ),
  battery: (
    <svg viewBox="0 0 46 56" fill="none" stroke="currentColor" strokeWidth={1.6} aria-hidden="true">
      <rect x="11" y="18" width="24" height="26" rx="2" />
      <rect x="15" y="13" width="6" height="5" rx="1" />
      <rect x="25" y="13" width="6" height="5" rx="1" />
      <line x1="16" y1="26" x2="22" y2="26" />
      <line x1="19" y1="23" x2="19" y2="29" />
      <line x1="24" y1="26" x2="30" y2="26" />
    </svg>
  ),
  generic: (
    <svg viewBox="0 0 46 56" fill="none" stroke="currentColor" strokeWidth={1.6} aria-hidden="true">
      <path d="M23 11l12 6v18l-12 6-12-6V17z" />
      <path d="M11 17l12 6 12-6" />
      <line x1="23" y1="23" x2="23" y2="41" />
    </svg>
  ),
};

function Pedestal({ part, index }: { part: PlannedPart; index: number }): JSX.Element {
  const fluid = isFluidLine(part);
  const category = partCategory(part);
  const qty = part.qty ?? 1;

  return (
    <li className="at-pedestal">
      <div className="at-pedestal-stage">
        <span className="at-pedestal-object">{GLYPH[category]}</span>
      </div>
      <span className="at-pedestal-plinth" aria-hidden="true" />

      {fluid ? (
        <div className="at-pedestal-spec">
          {specRows(part.spec as FluidSpec).map((row, i) => (
            <span className="at-pedestal-spec-row" key={i}>
              {row}
            </span>
          ))}
        </div>
      ) : (
        <>
          <span className="at-pedestal-ref">{part.ref || part.label || `Pièce ${index + 1}`}</span>
          {part.brand && <span className="at-pedestal-brand">{part.brand}</span>}
          {(part.priceCents != null || qty > 1) && (
            <span className="at-pedestal-meta">
              {part.priceCents != null && <span>{formatEuros(part.priceCents)}</span>}
              {qty > 1 && <span className="at-pedestal-qty">×{qty}</span>}
            </span>
          )}
        </>
      )}
    </li>
  );
}

export function PartsShelf({ parts }: { parts: PlannedPart[] }): JSX.Element {
  return (
    <section className="at-shelf" aria-label="Établi — pièces">
      <h3 className="at-shelf-title">Établi</h3>
      {parts.length === 0 ? (
        <p className="at-shelf-empty">Aucune pièce sur l'établi.</p>
      ) : (
        <ul className="at-shelf-rail">
          {parts.map((part, i) => (
            <Pedestal part={part} index={i} key={part.id ?? `${part.ref}-${i}`} />
          ))}
        </ul>
      )}
    </section>
  );
}
