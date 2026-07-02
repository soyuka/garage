// PROTOTYPE A/B flag for an alternative Plateau look (cutting-mat floor + porcelain car), gated behind
// localStorage so it never reaches real users and needs no build-time wiring. Read ONCE at mount — there
// is no live-reactivity; flipping the key requires a reload (see the "Plateau: nuit/mat" toggle in
// AtelierScene, the #/scene dev harness). Key absent (the default) is the shipped night-garage look,
// unchanged bit-for-bit.
//
//   localStorage.setItem('mpa.stage', 'mat'); location.reload();   // turn the alt look ON
//   localStorage.removeItem('mpa.stage'); location.reload();       // back to the default night look
export type StageVariant = 'night' | 'mat';

export const STAGE_VARIANT_KEY = 'mpa.stage';

export function readStageVariant(): StageVariant {
  try {
    return window.localStorage.getItem(STAGE_VARIANT_KEY) === 'mat' ? 'mat' : 'night';
  } catch {
    return 'night'; // storage denied (privacy mode, SSR) — never let a dev flag break the stage
  }
}
