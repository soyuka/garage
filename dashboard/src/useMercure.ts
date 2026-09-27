import { useEffect, useRef } from 'react';
import type { Driver } from './pilot';
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

/**
 * Build the hub URL with one repeated `?match=` param per subscribed topic, against the page origin.
 * Mercure 1.0 (non-compatibility mode) renamed the exact-match query parameter from `topic` to
 * `match` (a URI-template/pattern subscription would instead use `match_urlpattern`, unused here —
 * every subscription in this app is an exact absolute-IRI topic).
 */
export function buildHubUrl(hub: string, origin: string, topics: string[]): string {
  const url = new URL(hub, origin);
  topics.forEach((t) => url.searchParams.append('match', t));
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

/** One EventSource's worth of topics, and the driver whose frames arrive on it (see agent.ts). */
export interface Subscription {
  driver: Driver;
  topics: string[];
}

/**
 * Open one EventSource per subscription and stream every frame into `onFrame`, tagged with the driver
 * of the stream it came from — provenance without a wire change (Mercure never names the matched
 * topic, so one stream carrying both drivers could not tell them apart). The handler is held in a ref
 * so a changing callback identity does NOT tear the streams down — only a change to the subscription
 * set (keyed by its serialisation) re-subscribes. Closes every stream on unmount.
 */
export function useMercure(subscriptions: Subscription[], onFrame: (frame: MercureFrame, driver: Driver) => void): void {
  const handler = useRef(onFrame);
  handler.current = onFrame;
  const key = subscriptions.map((s) => `${s.driver}:${s.topics.join('|')}`).join(';');

  useEffect(() => {
    if (!HUB) return;
    const sources = subscriptions
      .filter((s) => s.topics.length > 0)
      .map(({ driver, topics }) => {
        const source = new EventSource(buildHubUrl(HUB, window.location.origin, topics));
        source.onmessage = (e: MessageEvent<string>) => {
          const frame = parseFrame(e.data);
          if (frame) handler.current(frame, driver);
        };
        return source;
      });
    return () => sources.forEach((s) => s.close());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
}
