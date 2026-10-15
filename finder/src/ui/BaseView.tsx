import type { ReactNode } from 'react';
import { baseView, measure, threadSize, type Measure } from '../dims';
import { NNBSP } from '../format';
import type { Dimension, Family } from '../types';

const W = 520;
const H = 232;
const CX = 150;
const CY = 128;
const R_PX = 86;
const LABEL_X = 300;
const SHELF = 14;

const polar = (r: number, deg: number): [number, number] => [CX + r * Math.cos((deg * Math.PI) / 180), CY + r * Math.sin((deg * Math.PI) / 180)];

function Leader({ r, deg, y, children }: { r: number; deg: number; y: number; children: string }) {
  const [x0, y0] = polar(r, deg);
  return (
    <g>
      <path className="bv-thin" d={`M${x0} ${y0}L${LABEL_X - SHELF - 4} ${y}H${LABEL_X - 4}`} markerStart="url(#bv-arrow)" />
      <text className="bv-value" x={LABEL_X} y={y + 4.5}>
        {children}
      </text>
    </g>
  );
}

const ring = (r: number) => `M${CX - r} ${CY}a${r} ${r} 0 1 0 ${2 * r} 0a${r} ${r} 0 1 0 ${-2 * r} 0`;
const diam = (m: Measure) => `Ø${NNBSP}${m.text}`;

function Frame({ title, extent, children }: { title: string; extent: number; children: ReactNode }) {
  return (
    <svg className="bv" viewBox={`0 0 ${W} ${H}`} role="img" aria-label={title}>
      <defs>
        <marker id="bv-arrow" viewBox="0 0 10 6" refX="0" refY="3" markerWidth="9" markerHeight="5.4" orient="auto-start-reverse" markerUnits="userSpaceOnUse">
          <path d="M0 3L10 0V6z" fill="currentColor" />
        </marker>
      </defs>
      <text className="bv-title" x="24" y="28">
        {title}
      </text>
      <path className="bv-chain" d={`M${CX - extent} ${CY}H${CX + extent}M${CX} ${CY - extent}V${CY + extent}`} />
      {children}
    </svg>
  );
}

// Orthographic "VUE A" drawn straight from the cluster fingerprint: every circle is a midpoint, every
// label the same range text as the title block.
export function BaseView({ family, dimensions }: { family: Family; dimensions: readonly Dimension[] }) {
  if (family.kind === 'brake') {
    const outer = measure(dimensions, 'outer');
    if (!outer) return null;
    const thickness = measure(dimensions, 'thickness');
    return (
      <Frame title="VUE A — FACE" extent={R_PX + 10}>
        <path className="bv-visible" d={ring(R_PX)} />
        <Leader r={R_PX} deg={-40} y={62}>
          {diam(outer)}
        </Leader>
        {thickness && (
          <text className="bv-value" x={LABEL_X} y={126.5}>
            {`ép.${NNBSP}${thickness.text}`}
          </text>
        )}
      </Frame>
    );
  }
  if (family.kind !== 'filter') return null;
  const view = baseView(dimensions);
  if (!view) return null;
  const s = (R_PX * 2) / view.outer.mid;
  const sealOut = view.sealOuter ? (view.sealOuter.mid * s) / 2 : null;
  const sealIn = view.sealInner ? (view.sealInner.mid * s) / 2 : null;
  const t = view.thread ? threadSize(view.thread) : null;
  const major = t ? (t.major * s) / 2 : null;
  const minor = t ? ((t.major - 1.0825 * t.pitch) * s) / 2 : null;
  const seal = [view.sealInner, view.sealOuter].filter((m): m is Measure => m !== null).map((m) => m.text).join(`${NNBSP}/${NNBSP}`);

  return (
    <Frame title="VUE A — DESSOUS" extent={R_PX + 10}>
      <path className="bv-visible" d={ring(R_PX)} />
      {sealOut !== null && sealIn !== null && <path className="bv-seal" d={`${ring(sealOut)}${ring(sealIn)}`} fillRule="evenodd" />}
      {sealOut !== null && <path className="bv-visible" d={ring(sealOut)} />}
      {sealIn !== null && <path className="bv-thin" d={ring(sealIn)} />}
      {minor !== null && major !== null && (
        <>
          <path className="bv-visible" d={ring(minor)} />
          <path className="bv-thin" d={`M${CX + major} ${CY}A${major} ${major} 0 1 1 ${CX} ${CY - major}`} />
        </>
      )}
      <Leader r={R_PX} deg={-40} y={58}>
        {diam(view.outer)}
      </Leader>
      {seal && sealOut !== null && (
        <Leader r={sealOut} deg={-6} y={124}>
          {`joint Ø${NNBSP}${seal}`}
        </Leader>
      )}
      {view.thread && (
        <Leader r={minor ?? R_PX * 0.25} deg={38} y={190}>
          {view.thread}
        </Leader>
      )}
    </Frame>
  );
}
