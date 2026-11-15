import { useEffect, useMemo, useRef } from 'react';
import { fetchAgentLink, type AgentLink } from './agent';
import type { StreamLifecycle } from './useMercure';

const MAX_BACKOFF_MS = 60_000;

export interface LinkKeeper {
  /** Fetch the link now (mount, session change). */
  refresh(): void;
  /** A stream was refused for good: its token most likely expired. */
  refused(): void;
  /** A stream is live again: the next refusal is a fresh expiry, not a loop. */
  opened(): void;
  stop(): void;
}

/**
 * Keeps GET /agent/link, and with it the hub's subscriber cookie, current. The token in the cookie is
 * short-lived and the hub refuses an expired one on reconnect, so a refusal triggers a refetch; each
 * successful fetch bumps `generation`, which changes the subscription key so the streams reopen.
 * Refusals of several streams coalesce into one fetch, and repeated refusals of fresh tokens (a
 * misconfigured hub) back off instead of hammering it. A refetch that fails (no topics: the link
 * did not set the cookie) keeps the current link and retries later.
 */
export function linkKeeper(fetchLink: () => Promise<AgentLink>, onLink: (link: AgentLink) => void): LinkKeeper {
  let stopped = false;
  let busy = false;
  let generation = 0;
  let failures = 0;
  let timer: ReturnType<typeof setTimeout> | null = null;

  const backoff = (): number => (failures === 0 ? 0 : Math.min(1000 * 2 ** (failures - 1), MAX_BACKOFF_MS));

  const run = async (): Promise<void> => {
    timer = null;
    busy = true;
    let link: AgentLink | null = null;
    try {
      link = await fetchLink();
    } catch {
      link = null;
    }
    busy = false;
    if (stopped) return;
    if (link === null || (link.topic === null && generation > 0)) {
      failures += 1;
      schedule();
      return;
    }
    generation += 1;
    onLink({ ...link, generation });
  };

  const schedule = (): void => {
    if (stopped || busy || timer !== null) return;
    timer = setTimeout(() => void run(), backoff());
  };

  return {
    refresh() {
      schedule();
    },
    refused() {
      if (stopped || busy || timer !== null) return;
      schedule();
      failures += 1;
    },
    opened() {
      failures = 0;
    },
    stop() {
      stopped = true;
      if (timer !== null) clearTimeout(timer);
      timer = null;
    },
  };
}

/** The link for this tab's session, kept fresh; returns the stream lifecycle to hand to useMercure. */
export function useAgentLink(sessionId: string, onLink: (link: AgentLink) => void): StreamLifecycle {
  const apply = useRef(onLink);
  apply.current = onLink;
  const keeper = useRef<LinkKeeper | null>(null);

  useEffect(() => {
    const k = linkKeeper(() => fetchAgentLink(sessionId), (link) => apply.current(link));
    keeper.current = k;
    k.refresh();
    return () => {
      k.stop();
      keeper.current = null;
    };
  }, [sessionId]);

  return useMemo(() => ({ onRefused: () => keeper.current?.refused(), onOpen: () => keeper.current?.opened() }), []);
}
