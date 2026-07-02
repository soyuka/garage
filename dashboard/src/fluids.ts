import { NBSP, formatAmount } from './format';
import type { SpecNode } from './focus';

// "Capacités fluides" (§5.8 FillVolume ★) — the response→constellation-node mapper. Each entry is a
// fluid capacity (volume + unit) and/or a spec (info string like "ACEA E3|E4|E5", "DOT 4", "R134a").
// Deterministic + pure: the "Capacités fluides" chip GETs /cars/{kType}/fill-volumes and projects these
// through the specs focus mechanism. Catalog-sourced, so every node reads verified (✓).

/** One FillVolume row as the server serves it (server-only shape — not in the frozen types.ts contract). */
export interface FillVolume {
  id?: string | number;
  label?: string | null;
  originalTitle?: string | null;
  volume?: number | string | null;
  unit?: string | null;
  info?: string | null;
}

const UNIT_ABBR: Record<string, string> = {
  liter: 'L',
  liters: 'L',
  litre: 'L',
  litres: 'L',
  l: 'L',
  milliliter: 'ml',
  milliliters: 'ml',
  ml: 'ml',
  gram: 'g',
  grams: 'g',
  g: 'g',
  kilogram: 'kg',
  kilograms: 'kg',
  kg: 'kg',
};

function abbrevUnit(unit?: string | null): string | null {
  if (!unit) return null;
  const key = unit.trim().toLowerCase();
  return key ? UNIT_ABBR[key] ?? unit.trim() : null;
}

function toNumber(v: number | string | null | undefined): number | null {
  if (v == null || v === '') return null;
  const n = typeof v === 'number' ? v : Number.parseFloat(String(v).replace(',', '.'));
  return Number.isFinite(n) ? n : null;
}

export function fluidNodes(vols: FillVolume[]): SpecNode[] {
  const nodes: SpecNode[] = [];
  vols.forEach((v, i) => {
    const amount = toNumber(v.volume);
    const info = v.info?.trim() || undefined;
    const label = (v.label?.trim() || v.originalTitle?.trim() || 'Fluide') as string;
    if (amount != null) {
      const unit = abbrevUnit(v.unit);
      const value = unit ? `${formatAmount(amount)}${NBSP}${unit}` : formatAmount(amount);
      nodes.push({ key: `fluid:${v.id ?? i}`, label, value, verified: true, info });
    } else if (info) {
      // a spec-only entry (brake fluid grade, refrigerant) — the info IS the value.
      nodes.push({ key: `fluid:${v.id ?? i}`, label, value: info, verified: true });
    }
    // neither a volume nor an info → nothing to show, drop it.
  });
  return nodes;
}
