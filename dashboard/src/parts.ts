import type { PlannedPart } from './types';

/**
 * A human search query for a planned part: SKU/brand/label for a catalog part, or
 * viscosity + label + manufacturer standard for a derived fluid line.
 */
export function partSearchQuery(part: PlannedPart): string {
  if (part.spec) {
    return [part.spec.viscosity, part.label, part.spec.standard?.split('|').join(' ')]
      .filter(Boolean)
      .join(' ')
      .trim();
  }
  return [part.ref, part.brand, part.label].filter(Boolean).join(' ').trim();
}

export interface PartShopLink {
  name: string;
  url: string;
}

/** Two parts-shop searches for a planned part, or [] when there is nothing searchable. */
export function partShopLinks(part: PlannedPart): PartShopLink[] {
  const q = partSearchQuery(part);
  if (!q) return [];
  const enc = encodeURIComponent(q);
  return [
    { name: 'Mister-Auto', url: `https://www.mister-auto.com/rechercher/?q=${enc}` },
    // Auto-Doc's search wants '+' for spaces; the rest of the encoding (e.g. %2F) is identical.
    { name: 'Auto-Doc', url: `https://www.auto-doc.fr/search?keyword=${enc.replace(/%20/g, '+')}` },
  ];
}
