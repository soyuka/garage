import { API, authFetch } from './auth';
import type { Subscription } from './useMercure';
import type { AgentTurnRequest, Capabilities, TurnRefusalKind } from './types';

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

export type TurnBody = Omit<AgentTurnRequest, 'files'>;

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

/** The server turned the turn down before enqueueing anything; `retryAfter` in seconds, on a 429. */
export class TurnRefusedError extends Error {
  constructor(
    readonly kind: TurnRefusalKind,
    readonly status: number,
    readonly retryAfter: number | null,
  ) {
    super(`agent turn refused: ${status}`);
    this.name = 'TurnRefusedError';
  }
}

const REFUSALS: Record<number, TurnRefusalKind> = { 413: 'tooLarge', 415: 'unsupported', 422: 'invalid', 429: 'rateLimited' };

/** Retry-After is delta-seconds or an HTTP date (RFC 9110 §10.2.3). */
export function retryAfterSeconds(header: string | null, now: number = Date.now()): number | null {
  if (!header) return null;
  const trimmed = header.trim();
  if (/^\d+$/.test(trimmed)) return Number(trimmed);
  const at = Date.parse(trimmed);
  return Number.isNaN(at) ? null : Math.max(0, Math.ceil((at - now) / 1000));
}

/** JSON without files; multipart with them, with no Content-Type so the browser writes the boundary. */
export function turnRequestInit({ files = [], ...body }: AgentTurnRequest): RequestInit {
  if (files.length === 0) return { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
  const form = new FormData();
  for (const [key, value] of Object.entries(body)) if (value !== undefined) form.append(key, value);
  for (const file of files) form.append('files[]', file);
  return { method: 'POST', body: form };
}

/**
 * Post one agent turn. The endpoint acks 202 (the turn runs on the worker); the reply streams over the
 * session topic. A refusal (413/415/422/429) rejects with a {@link TurnRefusedError}; anything else is a
 * plain transport failure (the caller clears the optimistic busy state either way).
 */
export async function postTurn(instruction: string, ctx: TurnContext, files: File[] = []): Promise<void> {
  const res = await authFetch(`${API}/agent/turn`, turnRequestInit({ ...buildTurnBody(instruction, ctx), files }));
  if (res.ok) return;
  const kind = REFUSALS[res.status];
  if (kind) throw new TurnRefusedError(kind, res.status, kind === 'rateLimited' ? retryAfterSeconds(res.headers.get('Retry-After')) : null);
  throw new Error(`agent turn failed: ${res.status}`);
}

/**
 * What this dashboard listens on, all resolved by GET /agent/link (keyed digests; every update is private,
 * read with the subscriber cookie the same response sets): this tab's turn
 * SESSION topic, the owner's FLEET topic (vehicle writes) and the owner's PILOT topic, where render
 * intents driven from an external MCP host (Claude Code, Goose…) land. Nothing before it resolves. The pilot topic carries the very same UIAction
 * frames, so the same handler drives the same choreography whoever is at the controls.
 *
 * The pilot topic gets its OWN subscription rather than a third `?match=` on the shared one. Mercure
 * delivers an update without naming the topic it matched, so a single stream cannot tell the two
 * drivers apart — and the dashboard has to, because the two lifecycles differ (a pilot run declares
 * no start, may go quiet for minutes, and must never trip the in-app turn's liveness watchdog; see
 * pilot.ts). One EventSource per driver makes provenance STRUCTURAL: the stream a frame arrived on is
 * the answer, with nothing to forget to stamp and nothing new on the wire.
 */
export function dashboardSubscriptions(link: OwnerTopics): Subscription[] {
  const { generation } = link;
  const app = [link.fleetTopic, link.sessionTopic].filter((t): t is string => t !== null);
  const subs: Subscription[] = app.length > 0 ? [{ driver: 'app', topics: app, generation }] : [];
  if (link.topic) subs.push({ driver: 'pilot', topics: [link.topic], generation });
  return subs;
}

/**
 * The owner-bound topics GET /agent/link hands out: `topic` = pilot intents, `fleetTopic` = vehicle
 * writes, `sessionTopic` = this tab's in-app turns (a digest of owner + tab session id, so another
 * owner reusing the id lands elsewhere). The same response sets the hub's HttpOnly subscriber cookie;
 * `generation` counts the fetches that did, so a refetch reopens the streams under the fresh cookie
 * even when the topics are unchanged.
 */
export interface OwnerTopics {
  topic: string | null;
  fleetTopic: string | null;
  sessionTopic: string | null;
  generation: number;
}

export const NO_OWNER_TOPICS: OwnerTopics = { topic: null, fleetTopic: null, sessionTopic: null, generation: 0 };

export interface AgentLink extends OwnerTopics {
  capabilities: Capabilities;
}

/** Until the server says otherwise everything is assumed on: the endpoints' own 503s stay the fallback. */
export const ALL_CAPABILITIES: Capabilities = { agent: true, voice: true, dictation: true, web_search: true };

/** Only a literal `false` switches a capability off — a malformed or older body never hides a feature. */
export function parseAgentLink(body: unknown): AgentLink {
  const link = body !== null && typeof body === 'object' ? (body as { topic?: unknown; fleetTopic?: unknown; sessionTopic?: unknown; capabilities?: unknown }) : {};
  const raw = link.capabilities !== null && typeof link.capabilities === 'object' ? (link.capabilities as Record<string, unknown>) : {};
  const capabilities = { ...ALL_CAPABILITIES };
  for (const key of Object.keys(capabilities) as (keyof Capabilities)[]) {
    if (raw[key] === false) capabilities[key] = false;
  }
  return {
    topic: typeof link.topic === 'string' ? link.topic : null,
    fleetTopic: typeof link.fleetTopic === 'string' ? link.fleetTopic : null,
    sessionTopic: typeof link.sessionTopic === 'string' ? link.sessionTopic : null,
    generation: 0,
    capabilities,
  };
}

/**
 * Resolve the owner's topics (and, as a side effect, the hub's subscriber cookie) and what this deployment can do. Each topic is a
 * keyed digest server-side, hence it can only be asked for, not derived. Null when unauthenticated or
 * the endpoint is absent.
 */
export async function fetchAgentLink(sessionId: string): Promise<AgentLink> {
  try {
    const res = await authFetch(`${API}/agent/link?session=${encodeURIComponent(sessionId)}`);
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
