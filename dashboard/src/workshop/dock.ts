import { TURN_ACCEPTED_TYPES, TURN_MAX_FILE_BYTES, TURN_MAX_FILES } from '../types';
import type { Section } from './view';

export type DockScope = Section | 'parc';

export function dockScope(section: Section | null): DockScope {
  return section ?? 'parc';
}

/** What the dock sends: on Documents the words are a search through the vehicle's documents. */
export function scopeInstruction(scope: DockScope, text: string, documentSearch: (query: string) => string): string {
  const line = text.trim();
  return scope === 'documents' && line ? documentSearch(line) : line;
}

export type FileProblem = { kind: 'type' | 'size'; name: string } | { kind: 'count' };

/** Mirrors the server's checks (type, size, count) so a doomed upload is never sent. */
export function admitFiles(held: number, incoming: readonly File[]): { admitted: File[]; problems: FileProblem[] } {
  const admitted: File[] = [];
  const problems: FileProblem[] = [];
  let overflow = false;
  for (const file of incoming) {
    if (!(TURN_ACCEPTED_TYPES as readonly string[]).includes(file.type)) problems.push({ kind: 'type', name: file.name });
    else if (file.size > TURN_MAX_FILE_BYTES) problems.push({ kind: 'size', name: file.name });
    else if (held + admitted.length >= TURN_MAX_FILES) overflow = true;
    else admitted.push(file);
  }
  if (overflow) problems.push({ kind: 'count' });
  return { admitted, problems };
}

/** A Retry-After in words: seconds under a minute, whole minutes past it. */
export function waitLabel(seconds: number | null): string | null {
  if (seconds === null) return null;
  return seconds < 60 ? `${Math.max(1, seconds)} s` : `${Math.ceil(seconds / 60)} min`;
}
