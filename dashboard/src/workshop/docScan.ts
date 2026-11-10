import { findMatches } from '../scene/documentViewer';
import type { DocScanHit, DocumentSpec, Iri, UIAction } from '../types';

/** DESIGN.md § Motion, "Documentation search log": the numbers as shipped; each duration is a `--w-dur-*` token. */
export const SEARCH_LOG = { maxHits: 6, typeCharMs: 30, typeMaxMs: 900, sweepMs: 700, decodeMs: 350, staggerMs: 60, settleMs: 400 } as const;

/** One skeleton block of the box page, in percent of the page box. */
export interface PageBlock {
  kind: 'title' | 'line' | 'figure' | 'table';
  x: number;
  y: number;
  w: number;
  h: number;
}

const line = (y: number, w: number): PageBlock => ({ kind: 'line', x: 8, y, w, h: 3 });

export const PAGE_SKELETON: readonly PageBlock[] = [
  { kind: 'title', x: 8, y: 5, w: 52, h: 4 },
  line(13, 84),
  line(18, 76),
  line(23, 82),
  line(28, 58),
  { kind: 'figure', x: 8, y: 34, w: 40, h: 22 },
  { kind: 'table', x: 52, y: 34, w: 40, h: 22 },
  line(61, 84),
  line(66, 70),
  line(71, 80),
  line(76, 64),
  line(83, 82),
  line(88, 52),
];

const LINES = PAGE_SKELETON.filter((b) => b.kind === 'line');
export const LINE_COUNT = LINES.length;

/** The text line hit `index` of `count` lights: spread evenly down the page, best hit on top. */
export function hitSlot(index: number, count: number): number {
  return Math.min(LINE_COUNT - 1, Math.max(0, Math.round(((index + 0.5) * LINE_COUNT) / count - 0.5)));
}

export function hitBoxes(count: number): PageBlock[] {
  const n = Math.min(count, SEARCH_LOG.maxHits);
  return Array.from({ length: n }, (_, i) => LINES[hitSlot(i, n)]);
}

export function typingMs(query: string, reduced: boolean): number {
  return reduced ? 0 : Math.min([...query].length * SEARCH_LOG.typeCharMs, SEARCH_LOG.typeMaxMs);
}

// `--w-ease-out` in tokens.css: the sweep line rides it, so the boxes must light on the same curve.
const EASE = [0.16, 1, 0.3, 1] as const;

function bezier(s: number, p1: number, p2: number): number {
  const u = 1 - s;
  return 3 * u * u * s * p1 + 3 * u * s * s * p2 + s * s * s;
}

/** The fraction of the sweep's duration at which the eased line reaches `y` (0 top, 1 bottom). */
export function sweepCrossFraction(y: number): number {
  if (y <= 0) return 0;
  let lo = 0;
  let hi = 1;
  for (let i = 0; i < 40; i++) {
    const mid = (lo + hi) / 2;
    if (bezier(mid, EASE[1], EASE[3]) < y) lo = mid;
    else hi = mid;
  }
  return bezier(hi, EASE[0], EASE[2]);
}

export interface SearchTimeline {
  typeMs: number;
  sweepAt: number;
  /** when each hit's box lights and its log row appears, ms from the search's arrival */
  lights: number[];
  doneAt: number;
}

export function searchTimeline(query: string, count: number, reduced: boolean): SearchTimeline {
  const boxes = hitBoxes(count);
  if (reduced) return { typeMs: 0, sweepAt: 0, lights: boxes.map(() => 0), doneAt: 0 };
  const typeMs = typingMs(query, false);
  const lights: number[] = [];
  boxes.forEach((box, i) => {
    const crossed = Math.round(typeMs + SEARCH_LOG.sweepMs * sweepCrossFraction(box.y / 100));
    lights.push(i === 0 ? crossed : Math.max(crossed, lights[i - 1] + SEARCH_LOG.staggerMs));
  });
  const last = lights[lights.length - 1];
  return { typeMs, sweepAt: typeMs, lights, doneAt: Math.max(typeMs + SEARCH_LOG.sweepMs, last === undefined ? 0 : last + SEARCH_LOG.decodeMs) };
}

const GLYPHS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789·/-';

/** `final` resolving left to right at `progress` (0..1): unresolved characters are random glyphs, spaces stay spaces. */
export function decodeText(final: string, progress: number, rand: () => number): string {
  const chars = [...final];
  const resolved = Math.floor(Math.min(1, Math.max(0, progress)) * chars.length);
  return chars.map((ch, i) => (i < resolved || /\s/.test(ch) ? ch : GLYPHS[Math.floor(rand() * GLYPHS.length) % GLYPHS.length])).join('');
}

/** « 21A-6 · Capacité en huile 2,4… »: the page, then the snippet cut around its first matched term, `max` characters in all. */
export function hitText(label: string, snippet: string, terms: readonly string[], max: number): string {
  const head = `${label} · `;
  const room = Math.max(1, max - head.length);
  const text = snippet.replace(/^…|…$/g, '').replace(/\s+/g, ' ').trim();
  let start = 0;
  const first = findMatches([text], terms)[0];
  if (first && first.start.offset + 8 > room) {
    start = Math.max(0, first.start.offset - 8);
    const space = text.lastIndexOf(' ', first.start.offset - 1);
    if (space >= start) start = space + 1;
  }
  let body = text.slice(start);
  if (start > 0) body = `…${body}`;
  if (body.length > room) body = `${body.slice(0, room - 1)}…`;
  return head + body;
}

export interface MarkedSegment {
  text: string;
  mark: boolean;
}

/** `text` split on the query terms, folded like the API searcher (case, accents). */
export function markTerms(text: string, terms: readonly string[]): MarkedSegment[] {
  const matches = findMatches([text], terms);
  if (matches.length === 0) return [{ text, mark: false }];
  const out: MarkedSegment[] = [];
  let at = 0;
  for (const m of matches) {
    if (m.start.offset > at) out.push({ text: text.slice(at, m.start.offset), mark: false });
    out.push({ text: text.slice(m.start.offset, m.end.offset), mark: true });
    at = m.end.offset;
  }
  if (at < text.length) out.push({ text: text.slice(at), mark: false });
  return out;
}

export function specForHit(hit: DocScanHit, highlight?: string): DocumentSpec {
  const spec: DocumentSpec = { url: hit.url, title: hit.title, contentType: hit.contentType, filename: hit.filename, pagesUrl: hit.pagesUrl, page: hit.page };
  if (highlight) spec.highlight = highlight;
  return spec;
}

/** The newest logged hit on `url` at `page` (a showDocument's target), or null. */
export function findLoggedHit(searches: readonly { key: number; hits: readonly DocScanHit[] }[], url: string, page: number | undefined): { key: number; hit: number } | null {
  for (let s = searches.length - 1; s >= 0; s--) {
    const at = searches[s].hits.findIndex((h) => h.url === url && h.page === (page ?? 1));
    if (at !== -1) return { key: searches[s].key, hit: at };
  }
  return null;
}

type ScanFrame = Extract<UIAction, { type: 'documentScan' }>;

/** A scan is about one car: drive it in first when another is on screen; the staging gate holds the scan until it arrives. */
export function scanIntents(frame: ScanFrame, shownIri: Iri | null): UIAction[] {
  return frame.vehicle === shownIri ? [frame] : [{ type: 'selectVehicle', id: frame.vehicle }, frame];
}
