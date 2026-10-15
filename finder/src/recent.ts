const KEY = 'finder.recent';
const MAX = 6;

export interface RecentSearch {
  categoryId: number;
  label: string;
}

type Store = Record<string, RecentSearch[]>;

function read(): Store {
  try {
    const raw = localStorage.getItem(KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : {};
    return typeof parsed === 'object' && parsed !== null ? (parsed as Store) : {};
  } catch {
    return {};
  }
}

export function recentFor(plate: string): RecentSearch[] {
  const list = read()[plate];
  return Array.isArray(list) ? list.filter((r) => typeof r.categoryId === 'number' && typeof r.label === 'string') : [];
}

export function remember(plate: string, search: RecentSearch): RecentSearch[] {
  const store = read();
  const next = [search, ...recentFor(plate).filter((r) => r.categoryId !== search.categoryId)].slice(0, MAX);
  try {
    localStorage.setItem(KEY, JSON.stringify({ ...store, [plate]: next }));
  } catch {
    return next;
  }
  return next;
}

export function seedRecent(plate: string, searches: RecentSearch[]): void {
  if (recentFor(plate).length > 0) return;
  try {
    localStorage.setItem(KEY, JSON.stringify({ ...read(), [plate]: searches.slice(0, MAX) }));
  } catch {
    /* demo convenience only */
  }
}
