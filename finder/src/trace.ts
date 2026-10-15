import { NNBSP, formatCount } from './format';
import type { Cluster, Narrowing } from './types';

export interface TraceRow {
  label: string;
  value: string;
  result?: true;
}

function outcome(parts: number): string {
  if (parts === 0) return 'aucune pièce identifiée';
  return parts === 1 ? '1 pièce' : `${parts} pièces distinctes`;
}

export function narrowingTrace(n: Narrowing, cluster: Cluster | null, parts: number): TraceRow[] {
  const rows: TraceRow[] = [
    { label: 'Références catalogue', value: formatCount(n.totalArticles) },
    { label: 'Marques', value: formatCount(n.totalBrands) },
  ];
  if (cluster && parts === 1) {
    const brands = new Set(cluster.variants.map((v) => v.brand)).size;
    rows.push({ label: 'Même pièce', value: `${formatCount(cluster.articleCount)} réf.${NNBSP}·${NNBSP}${formatCount(brands)} marques` });
  }
  if (n.unclassifiedCount > 0) rows.push({ label: 'Non rattachées', value: formatCount(n.unclassifiedCount) });
  rows.push({ label: '→', value: outcome(parts), result: true });
  return rows;
}
