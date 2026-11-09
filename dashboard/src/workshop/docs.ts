import type { DocRow } from './modules';

export type DocKind = DocRow['kind'];

const KIND_ORDER: readonly DocKind[] = ['bill', 'manual', 'technical_note', 'quote', 'other'];

export function docKinds(rows: DocRow[]): Array<{ kind: DocKind; count: number }> {
  return KIND_ORDER.map((kind) => ({ kind, count: rows.filter((r) => r.kind === kind).length })).filter((k) => k.count > 0);
}

/** The preview strip: documentRows' newest-first order, narrowed to one kind when a chip is on. */
export function stripRows(rows: DocRow[], kind: DocKind | null): DocRow[] {
  return kind ? rows.filter((r) => r.kind === kind) : rows;
}

export function openRowId(rows: DocRow[], doc: { url: string } | null): string | null {
  return (doc && rows.find((r) => r.url === doc.url)?.id) ?? null;
}

/** The strip's card title: a manual section's own name when the title carries one after a dash. */
export function previewTitle(title: string): string {
  const at = title.lastIndexOf(' — ');
  return at < 0 ? title : title.slice(at + 3).trim() || title;
}
