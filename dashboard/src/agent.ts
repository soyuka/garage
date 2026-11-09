import { API, authFetch } from './auth';
import type { Subscription } from './useMercure';
import type { Capabilities } from './types';

// ─────────────────────────────────────────────────────────────────────────────
// Atelier — the agent turn (Tier-1 transport re-authored fresh from
// the /agent/turn request contract, no import path back).
//
// A turn is async: POST acks 202 and the reply arrives as session frames over the
// per-session Mercure topic (turn/status/toast + the UIAction union). There is NO
// action list in the response. The chat is hidden — the reply IS the state mutations.
// ─────────────────────────────────────────────────────────────────────────────

export interface TurnContext {
  sessionId: string;
  /** The selected vehicle IRI, so a vehicle-scoped instruction lands on the right car. */
  selectedId?: string;
  /** A compact one-line snapshot of the on-screen state (NOT a transcript). */
  summary?: string;
  /** The CTA card being replied to (headline + finding + option labels), one bounded line. */
  pendingContext?: string;
}

export interface TurnBody {
  instruction: string;
  sessionId: string;
  selectedId?: string;
  summary?: string;
  pendingContext?: string;
}

/** Assemble the POST body, omitting any absent optional (so an unset field never serializes as null). */
export function buildTurnBody(instruction: string, ctx: TurnContext): TurnBody {
  const body: TurnBody = { instruction, sessionId: ctx.sessionId };
  if (ctx.selectedId !== undefined) body.selectedId = ctx.selectedId;
  if (ctx.summary !== undefined) body.summary = ctx.summary;
  if (ctx.pendingContext !== undefined) body.pendingContext = ctx.pendingContext;
  return body;
}

/** Where this tab keeps its session id — `sessionStorage`: per tab, survives a reload, never shared. */
export const SESSION_KEY = 'mpa.session';

/**
 * The tab's session id. It keys the turn's Mercure topic, so it must outlive the PAGE: a reload while
 * a turn is queued or running (F5, a Vite full reload) comes back on the same topic and still receives
 * every frame the worker publishes afterwards. A fresh page instance used to mint a new id, which sent
 * an in-flight turn's whole reply to a topic with no subscriber (2026-09-05). Storage that is absent or
 * throws (privacy modes) degrades to a per-page id — the old behaviour, never a crash.
 */
export function tabSessionId(storage: Pick<Storage, 'getItem' | 'setItem'> | null, mint: () => string): string {
  try {
    const kept = storage?.getItem(SESSION_KEY);
    if (kept) return kept;
    const fresh = mint();
    storage?.setItem(SESSION_KEY, fresh);
    return fresh;
  } catch {
    return mint();
  }
}

/**
 * Post one agent turn. The endpoint acks 202 (the turn runs on the worker); the reply streams over the
 * session topic. We only surface a transport failure (the caller clears the optimistic busy state).
 */
export async function postTurn(instruction: string, ctx: TurnContext): Promise<void> {
  const res = await authFetch(`${API}/agent/turn`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(buildTurnBody(instruction, ctx)),
  });
  if (!res.ok) throw new Error(`agent turn failed: ${res.status}`);
}

/**
 * What this dashboard listens on: its own per-tab turn session and — once GET /agent/link has resolved
 * them — the owner's FLEET topic (vehicle writes; a keyed digest, since the hub takes anonymous
 * subscribers and a guessable topic would broadcast every owner's plates) and the owner's PILOT topic, where render intents driven from an
 * external MCP host (Claude Code, Goose…) land. The pilot topic carries the very same UIAction
 * frames, so the same handler drives the same choreography whoever is at the controls.
 *
 * The pilot topic gets its OWN subscription rather than a third `?match=` on the shared one. Mercure
 * delivers an update without naming the topic it matched, so a single stream cannot tell the two
 * drivers apart — and the dashboard has to, because the two lifecycles differ (a pilot run declares
 * no start, may go quiet for minutes, and must never trip the in-app turn's liveness watchdog; see
 * pilot.ts). One EventSource per driver makes provenance STRUCTURAL: the stream a frame arrived on is
 * the answer, with nothing to forget to stamp and nothing new on the wire.
 */
export function dashboardSubscriptions(api: string, sessionId: string, link: OwnerTopics): Subscription[] {
  const app = [`${api}/agent/sessions/${sessionId}`];
  if (link.fleetTopic) app.unshift(link.fleetTopic);
  const subs: Subscription[] = [{ driver: 'app', topics: app }];
  if (link.topic) subs.push({ driver: 'pilot', topics: [link.topic] });
  return subs;
}

/** The owner-scoped topics GET /agent/link hands out: `topic` = pilot intents, `fleetTopic` = vehicle writes. */
export interface OwnerTopics {
  topic: string | null;
  fleetTopic: string | null;
}

export const NO_OWNER_TOPICS: OwnerTopics = { topic: null, fleetTopic: null };

export interface AgentLink extends OwnerTopics {
  capabilities: Capabilities;
}

/** Until the server says otherwise everything is assumed on: the endpoints' own 503s stay the fallback. */
export const ALL_CAPABILITIES: Capabilities = { agent: true, voice: true, dictation: true, web_search: true };

/** Only a literal `false` switches a capability off — a malformed or older body never hides a feature. */
export function parseAgentLink(body: unknown): AgentLink {
  const link = body !== null && typeof body === 'object' ? (body as { topic?: unknown; fleetTopic?: unknown; capabilities?: unknown }) : {};
  const raw = link.capabilities !== null && typeof link.capabilities === 'object' ? (link.capabilities as Record<string, unknown>) : {};
  const capabilities = { ...ALL_CAPABILITIES };
  for (const key of Object.keys(capabilities) as (keyof Capabilities)[]) {
    if (raw[key] === false) capabilities[key] = false;
  }
  return {
    topic: typeof link.topic === 'string' ? link.topic : null,
    fleetTopic: typeof link.fleetTopic === 'string' ? link.fleetTopic : null,
    capabilities,
  };
}

/**
 * Resolve the owner's pilot topic and what this deployment can do. The topic is a keyed digest
 * server-side (the hub takes anonymous subscribers, so the topic is the only thing scoping one owner's
 * intents to their own dashboards), hence it can only be asked for, not derived. Topic null when
 * unauthenticated or the endpoint is absent — the dashboard then simply runs without the pilot channel.
 */
export async function fetchAgentLink(): Promise<AgentLink> {
  try {
    const res = await authFetch(`${API}/agent/link`);
    if (!res.ok) return parseAgentLink(null);
    return parseAgentLink(await res.json());
  } catch {
    return parseAgentLink(null);
  }
}

/**
 * Ask the worker to stop this session's turn. Cooperative: the endpoint acks 202 and the worker ends
 * the turn at its next tool boundary, so the busy state clears on the streamed turn-end, not this ack.
 */
export async function cancelTurn(sessionId: string): Promise<void> {
  const res = await authFetch(`${API}/agent/turn/cancel`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ sessionId }),
  });
  if (!res.ok) throw new Error(`agent cancel failed: ${res.status}`);
}
