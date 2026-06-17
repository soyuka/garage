import { useEffect } from 'react';

const HUB = import.meta.env.VITE_MERCURE_URL ?? '';

export interface DomainEvent {
  kind: 'vehicle' | 'maintenance' | 'fact';
  event: 'created' | 'updated';
  vehicle: string;
  iri: string;
}

/**
 * Subscribe to one or more Mercure topics and stream domain events into the UI. The topics are
 * the absolute vehicle IRIs the API publishes on (DomainEventPublisher) — collection + the
 * selected item. A write anywhere (agent, another tab, the MCP tools) live-patches the dashboard.
 */
export function useMercure(topics: string[], onEvent: (e: DomainEvent) => void): void {
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
        onEvent(JSON.parse(e.data) as DomainEvent);
      } catch {
        /* ignore non-JSON keep-alives */
      }
    };
    return () => source.close();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
}
