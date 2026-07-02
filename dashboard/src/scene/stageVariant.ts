// The Plateau stage look: 'mat' (cutting-mat floor + porcelain car) is the SHIPPED default; 'night'
// (the original tinted-glass/void look) is the explicit opt-out, stored under the same localStorage key
// so no build-time wiring is needed. Read ONCE at mount — there is no live-reactivity; flipping the key
// requires a reload (see the "Plateau: mat/nuit" toggle in AtelierScene, the #/scene dev harness).
//
//   localStorage.setItem('mpa.stage', 'night'); location.reload();   // opt out, back to the night look
//   localStorage.removeItem('mpa.stage'); location.reload();         // back to the shipped default (mat)
//
// A key already storing 'mat' from before this promotion keeps resolving to 'mat' — anything other than
// the literal 'night' does, so no migration step is needed.
export type StageVariant = 'night' | 'mat';

export const STAGE_VARIANT_KEY = 'mpa.stage';

export function readStageVariant(): StageVariant {
  try {
    return window.localStorage.getItem(STAGE_VARIANT_KEY) === 'night' ? 'night' : 'mat';
  } catch {
    return 'mat'; // storage denied (privacy mode, SSR) — falls back to the shipped default
  }
}
