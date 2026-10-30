import { dimText, findDim, threadOf, type DimKey } from './dims';
import { NNBSP, formatCount, formatValue } from './format';
import { pickOe, sameBrand } from './result';
import type { ClusterChoice } from './route';
import type { AgentSelection, Applicability, Cluster, Dimension, Divergence, LinkAlternative, Narrowing, Reference, StoredSelection } from './types';

// `open`: several parts and nobody chose, the first is shown. `answer`: the cote question settled it.
// `user`: picked in the variant table, the user's call, not the catalogue's. `agent`: retained by the
// agent on `basis` (a decoded code de fabrication against per-article breakpoints), an indication.
export type SelectionStatus = 'single' | 'answer' | 'user' | 'agent' | 'open';

// `verdict`: the part the agent retained, kept when the user then shows another row.
export interface Selection {
  cluster: Cluster | null;
  index: number;
  status: SelectionStatus;
  verdict?: AgentSelection | null;
}

export const choiceSelectionId = (choice: ClusterChoice | null): string | null =>
  choice?.by === 'agent' ? choice.selectionId : choice?.by === 'user' ? (choice.retained?.selectionId ?? null) : null;

// A stored selection vouches only for the car, category and product group it was made on.
export function storedVerdict(stored: StoredSelection, here: { carId: number | null; categoryId: number; productGroupId: number | null }): AgentSelection | null {
  if (stored.carId !== here.carId || stored.categoryId !== here.categoryId) return null;
  if (here.productGroupId !== null && stored.productGroupId !== here.productGroupId) return null;
  return { id: stored.id, clusterId: stored.clusterId, basis: stored.basis, setAside: stored.setAside };
}

// `known`: the selection the server vouched for (frame or GET /agent/selections/{id}). An agent route
// it does not back is shown as nobody's choice: the route names ids, never what the agent said.
export function selectVariant(n: Narrowing | null, choice: ClusterChoice | null, known: AgentSelection | null = null): Selection {
  const clusters = n?.clusters ?? [];
  if (clusters.length === 0) return { cluster: null, index: -1, status: 'open' };
  if (clusters.length === 1) return { cluster: clusters[0]!, index: 0, status: 'single' };
  const open: Selection = { cluster: clusters[0]!, index: 0, status: 'open', verdict: null };
  const sid = choiceSelectionId(choice);
  const retainedId = choice?.by === 'agent' ? choice.id : choice?.by === 'user' ? choice.retained?.id : undefined;
  const verdict = known && sid === known.id && retainedId === known.clusterId && clusters.some((c) => c.id === known.clusterId) ? known : null;
  if (choice?.by === 'agent' && !verdict) return open;
  const index = choice ? clusters.findIndex((c) => c.id === choice.id) : -1;
  if (index === -1) return open;
  return { cluster: clusters[index]!, index, status: choice!.by, verdict };
}

export type VariantMark = 'retained' | 'aside' | null;

export const variantMark = (selection: Selection, clusterId: string): VariantMark => (selection.verdict ? (selection.verdict.clusterId === clusterId ? 'retained' : 'aside') : null);

// A row pick is the user's call; the agent's verdict stays on the table, and picking its row returns to it.
export function pickChoice(selection: Selection, clusterId: string): ClusterChoice {
  const v = selection.verdict;
  if (!v) return { id: clusterId, by: 'user' };
  if (v.clusterId === clusterId) return { id: v.clusterId, by: 'agent', selectionId: v.id };
  return { id: clusterId, by: 'user', retained: { id: v.clusterId, selectionId: v.id } };
}

// The cote question stays until something settled the part; the agent's verdict did.
export const questionOpen = (selection: Selection): boolean => !selection.verdict;

// Settled, the retained row is the only one shown; the others wait behind a link. Showing another row
// (a pick, an old link) puts them all back, the checked part is never hidden.
export function hiddenVariantIds(rows: readonly { id: string }[], selection: Selection): string[] {
  const v = selection.verdict;
  if (!v || selection.cluster?.id !== v.clusterId) return [];
  return rows.filter((r) => r.id !== v.clusterId).map((r) => r.id);
}

export function othersLabel(count: number, open: boolean): string {
  if (open) return count === 1 ? 'Masquer l’autre référence' : `Masquer les ${count} autres références`;
  return count === 1 ? 'Voir l’autre référence (ne correspond pas)' : `Voir les ${count} autres références (ne correspondent pas)`;
}

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
  oe: Reference | null;
  representative: Reference;
  articleCount: number;
  differs: VariantCote[];
  breakpoints: ApplicabilityLine[];
  variantNotes: VariantNote[];
}

export interface VariantNote {
  ref: string;
  text: string;
}

const lineText = (l: ApplicabilityLine): string => `${l.label} ${l.values.join(' · ')}`;

const alternativeText = (alt: LinkAlternative, shared: readonly Applicability[]): string => {
  const own = alt.conditions.filter((c) => !c.values.every((v) => shared.some((s) => s.name === c.name && s.values.includes(v))));
  const parts = applicabilityLines(own).map(lineText);
  if (alt.pairedWith.length > 0) parts.push(`avec ${alt.pairedWith.join(' / ')}`);
  return parts.join(', ');
};

// What a variant holds on top of what every variant of its cluster holds on this car.
export function variantNotes(cluster: Cluster): VariantNote[] {
  return cluster.variants.flatMap((v) => {
    const texts = v.applicability.map((alt) => alternativeText(alt, cluster.applicability));
    return texts.length === 0 || texts.includes('') ? [] : [{ ref: `${v.brand} ${v.number}`, text: texts.join(' ou ') }];
  });
}

const measureText = (d: Dimension): string => (d.number !== null ? `${dimText(d)}${d.unit ? `${NNBSP}${d.unit}` : ''}` : formatValue(d.value));

export const splitting = (differsOn: readonly Divergence[]): Divergence[] => differsOn.filter((d) => new Set(d.values.map((v) => v.value)).size > 1);

export function variantRows(n: Narrowing, make: string | null): VariantRow[] {
  const divergences = splitting(n.differsOn);
  const vehicle = n.applicabilityScope === 'vehicle';
  return n.clusters.map((c) => ({
    id: c.id,
    oe: pickOe(c, make, n.clusters),
    representative: c.representative,
    articleCount: c.articleCount,
    differs: divergences.map((d) => {
      const dim = c.dimensions.find((x) => x.key === d.key);
      const raw = d.values.find((v) => v.clusterId === c.id)?.value;
      const text = dim ? measureText(dim) : raw ? `${formatValue(raw)}${d.unit ? `${NNBSP}${d.unit}` : ''}` : '';
      return { key: d.key, label: d.label, text };
    }),
    breakpoints: vehicle ? applicabilityLines(c.applicability) : applicabilityLines(c.applicability).filter((a) => BREAKPOINT.test(a.label)),
    variantNotes: vehicle ? variantNotes(c) : [],
  }));
}

// Under the drawing while several parts are listed and nobody settled it: the table's own count.
// The question card names the cote to answer; this line adds the count and points at the composer.
export function refineHint(n: Narrowing | null, make: string | null, selection: Selection): string | null {
  if (!n || selection.status !== 'open') return null;
  const count = variantRows(n, make).length;
  return count > 1 ? `${formatCount(count)} pièces possibles. Précisez ci-dessous pour trancher.` : null;
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
export function makerOe(cluster: Cluster, make: string | null, parts: readonly Cluster[] = [cluster]): Reference[] {
  const headline = pickOe(cluster, make, parts);
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
