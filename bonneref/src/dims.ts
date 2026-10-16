import { formatRange, formatValue } from './format';
import type { Dimension } from './types';

const KEYS = {
  outer: /^diametre(_exterieur|_1)?$/,
  height: /^hauteur(_1)?$/,
  thickness: /^epaisseur/,
  sealInner: /^diametre_interieur_de_la_bague_d_etancheite$/,
  sealOuter: /^diametre_exterieur_de_la_bague_d_etancheite$/,
} as const;

export type DimKey = keyof typeof KEYS;

export interface Measure {
  mid: number;
  text: string;
}

export function findDim(dims: readonly Dimension[], key: DimKey): Dimension | undefined {
  return dims.find((d) => !d.info && KEYS[key].test(d.key) && d.number !== null && d.number > 0);
}

export const dimText = (d: Dimension): string => (d.min !== null && d.max !== null ? formatRange(d.min, d.max) : formatValue(d.value));

export const dimMid = (d: Dimension): number | null => (d.min !== null && d.max !== null ? (d.min + d.max) / 2 : d.number);

export function measure(dims: readonly Dimension[], key: DimKey): Measure | null {
  const d = findDim(dims, key);
  const mid = d ? dimMid(d) : null;
  return d && mid !== null && mid > 0 ? { mid, text: dimText(d) } : null;
}

export const threadOf = (dims: readonly Dimension[]): string | null => dims.find((d) => !d.info && d.key === 'thread')?.value ?? null;
