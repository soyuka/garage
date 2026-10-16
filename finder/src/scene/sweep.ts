// The plotter sweep: a part is redrawn by a horizontal pen line climbing the drawing; below the line the
// incoming part, above it the outgoing one. Never more than two layers.
export interface Layer<T> {
  key: string;
  item: T;
}

export interface SweepState<T> {
  shown: Layer<T> | null;
  outgoing: Layer<T> | null;
  // The incoming part is mounted (its mesh loaded): only then does the line move.
  ready: boolean;
  progress: number;
}

export const EMPTY: SweepState<never> = { shown: null, outgoing: null, ready: false, progress: 1 };

interface Change<T> {
  state: SweepState<T>;
  dropped: Layer<T>[];
}

export function present<T>(s: SweepState<T>, layer: Layer<T>): Change<T> {
  if (s.shown?.key === layer.key) return { state: { ...s, shown: layer }, dropped: [] };
  if (!s.shown) return { state: { shown: layer, outgoing: null, ready: false, progress: 0 }, dropped: [] };
  return { state: { shown: layer, outgoing: s.shown, ready: false, progress: 0 }, dropped: s.outgoing ? [s.outgoing] : [] };
}

export function markReady<T>(s: SweepState<T>, key: string): SweepState<T> {
  return s.shown?.key === key && !s.ready ? { ...s, ready: true } : s;
}

export function step<T>(s: SweepState<T>, dt: number, duration: number): Change<T> {
  if (!s.ready || (s.progress >= 1 && !s.outgoing)) return { state: s, dropped: [] };
  const progress = duration <= 0 ? 1 : Math.min(1, s.progress + dt / duration);
  if (progress < 1) return { state: { ...s, progress }, dropped: [] };
  return { state: { ...s, progress: 1, outgoing: null }, dropped: s.outgoing ? [s.outgoing] : [] };
}

export const sweepEase = (t: number): number => (t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2);
