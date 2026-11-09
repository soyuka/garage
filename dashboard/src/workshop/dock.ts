import type { Section } from './view';

export type DockScope = Section | 'parc';

export function dockScope(section: Section | null): DockScope {
  return section ?? 'parc';
}

/** What the dock sends: on Documents the words are a search through the vehicle's documents. */
export function scopeInstruction(scope: DockScope, text: string, documentSearch: (query: string) => string): string {
  const line = text.trim();
  return scope === 'documents' ? documentSearch(line) : line;
}
