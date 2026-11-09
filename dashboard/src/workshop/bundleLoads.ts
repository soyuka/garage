import type { Iri } from '../types';

/**
 * Per-car read sequencing: every bundle read takes a ticket and only the latest ticket's response may
 * land, so a slow early read can never overwrite a fresher one.
 */
export function createBundleSeq() {
  const latest = new Map<Iri, number>();
  let counter = 0;
  return {
    begin(iri: Iri): number {
      latest.set(iri, ++counter);
      return counter;
    },
    accept(iri: Iri, ticket: number): boolean {
      return latest.get(iri) === ticket;
    },
  };
}

/** The cars to start prefetching now: in order, skipping what is loaded/tried/in flight, within the cap. */
export function nextPrefetch(candidates: Iri[], skip: (iri: Iri) => boolean, inFlight: ReadonlySet<Iri>, limit: number): Iri[] {
  const free = limit - inFlight.size;
  if (free <= 0) return [];
  return candidates.filter((iri) => !inFlight.has(iri) && !skip(iri)).slice(0, free);
}
