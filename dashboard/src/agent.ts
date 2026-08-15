import { API, authFetch } from './auth';
import type { Subscription } from './useMercure';

// ─────────────────────────────────────────────────────────────────────────────
// Atelier — the agent turn (see PROVENANCE: Tier-1 transport re-authored fresh from
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
 * What this dashboard listens on: the public vehicle collection, its own per-tab turn session, and —
 * once GET /agent/link has resolved it — the owner's PILOT topic, where render intents driven from an
 * external MCP host (Claude Code, Goose…) land. The pilot topic carries the very same UIAction
 * frames, so the same handler drives the same choreography whoever is at the controls.
 *
 * The pilot topic gets its OWN subscription rather than a third `?topic=` on the shared one. Mercure
 * delivers an update without naming the topic it matched, so a single stream cannot tell the two
 * drivers apart — and the dashboard has to, because the two lifecycles differ (a pilot run declares
 * no start, may go quiet for minutes, and must never trip the in-app turn's liveness watchdog; see
 * pilot.ts). One EventSource per driver makes provenance STRUCTURAL: the stream a frame arrived on is
 * the answer, with nothing to forget to stamp and nothing new on the wire.
 */
export function dashboardSubscriptions(api: string, sessionId: string, pilotTopic: string | null): Subscription[] {
  const subs: Subscription[] = [{ driver: 'app', topics: [`${api}/garage/vehicles`, `${api}/agent/sessions/${sessionId}`] }];
  if (pilotTopic) subs.push({ driver: 'pilot', topics: [pilotTopic] });
  return subs;
}

/**
 * Resolve the owner's pilot topic. It is a keyed digest server-side (the hub takes anonymous
 * subscribers, so the topic is the only thing scoping one owner's intents to their own dashboards),
 * hence it can only be asked for, not derived. Null when unauthenticated or the endpoint is absent —
 * the dashboard then simply runs without the pilot channel.
 */
export async function fetchPilotTopic(): Promise<string | null> {
  try {
    const res = await authFetch(`${API}/agent/link`);
    if (!res.ok) return null;
    const body = (await res.json()) as { topic?: string };
    return body.topic ?? null;
  } catch {
    return null;
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
