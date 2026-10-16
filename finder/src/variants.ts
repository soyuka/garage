import { dimText, findDim, threadOf, type DimKey } from './dims';
import { NNBSP, formatValue } from './format';
import { pickOe, sameBrand } from './result';
import type { ClusterChoice } from './route';
import type { Applicability, Cluster, Dimension, Divergence, Narrowing, Reference } from './types';

// `open`: several parts and nobody chose, the first is shown. `answer`: the cote question settled it.
// `user`: picked in the variant table, the user's call, not the catalogue's.
export type SelectionStatus = 'single' | 'answer' | 'user' | 'open';

export interface Selection {
  cluster: Cluster | null;
  index: number;
  status: SelectionStatus;
}

export function selectVariant(n: Narrowing | null, choice: ClusterChoice | null): Selection {
  const clusters = n?.clusters ?? [];
  if (clusters.length === 0) return { cluster: null, index: -1, status: 'open' };
  if (clusters.length === 1) return { cluster: clusters[0]!, index: 0, status: 'single' };
  const index = choice ? clusters.findIndex((c) => c.id === choice.id) : -1;
  if (index === -1) return { cluster: clusters[0]!, index: 0, status: 'open' };
  return { cluster: clusters[index]!, index, status: choice!.by };
}

export const variantLetter = (index: number): string => (index < 26 ? String.fromCharCode(65 + index) : String(index + 1));

export interface ApplicabilityLine {
  label: string;
  values: string[];
}

const YEAR_MONTH = /^(\d{4})(0[1-9]|1[0-2])$/;
const DATED = /ann[ée]e|date/i;
const BREAKPOINT = /OPR|organisation|VIN|ch[aâ]ssis|s[ée]rie/i;

export function applicabilityLines(list: readonly Applicability[]): ApplicabilityLine[] {
  return list.map((a) => {
    const name = a.name.replace(/^pour\s+/i, '').replace(/'/g, '’');
    const label = name.charAt(0).toUpperCase() + name.slice(1);
    const sorted = [...new Set(a.values)].sort((x, y) => x.localeCompare(y, 'fr', { numeric: true }));
    const values = DATED.test(label) ? sorted.map((v) => v.replace(YEAR_MONTH, '$2/$1')) : sorted;
    return { label, values };
  });
}

export interface VariantCote {
  key: string;
  label: string;
  text: string;
}

export interface VariantRow {
  id: string;
  letter: string;
  oe: Reference | null;
  representative: Reference;
  articleCount: number;
  differs: VariantCote[];
  breakpoints: ApplicabilityLine[];
}

const measureText = (d: Dimension): string => (d.number !== null ? `${dimText(d)}${d.unit ? `${NNBSP}${d.unit}` : ''}` : formatValue(d.value));

export const splitting = (differsOn: readonly Divergence[]): Divergence[] => differsOn.filter((d) => new Set(d.values.map((v) => v.value)).size > 1);

export function variantRows(n: Narrowing, make: string | null): VariantRow[] {
  const divergences = splitting(n.differsOn);
  return n.clusters.map((c, i) => ({
    id: c.id,
    letter: variantLetter(i),
    oe: pickOe(c, make),
    representative: c.representative,
    articleCount: c.articleCount,
    differs: divergences.map((d) => {
      const dim = c.dimensions.find((x) => x.key === d.key);
      const raw = d.values.find((v) => v.clusterId === c.id)?.value;
      const text = dim ? measureText(dim) : raw ? `${formatValue(raw)}${d.unit ? `${NNBSP}${d.unit}` : ''}` : '';
      return { key: d.key, label: d.label, text };
    }),
    breakpoints: applicabilityLines(c.applicability).filter((a) => BREAKPOINT.test(a.label)),
  }));
}

export interface OtherCote extends VariantCote {
  differs: boolean;
}

const PACKAGING = /emballage/;
const ON_COTES_LINE: DimKey[] = ['outer', 'height', 'thickness', 'sealInner', 'sealOuter'];

// The catalogue cotes `cotesLine` does not print: with several parts they are often what tells them apart.
export function otherDimensions(cluster: Cluster, differsOn: readonly Divergence[]): OtherCote[] {
  const shown = new Set(ON_COTES_LINE.map((k) => findDim(cluster.dimensions, k)?.key).filter((k): k is string => k !== undefined));
  if (threadOf(cluster.dimensions) !== null) shown.add('thread');
  const splits = new Set(splitting(differsOn).map((d) => d.key));
  return cluster.dimensions.filter((d) => !shown.has(d.key) && !PACKAGING.test(d.key)).map((d) => ({ key: d.key, label: d.label, text: measureText(d), differs: splits.has(d.key) }));
}

const foldNumber = (r: Reference): string => r.number.replace(/[^0-9a-z]/gi, '').toUpperCase();

// The carmaker's other OE numbers for this part, spelling variants folded (3812 E0 = 3812.E0 = 3812E0).
export function makerOe(cluster: Cluster, make: string | null): Reference[] {
  const headline = pickOe(cluster, make);
  if (!headline) return [];
  const seen = new Set([foldNumber(headline)]);
  return cluster.oeNumbers.filter((r) => {
    if (!sameBrand(r.brand, headline.brand)) return false;
    const key = foldNumber(r);
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}
