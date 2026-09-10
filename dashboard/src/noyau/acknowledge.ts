// The line spoken the instant a turn is sent (Atelier.tsx `send()`), before any real step has come
// back — a fixed sentence read the same way on every turn stops sounding like an acknowledgement and
// starts sounding like a loading spinner. Pure and clock-free: the only state that matters is which
// index spoke last, kept by the caller.

/**
 * Draw one line from `pool`, never the one at `last` (when there are at least two to choose from).
 * `rand` is injected — the caller passes `Math.random`, tests pass a fixed sequence.
 */
export function pickAcknowledgement(
  pool: readonly string[],
  last: number | null,
  rand: () => number,
): { index: number; text: string } {
  if (pool.length <= 1) return { index: 0, text: pool[0] };

  let index = Math.floor(rand() * pool.length);
  if (index === last) index = (index + 1) % pool.length;

  return { index, text: pool[index] };
}
