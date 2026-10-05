// The document viewer's pure core (`showDocument`, CONTRACT.md): highlight matching, page layout and
// navigation. No DOM, no pdf.js — DocumentViewer.tsx feeds it text runs and sizes.

// Mirrors `App\Documentation\DocumentationSearcher` so a hit the agent cites is the text the viewer marks.
const STOPWORDS = new Set([
  'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'un', 'une', 'et', 'en', 'au', 'aux', 'a',
  'pour', 'sur', 'par', 'avec', 'dans', 'ou', 'est', 'que', 'qui', 'se', 'sa', 'son', 'ses',
]);

const folded = new Map<string, string>();

/** NFD, strip combining marks, lowercase — one output char per input code unit, so offsets survive. */
export function fold(text: string): string {
  let out = '';
  for (const ch of text) {
    let mapped = folded.get(ch);
    if (mapped === undefined) {
      const stripped = ch.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
      mapped = stripped === '' ? ch : [...stripped][0];
      if (mapped.length !== ch.length) mapped = ch;
      folded.set(ch, mapped);
    }
    out += mapped;
  }
  return out;
}

export function highlightTerms(query: string | undefined | null): string[] {
  if (!query) return [];
  const terms = [...new Set(fold(query).split(/[^\p{L}\p{N}]+/u).filter((t) => t !== ''))].filter((t) => !STOPWORDS.has(t));
  const meaningful = terms.filter((t) => [...t].length >= 2);
  return meaningful.length > 0 ? meaningful : terms;
}

export interface TextPos {
  item: number;
  offset: number;
}

/** One highlighted occurrence, `end` exclusive. May span several text runs. */
export interface Match {
  start: TextPos;
  end: TextPos;
}

/**
 * Every occurrence of any term in a page's text runs (pdf.js `textContentItemsStr`), overlapping hits
 * merged. Runs are joined with no separator, the way pdf.js lays them out, so a word the PDF split
 * across runs still matches.
 */
export function findMatches(items: readonly string[], terms: readonly string[]): Match[] {
  if (terms.length === 0 || items.length === 0) return [];
  const starts: number[] = [];
  let text = '';
  for (const item of items) {
    starts.push(text.length);
    text += item;
  }
  const haystack = fold(text);

  const ranges: [number, number][] = [];
  for (const term of terms) {
    if (term === '') continue;
    for (let at = haystack.indexOf(term); at !== -1; at = haystack.indexOf(term, at + 1)) ranges.push([at, at + term.length]);
  }
  ranges.sort((a, b) => a[0] - b[0] || b[1] - a[1]);

  const merged: [number, number][] = [];
  for (const r of ranges) {
    const last = merged[merged.length - 1];
    if (last && r[0] < last[1]) last[1] = Math.max(last[1], r[1]);
    else merged.push([r[0], r[1]]);
  }

  const locate = (pos: number, isEnd: boolean): TextPos => {
    let item = 0;
    for (let i = 0; i < starts.length; i++) {
      if (starts[i] < pos || (!isEnd && starts[i] === pos && items[i].length > 0)) item = i;
      else if (starts[i] > pos) break;
    }
    return { item, offset: pos - starts[item] };
  };
  return merged.map(([s, e]) => ({ start: locate(s, false), end: locate(e, true) }));
}

export interface Segment {
  start: number;
  end: number;
  match: number;
}

/** Cut matches into per-run segments (`lengths` = each run's length), keyed by run index. */
export function segmentsByItem(matches: readonly Match[], lengths: readonly number[]): Map<number, Segment[]> {
  const out = new Map<number, Segment[]>();
  matches.forEach((m, match) => {
    for (let item = m.start.item; item <= m.end.item; item++) {
      const start = item === m.start.item ? m.start.offset : 0;
      const end = item === m.end.item ? m.end.offset : lengths[item] ?? 0;
      if (end <= start) continue;
      const list = out.get(item) ?? [];
      list.push({ start, end, match });
      out.set(item, list);
    }
  });
  return out;
}

/** Index of the first match whose page is ≥ `page` (pages are 1-based, one entry per match), else the first. */
export function firstMatchAtOrAfter(matchPages: readonly number[], page: number): number {
  if (matchPages.length === 0) return -1;
  const at = matchPages.findIndex((p) => p >= page);
  return at === -1 ? 0 : at;
}

export function clampPage(page: number, total: number): number {
  if (!Number.isFinite(page)) return 1;
  return Math.min(Math.max(1, Math.round(page)), Math.max(1, total));
}

/** Zoom stops, as multiples of fit-width (1 = the page fills the scroller's width). */
export const ZOOM_STOPS = [0.5, 0.67, 0.8, 1, 1.25, 1.5, 2, 3, 4] as const;

export function zoomStep(current: number, direction: 1 | -1): number {
  if (direction > 0) return ZOOM_STOPS.find((z) => z > current + 1e-6) ?? current;
  return [...ZOOM_STOPS].reverse().find((z) => z < current - 1e-6) ?? current;
}

export function fitWidthScale(pageWidth: number, containerWidth: number, gutter: number): number {
  if (pageWidth <= 0 || containerWidth <= gutter) return 1;
  return (containerWidth - gutter) / pageWidth;
}

export interface PageSize {
  width: number;
  height: number;
}

export interface PageLayout {
  tops: number[];
  heights: number[];
  widths: number[];
  total: number;
}

/** Stack the pages (unscaled sizes) at `scale`, `gap` above each page and below the last. */
export function layoutPages(sizes: readonly PageSize[], scale: number, gap: number): PageLayout {
  const tops: number[] = [];
  const heights: number[] = [];
  const widths: number[] = [];
  let y = gap;
  for (const s of sizes) {
    const h = Math.round(s.height * scale);
    tops.push(y);
    heights.push(h);
    widths.push(Math.round(s.width * scale));
    y += h + gap;
  }
  return { tops, heights, widths, total: y };
}

/** The 1-based page under the upper third of the viewport — what « p. N » reads. */
export function pageAt(layout: PageLayout, scrollTop: number, viewportHeight: number): number {
  const probe = scrollTop + viewportHeight / 3;
  let page = 1;
  for (let i = 0; i < layout.tops.length; i++) {
    if (layout.tops[i] <= probe) page = i + 1;
    else break;
  }
  return page;
}

/** 1-based pages intersecting the viewport widened by `overscan` px on both sides. */
export function pagesToRender(layout: PageLayout, scrollTop: number, viewportHeight: number, overscan: number): number[] {
  const from = scrollTop - overscan;
  const to = scrollTop + viewportHeight + overscan;
  const pages: number[] = [];
  for (let i = 0; i < layout.tops.length; i++) {
    if (layout.tops[i] < to && layout.tops[i] + layout.heights[i] > from) pages.push(i + 1);
  }
  return pages;
}

/** Backing-store scale for a canvas: the device pixel ratio, capped so its area stays under `maxPixels`. */
export function outputScale(cssWidth: number, cssHeight: number, dpr: number, maxPixels: number): number {
  const area = cssWidth * cssHeight;
  if (area <= 0) return dpr;
  if (area * dpr * dpr <= maxPixels) return dpr;
  return Math.floor(Math.sqrt(maxPixels / area) * 1000) / 1000;
}

export type ViewerKind = 'pdf' | 'image' | 'other';

export function viewerKind(contentType: string): ViewerKind {
  const type = contentType.toLowerCase();
  if (type === 'application/pdf') return 'pdf';
  if (type.startsWith('image/')) return 'image';
  return 'other';
}
