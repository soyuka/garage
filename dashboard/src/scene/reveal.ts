import type { BodyRegion } from './anchors';
import { hasAssembly } from './assembly';
import type { CameraFocus } from './cameraRig';

// ─────────────────────────────────────────────────────────────────────────────
// C4 — the reveal, as state. Pure core: what a `revealAssembly` frame turns into, and when it ends.
//
// A reveal is not a mode. It is a CameraFocus with `mode:'reveal'` plus the diagram to draw, so every
// rule the earned camera already enforces — the intent gate, the mid-drag queue, drag-breaks-hold, the
// burst coalescer — applies to it unchanged and none of them was re-implemented here.
// ─────────────────────────────────────────────────────────────────────────────

export interface RevealState {
  region: BodyRegion;
  /** the parts the answer named; they are CALLED OUT in the diagram, not filtered from it. */
  partKeys: string[];
  /** re-triggers a fresh rise for a repeat region, exactly like PulseRegion / CameraFocus. */
  key: number;
}

/**
 * The reveal a frame asks for, or null to decline. A region with no diagram declines rather than
 * ghosting the shell to show an empty bay: the ghost SUBTRACTS the car, so firing it with nothing to
 * put underneath would leave the operator with strictly less than they had.
 */
export function revealFor(region: BodyRegion, partKeys: string[] | undefined, key: number): RevealState | null {
  if (!hasAssembly(region)) return null;
  return { region, partKeys: partKeys ?? [], key };
}

/** The camera hold a reveal implies — the same shape any other hold uses, in the reveal pose family. */
export function focusForReveal(reveal: RevealState): CameraFocus {
  return { region: reveal.region, key: reveal.key, mode: 'reveal' };
}

/**
 * Does this reveal survive the camera state it now finds itself in? A reveal is anchored to its hold,
 * so the moment the camera stops holding that region — released, dragged away, or sent somewhere else —
 * the diagram has nothing to sit under and must clear. One rule, so there is no way for the shell to
 * stay ghosted after the camera has moved on.
 */
export function revealSurvives(reveal: RevealState | null, focus: CameraFocus | null): boolean {
  if (!reveal) return false;
  return focus != null && focus.mode === 'reveal' && focus.region === reveal.region && focus.key === reveal.key;
}
