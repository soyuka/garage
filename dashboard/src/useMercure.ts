import { useEffect, useRef } from 'react';
import type { DomainEvent, MercureFrame, VehicleSummary } from './types';

// ─────────────────────────────────────────────────────────────────────────────
// Atelier — the Mercure subscription (see PROVENANCE: Tier-1 transport re-authored
// fresh from the EventSource topic-subscribe contract, no import path back).
//
// Subscribe to EXACTLY two absolute-IRI topics: the collection topic
// `${API}/garage/vehicles` (the publisher fans the SAME full-vehicle payload to the
// item AND the collection topic, so the collection subscription alone covers every
// vehicle — never add per-item subs) and the per-tab session topic
// `${API}/agent/sessions/<id>`. A domain event carries the full read-model in
// `resource`, so the live cascade reconciles state with NO follow-up fetch.
// ─────────────────────────────────────────────────────────────────────────────

const HUB = import.meta.env.VITE_MERCURE_URL ?? '';

// ── Pure core (unit-tested) ───────────────────────────────────────────────────

/** Build the hub URL with one repeated `?topic=` param per subscribed topic, against the page origin. */
export function buildHubUrl(hub: string, origin: string, topics: string[]): string {
  const url = new URL(hub, origin);
  topics.forEach((t) => url.searchParams.append('topic', t));
  return url.toString();
}

/** Parse a Mercure SSE data line; null on a non-JSON keep-alive (the hub sends `:` heartbeats). */
export function parseFrame(data: string): MercureFrame | null {
  try {
    return JSON.parse(data) as MercureFrame;
  } catch {
    return null;
  }
}

/** A domain event (collection topic) carries `kind`; a session UIAction carries `type`. */
export function isDomainEvent(frame: MercureFrame): frame is DomainEvent {
  return 'kind' in frame && frame.kind === 'vehicle';
}

/** Reconcile a pushed resource into the collection: replace the matching `@id` in place, else append. */
export function upsertVehicle(list: VehicleSummary[], resource: VehicleSummary): VehicleSummary[] {
  const i = list.findIndex((v) => v['@id'] === resource['@id']);
  if (i === -1) return [...list, resource];
  const next = list.slice();
  next[i] = resource;
  return next;
}

/** Drop the soft-deleted member from the collection. */
export function removeVehicle(list: VehicleSummary[], iri: string): VehicleSummary[] {
  return list.filter((v) => v['@id'] !== iri);
}

// ── Subscription hook (glue) ──────────────────────────────────────────────────

/**
 * Subscribe to the given absolute-IRI topics; stream every frame into `onFrame`. The handler is held
 * in a ref so a changing callback identity does NOT tear down and re-open the EventSource — only a
 * change to the topic set (keyed by join) re-subscribes. Closes the stream on unmount.
 */
export function useMercure(topics: string[], onFrame: (frame: MercureFrame) => void): void {
  const handler = useRef(onFrame);
  handler.current = onFrame;
  const key = topics.join('|');

  useEffect(() => {
    if (!HUB || topics.length === 0) return;
    const source = new EventSource(buildHubUrl(HUB, window.location.origin, topics));
    source.onmessage = (e: MessageEvent<string>) => {
      const frame = parseFrame(e.data);
      if (frame) handler.current(frame);
    };
    return () => source.close();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
}
