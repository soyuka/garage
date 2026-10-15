import type { Category } from './types';

export function foldText(s: string): string {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[’`´]/g, "'")
    .toLowerCase()
    .trim();
}

export function searchCategories(categories: readonly Category[], query: string, limit = 8): Category[] {
  const q = foldText(query);
  if (q === '') return [];
  const words = q.split(/\s+/);
  const scored: { c: Category; score: number }[] = [];
  for (const c of categories) {
    if (!c.leaf) continue;
    const label = foldText(c.label);
    const haystack = `${foldText(c.path)} ${foldText(c.name)}`;
    if (!words.every((w) => haystack.includes(w))) continue;
    let score = 3;
    if (label.startsWith(q)) score = 0;
    else if (words.every((w) => label.includes(w))) score = 1;
    else if (foldText(c.name).includes(q)) score = 2;
    scored.push({ c, score });
  }
  return scored
    .sort((a, b) => a.score - b.score || a.c.path.length - b.c.path.length)
    .slice(0, limit)
    .map((s) => s.c);
}
