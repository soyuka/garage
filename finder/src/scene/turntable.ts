const REVOLUTION_S = 40;
const MAX_FRAME_S = 0.1;

export interface TurntableState {
  reducedMotion: boolean;
  stopped: boolean;
}

// Azimuth to add this frame. A hidden tab resumes with one long frame: clamp it so the part never jumps.
export function turntableStep(deltaS: number, { reducedMotion, stopped }: TurntableState): number {
  if (reducedMotion || stopped) return 0;
  return ((2 * Math.PI) / REVOLUTION_S) * Math.min(deltaS, MAX_FRAME_S);
}
