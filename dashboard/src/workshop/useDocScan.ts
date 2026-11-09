import { useEffect, useMemo, useState } from 'react';
import type { PdfScanHop } from '../scene/documentPanes';
import type { DocumentSpec } from '../types';
import { scanCaption, scanPlan, specForHit, type ScanCaption } from './docScan';
import type { DocScan } from './view';

export interface DocScanPlay {
  /** the document the current hop shows; null on a no-match */
  doc: DocumentSpec | null;
  hop: PdfScanHop | null;
  caption: ScanCaption;
}

/** Plays a scan's hops on a clock: the sequencing lives in `docScan.ts`, this only advances the cursor. */
export function useDocScan(scan: DocScan | null, reduced: boolean): DocScanPlay | null {
  const plan = useMemo(() => (scan ? scanPlan(scan.hits.length, reduced) : []), [scan, reduced]);
  const [at, setAt] = useState<{ key: number; cursor: number } | null>(null);

  useEffect(() => {
    if (!scan) return;
    setAt({ key: scan.key, cursor: plan.length > 0 ? 0 : -1 });
    const ids = plan.slice(1).map((step, i) => window.setTimeout(() => setAt({ key: scan.key, cursor: i + 1 }), step.at));
    return () => ids.forEach((id) => window.clearTimeout(id));
  }, [scan, plan]);

  if (!scan) return null;
  const cursor = at?.key === scan.key ? at.cursor : plan.length > 0 ? 0 : -1;
  const step = plan[cursor];
  const hit = step ? scan.hits[step.hit] : undefined;
  return {
    doc: hit ? specForHit(hit, scan.query) : null,
    hop: hit && step ? { page: hit.page, seq: cursor, sweep: step.sweep } : null,
    caption: scanCaption(scan, plan, cursor),
  };
}
