import { dimText, findDim, threadOf } from './dims';
import { NNBSP } from './format';
import { foldText } from './search';
import type { Cluster, Dimension, Narrowing, Reference, Variant } from './types';

export const sameBrand = (a: string, b: string): boolean => foldText(a).replace(/[^a-z0-9]/g, '') === foldText(b).replace(/[^a-z0-9]/g, '');

export function pickOe(cluster: Cluster, make: string | null): Reference | null {
  const own = make ? cluster.oeNumbers.find((r) => sameBrand(r.brand, make)) : undefined;
  return own ?? cluster.oeNumbers[0] ?? null;
}

export function rankEquivalents(cluster: Cluster, limit = 6): { shown: Variant[]; otherBrands: number } {
  const ranked = cluster.variants.map((v, i) => ({ v, i })).sort((a, b) => a.v.tier - b.v.tier || a.i - b.i);
  const firstPerBrand: Variant[] = [];
  const seen = new Set<string>();
  for (const { v } of ranked) {
    const key = foldText(v.brand);
    if (seen.has(key)) continue;
    seen.add(key);
    firstPerBrand.push(v);
  }
  const shown = firstPerBrand.slice(0, limit);
  return { shown, otherBrands: firstPerBrand.length - shown.length };
}

export function copyText(oe: Reference | null, fallback: Reference | null): string {
  return (oe ?? fallback)?.number ?? '';
}

export function cotesLine(dims: readonly Dimension[]): string | null {
  const parts: string[] = [];
  const outer = findDim(dims, 'outer');
  const height = findDim(dims, 'height');
  const thickness = findDim(dims, 'thickness');
  const thread = threadOf(dims);
  const seal = [findDim(dims, 'sealInner'), findDim(dims, 'sealOuter')].filter((d): d is Dimension => d !== undefined).map(dimText);
  if (outer) parts.push(`Ø${NNBSP}${dimText(outer)}`);
  if (height) parts.push(`H${NNBSP}${dimText(height)}`);
  if (thickness) parts.push(`ép.${NNBSP}${dimText(thickness)}`);
  if (thread) parts.push(thread);
  if (seal.length > 0) parts.push(`joint Ø${NNBSP}${seal.join('/')}`);
  if (parts.length === 0) return null;
  const hasLength = outer || height || thickness || seal.length > 0;
  return hasLength ? `${parts.join(' · ')}${NNBSP}mm` : parts.join(' · ');
}

const DEGRADED: [RegExp, string][] = [
  [/no physical criteria/, 'Aucune cote publiée pour les distinguer : liste par marque.'],
  [/criteria unavailable/, 'Cotes non chargées : affinage incomplet.'],
  [/cross-references unavailable/, 'Réf. OE et une partie des équivalences manquantes.'],
  [/vehicle makers unavailable/, 'Réf. OE peut-être incomplètes.'],
];

export function degradedMessage(reason: string): string {
  const parts = reason.split(';').map((r) => r.trim());
  const french = parts.map((p) => DEGRADED.find(([re]) => re.test(p))?.[1]).filter((m): m is string => m !== undefined);
  if (french.length === parts.length && french.length > 0) return french.join(' ');
  return [...french, 'Réponse partielle du catalogue (quota ou panne).'].join(' ');
}

// A restored route that names no product group on a category holding several has nothing to show: the
// search goes back to the agent, which picks the group. No group picker for the user.
export function relaunchText(n: Narrowing, input: string | null, categoryLabel: string): string | null {
  if (!n.groupRequired) return null;
  const typed = input?.trim() ?? '';
  return typed !== '' ? typed : categoryLabel;
}
