import { CUES } from '../tokens/audition';
import type { Cue } from './cues';

export interface CuePalette {
  /** A cue with no file is silent in this palette. */
  url: Partial<Record<Cue, string>>;
  loop: { afterMs: number; fadeInMs: number; fadeOutMs: number; thinAfterMs: number; thinGain: number };
  /** Per-cue window inside which the same cue does not sound twice. */
  repeatMs?: Partial<Record<Cue, number>>;
}

const SPATIAL = '/audio/spatial-2026-09-11';

export const SPATIAL_PALETTE: CuePalette = {
  url: Object.fromEntries(CUES.names.filter((c) => c !== 'point').map((c) => [c, `${SPATIAL}/${c}.wav`])),
  loop: { afterMs: CUES.loopAfterMs, fadeInMs: CUES.loopFadeSeconds * 1000, fadeOutMs: 60, thinAfterMs: Infinity, thinGain: 1 },
};

const WORKSHOP = '/audio/workshop-2026-11';

// Generated with ElevenLabs sound effects (public/audio/workshop-2026-11/SOURCES.md).
export const WORKSHOP_PALETTE: CuePalette = {
  url: {
    select: `${WORKSHOP}/select.mp3`,
    point: `${WORKSHOP}/point.mp3`,
    card: `${WORKSHOP}/proposal.mp3`,
    blocked: `${WORKSHOP}/error.mp3`,
    complete: `${WORKSHOP}/settle.mp3`,
    working: `${WORKSHOP}/waiting.mp3`,
  },
  loop: { afterMs: 1200, fadeInMs: 600, fadeOutMs: 300, thinAfterMs: 30_000, thinGain: 0.5 },
  // A panel the agent raises and the ring it moves on the car land ~800 ms apart: one tick, not two.
  repeatMs: { point: 1200 },
};
