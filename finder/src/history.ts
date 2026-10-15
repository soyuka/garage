import { vehicleShortName } from './garage';
import { outcomeOf, type JournalRow } from './journal';
import { parseRoute, routeToHash } from './route';
import type { Vehicle } from './types';

const MAX = 10;

export interface HistoryEntry {
  id: string;
  // Every journal row that restores to this entry (older duplicates included): hiding it hides them all.
  ids: string[];
  hash: string;
  input: string;
  vehicle: { plate: string | null; name: string | null };
  outcome: { label: string; tone: 'ok' | 'warn' | 'alarm' };
  startedAt: string;
}

// "Make Model engine", else the bare model; with neither, the plate (rendered by the row itself), else the kType.
function vehicleName(row: JournalRow): string | null {
  const v = row.vehicle;
  if (!v) return null;
  const shape: Vehicle = { plate: v.plate, make: v.make, model: v.model, modelEn: null, version: null, engineCode: null, powerHp: null, firstRegistration: null, energy: null, vin: null, carId: v.carId, carType: v.carType === 'CV' ? 'CV' : 'PC' };
  const name = vehicleShortName(shape) ?? v.model ?? (v.plate === null && v.carId !== null ? `kType ${v.carId}` : null);
  return name === null ? null : [name, v.engine].filter(Boolean).join(' ');
}

// A page opened by URL journals the bare category id as its input: never show that.
const label = (row: JournalRow): string => row.categoryLabel ?? (/^\d+$/.test(row.instruction.trim()) ? '' : row.instruction);

// The home page's recent searches: journal rows the server could give a route, newest first, one per
// route. The route is re-parsed so only a hash the page itself would produce is ever followed.
export function historyEntries(rows: readonly JournalRow[]): HistoryEntry[] {
  const seen = new Map<string, HistoryEntry>();
  const out: HistoryEntry[] = [];
  for (const row of [...rows].sort((a, b) => Date.parse(b.startedAt) - Date.parse(a.startedAt))) {
    if (row.route === null) continue;
    const route = parseRoute(`#/${row.route}`);
    if (route.view === 'entry') continue;
    const hash = routeToHash(route);
    const known = seen.get(hash);
    if (known) {
      known.ids.push(row.id);
      continue;
    }
    if (out.length === MAX) continue;
    const entry: HistoryEntry = { id: row.id, ids: [row.id], hash, input: label(row), vehicle: { plate: row.vehicle?.plate ?? null, name: vehicleName(row) }, outcome: outcomeOf(row), startedAt: row.startedAt };
    seen.set(hash, entry);
    out.push(entry);
  }
  return out;
}

// Entries hidden locally (by entry id) before the next server read confirms it.
export const withoutRemoved = (entries: readonly HistoryEntry[], removed: ReadonlySet<string>): HistoryEntry[] => entries.filter((e) => !removed.has(e.id));

export function formatWhen(iso: string, now: Date = new Date()): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const time = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  return d.toDateString() === now.toDateString() ? time : `${d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })} ${time}`;
}

const startOfDay = (d: Date): number => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();

export function formatRelative(iso: string, now: Date = new Date()): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const minutes = Math.floor((now.getTime() - d.getTime()) / 60_000);
  if (minutes < 1) return 'à l’instant';
  if (minutes < 60) return `il y a ${minutes} min`;
  const days = Math.round((startOfDay(now) - startOfDay(d)) / 86_400_000);
  if (days === 0) return `il y a ${Math.floor(minutes / 60)} h`;
  if (days === 1) return 'hier';
  if (days < 7) return `il y a ${days} jours`;
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}
