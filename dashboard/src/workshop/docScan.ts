import type { DocScanHit, DocumentSpec, Iri, UIAction } from '../types';

/** DESIGN.md § Motion, "Documentation scan": the numbers as shipped. */
export const SCAN = { maxHits: 4, sweepMs: 700, holdMs: 500, budgetMs: 5000 } as const;

/** One hop of the scan: show hit `hit` from `at` ms; `sweep` plays the scan line over its page. */
export interface ScanStep {
  hit: number;
  at: number;
  sweep: boolean;
}

/**
 * The hops for `count` ranked hits: each of the first few gets a sweep and a hold, then the viewer comes
 * back to the best hit and stays there. Reduced motion skips straight to the best hit.
 */
export function scanPlan(count: number, reduced: boolean): ScanStep[] {
  if (count === 0) return [];
  if (reduced) return [{ hit: 0, at: 0, sweep: false }];
  const hop = SCAN.sweepMs + SCAN.holdMs;
  const n = Math.min(count, SCAN.maxHits, Math.floor(SCAN.budgetMs / hop));
  const steps: ScanStep[] = Array.from({ length: n }, (_, i) => ({ hit: i, at: i * hop, sweep: true }));
  if (n > 1) steps.push({ hit: 0, at: n * hop, sweep: false });
  return steps;
}

/** The step showing `elapsed` ms into the scan; -1 when there is nothing to show. */
export function scanCursor(plan: ScanStep[], elapsed: number): number {
  let at = -1;
  plan.forEach((s, i) => {
    if (s.at <= elapsed) at = i;
  });
  return at;
}

/** When the scan has nothing left to play. */
export function scanEndsAt(plan: ScanStep[]): number {
  const last = plan[plan.length - 1];
  if (!last) return 0;
  return last.at + (last.sweep ? SCAN.sweepMs + SCAN.holdMs : 0);
}

export function specForHit(hit: DocScanHit, query: string): DocumentSpec {
  return { url: hit.url, title: hit.title, contentType: hit.contentType, filename: hit.filename, pagesUrl: hit.pagesUrl, page: hit.page, highlight: query };
}

export type ScanCaption = { kind: 'position'; query: string; position: number; total: number } | { kind: 'count'; query: string; count: number } | { kind: 'none'; query: string };

export function scanCaption(scan: { query: string; hits: DocScanHit[] }, plan: ScanStep[], cursor: number): ScanCaption {
  if (scan.hits.length === 0) return { kind: 'none', query: scan.query };
  if (!plan.some((s) => s.sweep)) return { kind: 'count', query: scan.query, count: scan.hits.length };
  const total = plan.filter((s) => s.sweep).length;
  return { kind: 'position', query: scan.query, position: (plan[cursor]?.hit ?? 0) + 1, total };
}

type ScanFrame = Extract<UIAction, { type: 'documentScan' }>;

/** A scan is about one car: drive it in first when another is on screen; the staging gate holds the scan until it arrives. */
export function scanIntents(frame: ScanFrame, shownIri: Iri | null): UIAction[] {
  return frame.vehicle === shownIri ? [frame] : [{ type: 'selectVehicle', id: frame.vehicle }, frame];
}
