import type { MaintenanceRecord, PlannedPart } from '../types';
import { serviceLabel } from '../labels';
import type { Locale } from '../i18n/locale';
import { sentenceCase } from './describe';
import { stripNote } from './partMeshes';
import { topicForText } from './focusTopic';
import type { Fitted, JobItem, ToOrder } from './modules';
import type { Projection } from './view';

export interface ShelfItem {
  key: string;
  part: PlannedPart;
  type: string | null;
}

export type Shelf =
  | { id: string; kind: 'toOrder'; job: JobItem; items: ShelfItem[] }
  | { id: string; kind: 'fitted'; record: MaintenanceRecord; items: ShelfItem[] };

export type ShelfMove = 'left' | 'right' | 'up' | 'down';

const items = (id: string, parts: PlannedPart[], types: Array<string | null>): ShelfItem[] => parts.map((part, i) => ({ key: `${id}#${i}`, part, type: types[i] ?? null }));

export function partDisplay(part: PlannedPart, itemType: string | null | undefined, locale: Locale): { name: string; note: string | null } {
  const note = part.label?.match(/\(([^)]*)\)/)?.[1].trim() || null;
  const label = part.label ? stripNote(part.label) : '';
  const named = label || (part.brand && itemType ? sentenceCase(serviceLabel(itemType, locale)) : '');
  return { name: [part.brand, named].filter(Boolean).join(' ') || part.ref, note };
}

/** The Pièces shelves: one per job to order, then one per visit, newest first (the order piecesLists gives). */
export function visitShelfId(recordIri: string): string {
  return `rec:${recordIri}`;
}

const SELF = /^(self|moi|me|diy|soi-m[êe]me)$/i;

/** Who did a visit: the owner (a do-it-yourself marker the API stores raw) or a named garage. */
export function performer(by: string | null | undefined): { self: true } | { self: false; name: string } | null {
  const name = by?.trim();
  if (!name) return null;
  return SELF.test(name) ? { self: true } : { self: false, name };
}

export function partShelves(toOrder: ToOrder[], fitted: Fitted[]): Shelf[] {
  return [
    ...toOrder.map(({ job, parts }): Shelf => ({ id: `job:${job.id}`, kind: 'toOrder', job, items: items(`job:${job.id}`, parts, parts.map(() => job.type)) })),
    ...fitted.map(({ record, parts }): Shelf => ({ id: visitShelfId(record.id), kind: 'fitted', record, items: items(visitShelfId(record.id), parts, record.items.flatMap((i) => i.parts.map(() => i.type))) })),
  ];
}

export function defaultPart(shelves: Shelf[]): string | null {
  return shelves.find((s) => s.items.length > 0)?.items[0].key ?? null;
}

export function findPart(shelves: Shelf[], key: string | null): { shelf: Shelf; item: ShelfItem } | null {
  for (const shelf of shelves) {
    const item = shelf.items.find((i) => i.key === key);
    if (item) return { shelf, item };
  }
  return null;
}

const describePart = (p: PlannedPart) => [p.label, p.brand, p.ref].filter(Boolean).join(' ');

/** The part an agent frame points at: a summoned task's (named) part, or the first part of a revealed assembly. */
export function partForProjection(shelves: Shelf[], projection: Projection | null): string | null {
  if (projection?.kind === 'parts') {
    const shelf = shelves.find((s) => s.kind === 'toOrder' && s.job.taskId === projection.taskId);
    if (!shelf) return null;
    const named = projection.parts?.[0]?.ref;
    return (named ? shelf.items.find((i) => i.part.ref === named) : undefined)?.key ?? shelf.items[0]?.key ?? null;
  }
  if (projection?.kind === 'assembly') {
    const keys = new Set(projection.partKeys);
    for (const shelf of shelves) {
      const hit = shelf.items.find((i) => {
        const topic = topicForText(describePart(i.part))?.topic;
        return topic !== undefined && keys.has(topic);
      });
      if (hit) return hit.key;
    }
  }
  return null;
}

export function movePart(shelves: Shelf[], key: string, move: ShelfMove): string {
  const rows = shelves.filter((s) => s.items.length > 0);
  const row = rows.findIndex((s) => s.items.some((i) => i.key === key));
  if (row < 0) return key;
  const col = rows[row].items.findIndex((i) => i.key === key);
  if (move === 'left' || move === 'right') {
    const flat = rows.flatMap((s) => s.items);
    const at = flat.findIndex((i) => i.key === key);
    return flat[Math.min(flat.length - 1, Math.max(0, at + (move === 'right' ? 1 : -1)))].key;
  }
  const next = rows[row + (move === 'down' ? 1 : -1)];
  if (!next) return key;
  return next.items[Math.min(col, next.items.length - 1)].key;
}
