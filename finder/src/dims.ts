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

export interface BaseView {
  outer: Measure;
  sealOuter: Measure | null;
  sealInner: Measure | null;
  thread: string | null;
}

export function baseView(dims: readonly Dimension[]): BaseView | null {
  const outer = measure(dims, 'outer');
  if (!outer) return null;
  return { outer, sealOuter: measure(dims, 'sealOuter'), sealInner: measure(dims, 'sealInner'), thread: threadOf(dims) };
}

const INCH = 25.4;
const round = (n: number): number => Math.round(n * 1e4) / 1e4;

export function threadSize(note: string): { major: number; pitch: number } | null {
  const inch = /^(\d+)\/(\d+)"?\s*-\s*(\d+)/.exec(note.trim());
  if (inch) return { major: round((Number(inch[1]) / Number(inch[2])) * INCH), pitch: round(INCH / Number(inch[3])) };
  const metric = /^M\s*(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)/i.exec(note.trim());
  if (metric) return { major: Number(metric[1]!.replace(',', '.')), pitch: Number(metric[2]!.replace(',', '.')) };
  return null;
}
