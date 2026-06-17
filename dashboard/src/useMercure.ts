import { useEffect } from 'react';
import type { UIAction } from './types';

const HUB = import.meta.env.VITE_MERCURE_URL ?? '';

export interface DomainEvent {
  kind: 'vehicle' | 'maintenance' | 'fact';
  event: 'created' | 'updated';
  vehicle: string;
  iri: string;
}

/**
 * A frame on one of our Mercure topics: either a domain event (public vehicle topics, fanned out by
 * DomainEventPublisher on any write) or a render intent (the per-session topic, emitted by the
 * agent's render-intent tools). They are told apart by the discriminating `type` field a UIAction
 * carries; the caller routes accordingly.
 */
export type MercureFrame = DomainEvent | UIAction;

/**
 * Subscribe to one or more Mercure topics and stream frames into the UI. Topics are the absolute
 * vehicle IRIs the API publishes on (collection + selected item) plus this tab's session topic. A
 * write anywhere (agent, another tab, the MCP tools) live-patches the dashboard; the agent's intents
 * arrive on the session topic for this tab only.
 */
export function useMercure(topics: string[], onEvent: (e: MercureFrame) => void): void {
  const key = topics.join('|');
  useEffect(() => {
    if (!HUB || topics.length === 0) {
      return;
    }
    const url = new URL(HUB, window.location.origin);
    topics.forEach((t) => url.searchParams.append('topic', t));

    const source = new EventSource(url);
    source.onmessage = (e: MessageEvent<string>) => {
      try {
        onEvent(JSON.parse(e.data) as MercureFrame);
      } catch {
        /* ignore non-JSON keep-alives */
      }
    };
    return () => source.close();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
}
