// Tick marks for Le Noyau's instrument bezel — the diagnostic-gauge ring around the lens. Pure trig
// (start at 12 o'clock, clockwise), so the bezel reads as a measuring instrument: the body that
// *reads* the car. Major ticks (every `majorEvery`) run longer/brighter.

export interface Tick {
  x1: number;
  y1: number;
  x2: number;
  y2: number;
  major: boolean;
}

export function tickMarks(
  count: number,
  cx: number,
  cy: number,
  rInner: number,
  rOuter: number,
  majorEvery: number,
): Tick[] {
  const ticks: Tick[] = [];
  for (let i = 0; i < count; i++) {
    const angle = (i / count) * 2 * Math.PI - Math.PI / 2; // 0 = 12 o'clock, clockwise
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    ticks.push({
      x1: cx + cos * rInner,
      y1: cy + sin * rInner,
      x2: cx + cos * rOuter,
      y2: cy + sin * rOuter,
      major: i % majorEvery === 0,
    });
  }
  return ticks;
}
