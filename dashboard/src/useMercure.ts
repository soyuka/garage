import { useEffect, useRef } from 'react';
import type { Driver } from './pilot';
import type { DomainEvent, MercureFrame, UIAction, VehicleSummary } from './types';

// ─────────────────────────────────────────────────────────────────────────────
// Atelier — the Mercure subscription (Tier-1 transport re-authored
// fresh from the EventSource topic-subscribe contract, no import path back).
//
// The in-app subscription carries two absolute-IRI topics: the owner's fleet topic
// (`fleetTopic` from GET /agent/link — every vehicle write of this owner, one
// topic for the whole fleet, never per-item subs) and the per-tab session topic
// (`sessionTopic`, a digest of owner + tab session id). A domain event carries the full read-model in
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

// Typed against the union: a new UIAction member fails the build until it is listed here.
const UI_ACTION_TYPES: Record<UIAction['type'], true> = {
  selectVehicle: true,
  showFact: true,
  focusView: true,
  proposeActions: true,
  showParts: true,
  showHistory: true,
  focusRegion: true,
  revealAssembly: true,
  showDocument: true,
  documentScan: true,
  toast: true,
  status: true,
  turn: true,
};
const DOMAIN_EVENTS: ReadonlySet<string> = new Set<DomainEvent['event']>(['created', 'updated', 'deleted']);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isKnownFrame(value: unknown): value is MercureFrame {
  if (!isRecord(value)) return false;
  if ('kind' in value) {
    const resource = value.resource;
    return value.kind === 'vehicle' && typeof value.event === 'string' && DOMAIN_EVENTS.has(value.event) && typeof value.iri === 'string' && isRecord(resource) && typeof resource['@id'] === 'string';
  }
  return typeof value.type === 'string' && Object.prototype.hasOwnProperty.call(UI_ACTION_TYPES, value.type);
}

/**
 * Parse a Mercure SSE data line. Null on a non-JSON keep-alive (the hub sends `:` heartbeats) and on
 * anything that is not a frame this client knows: the pilot topic carries model-authored payloads, so an unknown discriminant never reaches a reducer.
 */
export function parseFrame(data: string): MercureFrame | null {
  let parsed: unknown;
  try {
    parsed = JSON.parse(data);
  } catch {
    return null;
  }
  return isKnownFrame(parsed) ? parsed : null;
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

/**
 * Reconcile a vehicle pushed by a domain event. The collection topic is not owner-scoped, so a frame
 * only ever updates a car already in the owner's listing; an unknown `@id` (a car just created in
 * another tab — or someone else's) is never appended from the frame: the caller re-reads the owner's
 * own listing instead.
 */
export function reconcileVehicleEvent(list: VehicleSummary[], resource: VehicleSummary): { list: VehicleSummary[]; refetch: boolean } {
  if (!list.some((v) => v['@id'] === resource['@id'])) return { list, refetch: true };
  return { list: upsertVehicle(list, resource), refetch: false };
}

/** Drop the soft-deleted member from the collection. */
export function removeVehicle(list: VehicleSummary[], iri: string): VehicleSummary[] {
  return list.filter((v) => v['@id'] !== iri);
}

// ── Subscription hook (glue) ──────────────────────────────────────────────────

/**
 * One EventSource's worth of topics and the driver whose frames arrive on it (see agent.ts). Every
 * update is private: the stream is authorized by the hub's HttpOnly cookie GET /agent/link sets (an
 * EventSource cannot send a header, and Mercure 1.x refuses tokens in the query string). `generation`
 * bumps when that cookie was renewed, which is what reopens a refused stream.
 */
export interface Subscription {
  driver: Driver;
  topics: string[];
  generation: number;
}

export interface StreamLifecycle {
  /** The hub refused the stream for good (an expired token's reconnect is a 401): fetch a fresh link. */
  onRefused: () => void;
  onOpen: () => void;
}

/**
 * Open one EventSource per subscription. A transient drop is retried by EventSource itself
 * (readyState CONNECTING); only a refused (re)connect leaves it CLOSED, which is the one case that needs
 * a new token. Returns the closer.
 */
export function openStreams(
  subscriptions: Subscription[],
  hub: string,
  origin: string,
  onFrame: (frame: MercureFrame, driver: Driver) => void,
  lifecycle: StreamLifecycle,
  Source: typeof EventSource = EventSource,
): () => void {
  const sources = subscriptions
    .filter((s) => s.topics.length > 0)
    .map(({ driver, topics }) => {
      const source = new Source(buildHubUrl(hub, origin, topics));
      source.onmessage = (e: MessageEvent<string>) => {
        const frame = parseFrame(e.data);
        if (frame) onFrame(frame, driver);
      };
      source.onopen = () => lifecycle.onOpen();
      source.onerror = () => {
        if (source.readyState === Source.CLOSED) lifecycle.onRefused();
      };
      return source;
    });
  return () => sources.forEach((s) => s.close());
}

/**
 * Open one EventSource per subscription and stream every frame into `onFrame`, tagged with the driver
 * of the stream it came from — provenance without a wire change (Mercure never names the matched
 * topic, so one stream carrying both drivers could not tell them apart). The handler is held in a ref
 * so a changing callback identity does NOT tear the streams down — only a change to the subscription
 * set (keyed by its serialisation, generation included: a renewed cookie re-subscribes) does. Closes every
 * stream on unmount.
 */
export function useMercure(subscriptions: Subscription[], onFrame: (frame: MercureFrame, driver: Driver) => void, lifecycle: StreamLifecycle): void {
  const handler = useRef(onFrame);
  handler.current = onFrame;
  const life = useRef(lifecycle);
  life.current = lifecycle;
  const key = subscriptions.map((s) => `${s.driver}:${s.topics.join('|')}:${s.generation}`).join(';');

  useEffect(() => {
    if (!HUB) return;
    return openStreams(subscriptions, HUB, window.location.origin, (frame, driver) => handler.current(frame, driver), {
      onRefused: () => life.current.onRefused(),
      onOpen: () => life.current.onOpen(),
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
}
