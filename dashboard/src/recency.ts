const RECENCY_KEY = 'mpa.recency';
const CAP = 50;

export function vehicleSegment(iri: string): string {
  return iri.split('/').pop() ?? iri;
}

export function loadRecency(): string[] {
  try {
    const parsed = JSON.parse(localStorage.getItem(RECENCY_KEY) ?? '[]');
    return Array.isArray(parsed) ? parsed.filter((s): s is string => typeof s === 'string') : [];
  } catch {
    return [];
  }
}

export function saveRecency(list: string[]): void {
  localStorage.setItem(RECENCY_KEY, JSON.stringify(list));
}

export function recordVisit(list: string[], iri: string): string[] {
  const seg = vehicleSegment(iri);
  return [seg, ...list.filter((s) => s !== seg)].slice(0, CAP);
}

export function orderByRecency<T extends { id: string }>(items: T[], recency: string[]): T[] {
  const rank = (item: T): number => {
    const i = recency.indexOf(vehicleSegment(item.id));
    return i === -1 ? recency.length : i;
  };
  return items
    .map((item, i) => ({ item, i, rank: rank(item) }))
    // Stable: tie-break on original index so unknowns (equal rank) keep their relative order.
    .sort((a, b) => a.rank - b.rank || a.i - b.i)
    .map((e) => e.item);
}
