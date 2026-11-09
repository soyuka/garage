/**
 * Draw positions for sorted tick positions so no two sit closer than `gap`: each cluster is laid out at
 * `gap` spacing centred on its own mean, clamped into [lo, hi], and clusters that then touch are merged.
 * Only the drawing moves; the true reading stays in each tick's accessible name.
 */
export function spreadTicks(xs: number[], gap: number, lo: number, hi: number): number[] {
  const blocks: Array<{ members: number[]; start: number }> = [];
  const place = (members: number[]) => {
    const mean = members.reduce((s, i) => s + xs[i], 0) / members.length;
    const width = (members.length - 1) * gap;
    return Math.min(Math.max(mean - width / 2, lo), Math.max(lo, hi - width));
  };
  xs.forEach((_, i) => {
    let block = { members: [i], start: place([i]) };
    for (;;) {
      const prev = blocks[blocks.length - 1];
      if (!prev || prev.start + (prev.members.length - 1) * gap + gap <= block.start + 1e-9) break;
      blocks.pop();
      const members = [...prev.members, ...block.members];
      block = { members, start: place(members) };
    }
    blocks.push(block);
  });
  const out: number[] = new Array(xs.length);
  for (const b of blocks) b.members.forEach((m, k) => (out[m] = b.start + k * gap));
  return out;
}
