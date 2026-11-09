import type { CSSProperties } from 'react';
import { orbStrokes, type OrbState } from './orb';

const STROKES = orbStrokes(11, 4, 19);

/** The agent's presence: a sphere sketched in a few pen strokes, the meshes' line language. CSS motion only. */
export function Orb({ state, reduced }: { state: OrbState; reduced: boolean }) {
  return (
    <span className={`w-orb is-${state.mode} is-${state.tone}`} data-reduced={reduced ? 'true' : undefined} aria-hidden="true">
      <svg viewBox="-24 -24 48 48" width="44" height="44" fill="none" stroke="currentColor" strokeWidth={1.3} strokeLinecap="round" strokeLinejoin="round">
        <g className="w-orb-body">
          <g className="w-orb-strokes">
            {STROKES.map((d, i) => (
              <path key={i} d={d} pathLength={1} className="w-orb-stroke" style={{ '--i': i } as CSSProperties} />
            ))}
          </g>
        </g>
      </svg>
    </span>
  );
}
