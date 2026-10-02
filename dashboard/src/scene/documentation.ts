import type { Locale } from '../i18n/locale';
import { visitTitle } from '../records';
import { taskTitle } from '../tasks';
import type { MaintenanceRecord, PlannedTask, VehicleDocument, VehicleDocumentKind } from '../types';

// The vehicle's documentation (`focusView {panel:'docs'}`): every filed document plus the visit bills,
// grouped in a fixed reading order. Bills are NOT a document kind on the wire — they ride the visits
// (`MaintenanceRecord.attachments`, the same data Le Dossier shows) — so they are merged in here rather
// than fetched a second time.

export type DocGroupKind = VehicleDocumentKind | 'bill';

const ORDER: readonly DocGroupKind[] = ['quote', 'manual', 'technical_note', 'bill', 'other'];

export interface DocRow {
  key: string;
  kind: DocGroupKind;
  title: string;
  sourceRef: string | null;
  /** null for a bill: the attachments API does not count pages. */
  pageCount: number | null;
  size: number;
  contentType: string;
  filename: string;
  /** Owner-gated — open through `fetchAttachmentObjectUrl`, never a bare href. */
  url: string;
  /** The open planned task a quote is linked to, by its title; null when unlinked or no longer open. */
  taskLabel: string | null;
  date: string | null;
  remove: { type: 'document'; id: string } | { type: 'bill'; url: string };
}

export interface DocGroup {
  kind: DocGroupKind;
  rows: DocRow[];
}

export interface Documentation {
  groups: DocGroup[];
  count: number;
  /** The documents read failed: the bills still show, but "no manual" would be a claim we cannot make. */
  unavailable: boolean;
  /** Open tasks a new document can be linked to, keyed by the bare ULID the documents API stores. */
  linkableTasks: { id: string; label: string }[];
}

/** The bare ULID at the end of a planned-task IRI (the documents API stores ULIDs, the client IRIs). */
export function taskUlid(iri: string): string {
  return iri.slice(iri.lastIndexOf('/') + 1);
}

export function buildDocumentation(
  documents: VehicleDocument[] | null,
  records: MaintenanceRecord[],
  tasks: PlannedTask[],
  locale: Locale,
): Documentation {
  const openTasks = new Map(tasks.filter((t) => t.status === 'open').map((t) => [taskUlid(t.id), taskTitle(t, locale)]));

  const rows: DocRow[] = (documents ?? []).map((d) => ({
    key: `doc:${d.id}`,
    kind: d.kind,
    title: d.title.trim() || d.filename,
    sourceRef: d.sourceRef,
    pageCount: d.pageCount,
    size: d.size,
    contentType: d.contentType,
    filename: d.filename,
    url: d.url,
    taskLabel: d.plannedTaskId ? openTasks.get(d.plannedTaskId) ?? null : null,
    date: d.addedAt.slice(0, 10),
    remove: { type: 'document', id: d.id },
  }));

  for (const record of records) {
    for (const att of record.attachments ?? []) {
      rows.push({
        key: `bill:${att.id}`,
        kind: 'bill',
        title: att.filename,
        sourceRef: visitTitle(record, locale),
        pageCount: null,
        size: att.size,
        contentType: att.contentType,
        filename: att.filename,
        url: att.url,
        taskLabel: null,
        date: record.performedAt,
        remove: { type: 'bill', url: att.url },
      });
    }
  }

  const groups = ORDER.map((kind) => ({ kind, rows: rows.filter((r) => r.kind === kind) })).filter((g) => g.rows.length > 0);

  return {
    groups,
    count: rows.length,
    unavailable: documents === null,
    linkableTasks: [...openTasks].map(([id, label]) => ({ id, label })),
  };
}
