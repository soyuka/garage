import { dimText, findDim, threadOf } from './dims';
import { NNBSP } from './format';
import { foldText } from './search';
import type { Cluster, Dimension, Reference, Variant } from './types';

const sameBrand = (a: string, b: string): boolean => foldText(a).replace(/[^a-z0-9]/g, '') === foldText(b).replace(/[^a-z0-9]/g, '');

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

const refText = (r: Reference): string => `${r.brand} ${r.number}`;

export function copyText(oe: Reference | null, top: Variant | null): string {
  return [oe, top].filter((r): r is Reference => r !== null).map(refText).join(' · ');
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
  [/no physical criteria/, 'Aucune cote publiée ne permet de distinguer ces pièces : liste classée par marque.'],
  [/criteria unavailable/, 'Les cotes des pièces n’ont pas pu être lues : affinage incomplet.'],
  [/cross-references unavailable/, 'Les références OE et une partie des équivalences manquent.'],
  [/vehicle makers unavailable/, 'Les références OE peuvent être incomplètes.'],
];

export function degradedMessage(reason: string): string {
  const parts = reason.split(';').map((r) => r.trim());
  const french = parts.map((p) => DEGRADED.find(([re]) => re.test(p))?.[1]).filter((m): m is string => m !== undefined);
  if (french.length === parts.length && french.length > 0) return french.join(' ');
  return [...french, 'Le catalogue a répondu partiellement (quota ou panne).'].join(' ');
}

// Synthetic demo frames (DÉMO-A/B) are not catalogue data; recorded fixtures are real TecDoc answers.
export function sourceLabel({ demo, synthetic }: { demo: boolean; synthetic: boolean }): string {
  return demo && synthetic ? 'Démo (données fictives)' : 'TecDoc';
}
